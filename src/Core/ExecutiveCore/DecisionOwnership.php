<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Core\CognitionPaused;
use NaviBrain\Core\DecisionStateMachine;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\DecisionAsyncClaim;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\DecisionIntegrationClaim;
use NaviBrain\Model\DecisionPlanningClaim;
use NaviBrain\Model\DecisionRecoveryScan;
use NaviBrain\Model\Intention;
use NaviBrain\Model\WorkItem;
use RuntimeException;
use Throwable;

class DecisionOwnership extends Component
{
    public function beginDecisionPlanning(int $intentionId, ?int $threadId, string $trigger, ?string $modelHint, string $workingChecksum): DecisionCycle
    {
        $this->requireText($trigger, 'decision trigger');
        if (ExecutiveControl::status()['paused']) {
            throw new CognitionPaused('Cognition is paused; new decision cycles are not admitted.');
        }
        if ($this->requireIntention($intentionId)->status !== 'active') {
            throw new RuntimeException('A decision cycle requires an active intention.');
        }
        if ($threadId !== null && (int) $this->requireCognitiveThread($threadId)->parent_intention_id !== $intentionId) {
            throw new RuntimeException('Decision thread must belong to the selected intention.');
        }
        if (DecisionCycle::getByWhere(['intention_id' => $intentionId, "status IN ('running','waiting')"]) instanceof DecisionCycle) {
            throw new RuntimeException('Intention already has an active decision cycle.');
        }

        $cycle = new DecisionCycle([
            'intention_id' => $intentionId, 'thread_id' => $threadId,
            'trigger' => mb_substr($trigger, 0, 160), 'model_id' => $modelHint,
            'state' => 'observe', 'status' => 'running', 'working_memory_checksum' => $workingChecksum,
            'observations' => [], 'retrieval' => [], 'reasoning' => [], 'evaluation' => [],
            'selection' => [], 'execution' => [], 'verification' => [], 'adaptation' => [],
            'stage_timings' => [], 'updated_at' => time(),
        ], true, true);
        $cycle->save();

        $claimRecord = new DecisionPlanningClaim([ 'cycle_id' => (int) $cycle->id, 'owner' => $this->dispatchOwner(), 'claimed_at' => time(), 'settled' => 0 ], true, true);
        $claimRecord->save();
        $this->emit('decision_cycle.started', [
            'decision_cycle_id' => (int) $cycle->id, 'intention_id' => $intentionId,
            'thread_id' => $threadId, 'trigger' => $trigger,
            'working_memory_checksum' => $workingChecksum, 'model_hint' => $modelHint,
        ]);
        return $cycle;
    }

    public function finishDecisionPlanning(int $cycleId, ?string $error = null): ?array
    {
        $owner = $this->dispatchOwner();
        try {
            return $this->closeDecisionPlanning($cycleId, $owner, $error);
        } catch (Throwable $failure) {
            $this->rememberDecisionRelinquishment('planning', $cycleId, null, $owner, $error ?? 'Decision planning closure failed; start a fresh cycle.');
            throw $failure;
        }
    }

    public function decisionRecoveryPage(string $kind, int $limit): array
    {
        [$column, $model, $idField] = match ($kind) {
            'planning' => ['planning_after_id', DecisionPlanningClaim::class, 'cycle_id'],
            'integration' => ['integration_after_id', DecisionIntegrationClaim::class, 'cycle_id'],
            'action' => ['action_after_id', DecisionAsyncClaim::class, 'action_id'],
            'unattempted_action' => ['unattempted_action_after_id', DecisionAsyncClaim::class, 'action_id'],
            default => throw new InvalidArgumentException('Unknown decision recovery scan.'),
        };
        $scan = DecisionRecoveryScan::getByID(1);
        if (!$scan instanceof DecisionRecoveryScan) {
            throw new RuntimeException('Decision recovery cursor is missing; initialize the current schema.');
        }
        $after = (int) $scan->$column;
        $where = $model === DecisionAsyncClaim::class
            ? ["status IN ('started','held','waiting')"] : ['settled' => 0];
        $page = static fn (int $cursor): array => $model::getAllRecordsByWhere([...$where, $idField . ' > ' . $cursor], ['order' => [$idField => 'ASC'], 'limit' => $limit, 'calcFoundRows' => false]);
        $rows = $page($after);
        if ($rows === [] && $after > 0) {
            $rows = $page(0);
        }
        if ($model === DecisionAsyncClaim::class) {
            foreach ($rows as &$row) {
                $integration = DecisionIntegrationClaim::getByWhere([ 'cycle_id' => $row['decision_cycle_id'], 'owner' => $row['owner'], 'settled' => 1, ]);
                $cycle = DecisionCycle::getByID((int) $row['decision_cycle_id']);
                $row['integration_settled'] = $integration instanceof DecisionIntegrationClaim
                    && $cycle instanceof DecisionCycle
                    && (int) $integration->work_id === (int) $cycle->proposal_work_item_id ? 1 : 0;
            }
            unset($row);
        }
        $scan->$column = $rows === [] ? 0 : (int) $rows[array_key_last($rows)][$idField];
        $scan->save();
        return $rows;
    }

    public function recoverDecisionPlanning(int $limit): array
    {
        $rows = $this->decisionRecoveryPage('planning', $limit);
        $results = [];
        foreach ($rows as $row) {
            $cycleId = (int) $row['cycle_id'];
            $owner = (string) $row['owner'];
            $relinquished = $this->decisionRelinquishment('planning', $cycleId, null, $owner);
            if ($relinquished === null && $this->dispatchOwnerIsLive($owner)) {
                continue;
            }
            try {
                $result = $this->closeDecisionPlanning($cycleId, $owner, $relinquished ?? 'Decision planning owner exited before work publication; start a fresh cycle.', true);
                if (($result['status'] ?? null) !== 'owner_live_or_unprovable') {
                    $results[] = ['cycle_id' => $cycleId, 'phase' => 'planning',
                        'status' => $result === null ? 'ownership_settled' : 'failed_before_work'];
                }
            } catch (RuntimeException $error) {
                $results[] = ['cycle_id' => $cycleId, 'phase' => 'planning',
                    'status' => 'recovery_blocked', 'error' => $error->getMessage()];
            }
        }
        return $results;
    }

    public function closeDecisionPlanning(int $cycleId, string $owner, ?string $error, bool $requireDead = false): ?array
    {
        $claim = DecisionPlanningClaim::getByID($cycleId);

        if (!$claim instanceof DecisionPlanningClaim || !hash_equals((string) $claim->owner, $owner)) {
            throw new RuntimeException('Decision planning lost its exact process owner.');
        }
        if ((int) $claim->settled === 1) {
            unset(Executive::$relinquishedDecisionClaims[$this->decisionClaimKey('planning', $cycleId, null, $owner)]);
            return null;
        }
        if ($requireDead && $this->decisionRelinquishment('planning', $cycleId, null, $owner) === null
            && $this->dispatchOwnerIsLive($owner)) {
            return ['status' => 'owner_live_or_unprovable'];
        }
        $cycle = DecisionCycle::getByID($cycleId);
        if (!$cycle instanceof DecisionCycle) {
            throw new RuntimeException('Planning claim references a missing decision cycle.');
        }
        $result = null;
        if ($cycle->proposal_work_item_id !== null) {
            $work = WorkItem::getByID((int) $cycle->proposal_work_item_id);
            if (!$work instanceof WorkItem || $work->work_type !== DecisionStateMachine::WORK_TYPE
                || (int) $work->parent_intention_id !== (int) $cycle->intention_id
                || (int) (((array) $work->input_refs)['decision_cycle_id'] ?? 0) !== $cycleId) {
                throw new RuntimeException('Planning claim has a conflicting published work binding.');
            }
        } elseif (!in_array($cycle->status, ['completed', 'impasse', 'failed', 'cancelled'], true)) {
            if ($cycle->status !== 'running'
                || !in_array($cycle->state, ['observe', 'retrieve', 'reason'], true)
                || (int) (((array) $cycle->execution)['action_id'] ?? 0) !== 0) {
                throw new RuntimeException('Planning claim cannot settle an ambiguous decision cycle.');
            }
            $failedState = (string) $cycle->state;
            $error ??= 'Decision planning ended before work publication; start a fresh cycle.';
            $cycle->setFields(['state' => 'failed', 'status' => 'failed', 'error' => $error, 'completed_at' => time(), 'updated_at' => time()]);
            $cycle->save();
            $event = $this->emitOnce('decision-planning-failed:' . $cycleId, 'decision_cycle.failed', [
                'decision_cycle_id' => $cycleId, 'failed_state' => $failedState,
                'model_id' => $cycle->model_id, 'error' => $error,
            ]);
            $result = ['status' => 'failed', 'cycle' => $cycle->getData(), 'event' => $event->getData()];
        }

        $claim->settled = 1;
        $claim->save();
        unset(Executive::$relinquishedDecisionClaims[$this->decisionClaimKey('planning', $cycleId, null, $owner)]);
        return $result;
    }

    public function claimDecisionIntegration(int $cycleId, int $workId, ?string $model): bool
    {
        if ($cycleId < 1 || $workId < 1) {
            throw new RuntimeException('Decision integration requires valid IDs with positive identifiers.');
        }
        $claimRecord = DecisionCycle::getByWhere(['id' => $cycleId, 'proposal_work_item_id' => $workId, 'status = \'waiting\'', 'state = \'reason\'']);
        if (!$claimRecord instanceof DecisionCycle) {
            return false;
        }
        $claimRecord->setFields([ 'status' => 'running', 'model_id' => $model, 'updated_at' => date('Y-m-d H:i:s') ]);
        $claimRecord->save();

        $ownerRecord = new DecisionIntegrationClaim([ 'cycle_id' => $cycleId, 'work_id' => $workId, 'owner' => $this->dispatchOwner(), 'claimed_at' => time(), 'settled' => 0 ], true, true);
        $ownerRecord->save();
        return true;
    }

    public function finishDecisionIntegration(int $cycleId, int $workId, ?string $error = null): ?array
    {
        $owner = $this->dispatchOwner();
        try {
            return $this->closeDecisionIntegration($cycleId, $workId, $owner, $error);
        } catch (Throwable $failure) {
            $this->rememberDecisionRelinquishment('integration', $cycleId, $workId, $owner, $error ?? 'Decision integration closure failed; start a fresh cycle.');
            throw $failure;
        }
    }

    public function recoverDecisionIntegrations(int $limit = 16): array
    {
        $limit = max(1, min(128, $limit));
        $this->pruneDecisionRelinquishments($limit);
        $planningResults = $this->recoverDecisionPlanning($limit);
        $rows = $this->decisionRecoveryPage('integration', $limit);
        $recovered = $planningResults;
        foreach ($rows as $row) {
            $cycleId = (int) $row['cycle_id'];
            $owner = (string) $row['owner'];
            $relinquished = $this->decisionRelinquishment('integration', $cycleId, (int) $row['work_id'], $owner);
            if ($relinquished === null && $this->dispatchOwnerIsLive($owner)) {
                continue;
            }
            try {
                $result = $this->closeDecisionIntegration($cycleId, (int) $row['work_id'], $owner, $relinquished ?? 'Decision result owner exited before integration settled; start a fresh cycle.', true);
            } catch (RuntimeException $error) {
                $recovered[] = ['cycle_id' => $cycleId, 'status' => 'recovery_blocked', 'error' => $error->getMessage()];
                continue;
            }
            if (($result['status'] ?? null) === 'owner_live_or_unprovable') {
                continue;
            }
            $recovered[] = [
                'cycle_id' => $cycleId,
                'status' => $result === null ? 'ownership_settled' : 'failed_before_dispatch',
            ];
        }
        return $recovered;
    }

    public function closeDecisionIntegration(int $cycleId, int $workId, string $owner, ?string $error, bool $requireDead = false): ?array
    {
        $claim = DecisionIntegrationClaim::getByID($cycleId);

        if (!$claim instanceof DecisionIntegrationClaim || (int) $claim->work_id !== $workId
            || !hash_equals((string) $claim->owner, $owner)) {
            throw new RuntimeException('Decision integration lost its exact result owner.');
        }
        if ((int) $claim->settled === 1) {
            unset(Executive::$relinquishedDecisionClaims[$this->decisionClaimKey('integration', $cycleId, $workId, $owner)]);
            return null;
        }
        if ($requireDead && $this->decisionRelinquishment('integration', $cycleId, $workId, $owner) === null
            && $this->dispatchOwnerIsLive($owner)) {
            return ['status' => 'owner_live_or_unprovable'];
        }
        $cycle = DecisionCycle::getByID($cycleId);
        if (!$cycle instanceof DecisionCycle || (int) $cycle->proposal_work_item_id !== $workId) {
            throw new RuntimeException('Decision integration claim has a conflicting cycle/work identity.');
        }
        $result = null;
        if (!in_array($cycle->status, ['completed', 'impasse', 'failed', 'cancelled'], true)) {
            $binding = DecisionAsyncClaim::getByWhere(['decision_cycle_id' => $cycleId]);
            $bound = $binding instanceof DecisionAsyncClaim ? [
                'action_id' => $binding->action_id,
                'action_trace_id' => ActionExecution::getByField('action_trace_id', (int) $binding->action_id)?->action_trace_id,
            ] : false;
            $actionId = (int) (((array) $cycle->execution)['action_id'] ?? 0);
            if (is_array($bound)) {
                if ($actionId < 1 || (int) $bound['action_id'] !== $actionId
                    || (int) ($bound['action_trace_id'] ?? 0) !== $actionId) {
                    throw new RuntimeException('Decision integration has an inconsistent action binding.');
                }
            } else {
                if ($cycle->status !== 'running' || $actionId !== 0) {
                    throw new RuntimeException('Decision integration cannot settle an ambiguous unbound cycle.');
                }
                $failedState = (string) $cycle->state;
                $error ??= 'Decision integration returned without a terminal outcome or bound action; start a fresh cycle.';
                $cycle->setFields([ 'state' => 'failed', 'status' => 'failed', 'error' => $error, 'completed_at' => time(), 'updated_at' => time(), ]);
                $cycle->save();
                $event = $this->emitOnce('decision-integration-failed:' . $cycleId, 'decision_cycle.failed', [
                    'decision_cycle_id' => $cycleId, 'failed_state' => $failedState,
                    'model_id' => $cycle->model_id, 'error' => $error,
                ]);
                $result = ['status' => 'failed', 'cycle' => $cycle->getData(), 'event' => $event->getData()];
            }
        }

        $claim->settled = 1;
        $claim->save();
        unset(Executive::$relinquishedDecisionClaims[$this->decisionClaimKey('integration', $cycleId, $workId, $owner)]);
        return $result;
    }

    public function decisionClaimKey(string $phase, int $cycleId, ?int $workId, string $owner): string
    {
        return $owner . ':' . $phase . ':' . $cycleId . ':' . ($workId ?? 0);
    }

    public function recoverLocallyRelinquishedDecisions(): void
    {
        if (Executive::$relinquishedDecisionClaims === []) {
            return;
        }
        $this->dispatchOwner();
        if (Executive::$relinquishedDecisionClaims === [] || Executive::$drainingDecisionRelinquishments
           ) {
            return;
        }
        Executive::$drainingDecisionRelinquishments = true;
        try {
            $this->recoverDecisionIntegrations(16);
        } finally {
            Executive::$drainingDecisionRelinquishments = false;
        }
    }

    public function rememberDecisionRelinquishment(string $phase, int $cycleId, ?int $workId, string $owner, string $reason): void
    {
        Executive::$relinquishedDecisionClaims[$this->decisionClaimKey($phase, $cycleId, $workId, $owner)] = [
            'phase' => $phase, 'cycle_id' => $cycleId, 'work_id' => $workId, 'owner' => $owner,
            'reason' => mb_strcut($reason, 0, 512, 'UTF-8'),
        ];
    }

    public function decisionRelinquishment(string $phase, int $cycleId, ?int $workId, string $owner): ?string
    {
        $key = $this->decisionClaimKey($phase, $cycleId, $workId, $owner);
        if (!isset(Executive::$relinquishedDecisionClaims[$key]) || $owner !== $this->dispatchOwner()) {
            return null;
        }
        return Executive::$relinquishedDecisionClaims[$key]['reason'] ?? null;
    }

    public function pruneDecisionRelinquishments(int $limit): void
    {
        if (Executive::$relinquishedDecisionClaims === []) {
            return;
        }
        $owner = $this->dispatchOwner();
        foreach (array_slice(Executive::$relinquishedDecisionClaims, 0, $limit, true) as $key => $entry) {
            if ($entry['owner'] !== $owner) {
                unset(Executive::$relinquishedDecisionClaims[$key]);
                continue;
            }
            $integration = $entry['phase'] === 'integration';
            $model = $integration ? DecisionIntegrationClaim::class : DecisionPlanningClaim::class;
            $record = $model::getByWhere(['cycle_id' => $entry['cycle_id']]);
            $claim = $record ? $record->getData() : false;
            unset(Executive::$relinquishedDecisionClaims[$key]);
            if (is_array($claim) && (int) $claim['settled'] === 0 && hash_equals((string) $claim['owner'], $owner)
                && (!$integration || (int) $claim['work_id'] === $entry['work_id'])) {
                Executive::$relinquishedDecisionClaims[$key] = $entry;
            }
        }
    }

    public function dispatchOwner(): string
    {
        $pid = getmypid();
        if (!is_int($pid) || $pid < 1) {
            throw new RuntimeException('Cannot establish a process id for action dispatch.');
        }
        if (Executive::$dispatchOwner !== null && !str_starts_with(Executive::$dispatchOwner, $pid . ':')) {

            Executive::$dispatchOwner = null;
            Executive::$relinquishedDecisionClaims = [];
            Executive::$drainingDecisionRelinquishments = false;
        }
        if (Executive::$dispatchOwner === null) {
            $start = $this->processStartTicks($pid);
            if ($start === null || $start === '') {
                throw new RuntimeException('Cannot establish a process identity for action dispatch.');
            }
            Executive::$dispatchOwner = $pid . ':' . $start . ':' . bin2hex(random_bytes(16));
        }
        return Executive::$dispatchOwner;
    }

    public function dispatchOwnerIsLive(string $owner): bool
    {
        $parts = explode(':', $owner, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {

            return true;
        }
        $pid = (int) $parts[0];
        $start = $this->processStartTicks($pid);
        return $start === null || hash_equals($parts[1], $start);
    }

    public function processStartTicks(int $pid): ?string
    {
        if ($pid < 1 || !is_dir('/proc')) {
            return null;
        }
        $path = '/proc/' . $pid . '/stat';
        $stat = @file_get_contents($path);
        if ($stat === false) {
            return is_dir('/proc/' . $pid) ? null : '';
        }
        $close = strrpos($stat, ')');
        if ($close === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];

        return isset($fields[19]) && ctype_digit($fields[19]) ? $fields[19] : null;
    }
}
