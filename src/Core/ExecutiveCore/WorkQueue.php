<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use Divergence\IO\Database\SQLite;
use InvalidArgumentException;
use NaviBrain\Core\WorkClaim;
use NaviBrain\Core\BackgroundStateCompiler;
use NaviBrain\Core\DecisionPreparationChanged;
use NaviBrain\Core\DecisionStateMachine;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Core\NarrativeSynthesis;
use NaviBrain\Core\PublicReflection;
use NaviBrain\Model\CycleRun;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\DecisionPlanningClaim;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Model\WorkItem;
use NaviBrain\Model\WorkItemLane;
use NaviBrain\Support\PlainText;
use RuntimeException;

class WorkQueue extends Component
{
    public function enqueueWork(WorkItem $work): array
    {
        if (!$work->validate()) {
            throw new InvalidArgumentException(implode('; ', $work->validationErrors));
        }
        $inputRefs = (array) $work->input_refs;
        $prompt = (string) $work->prompt;
        if ($work->parent_run_id !== null && !CycleRun::getByID($work->parent_run_id) instanceof CycleRun) {
            throw new RuntimeException(sprintf('Cycle run %d does not exist.', $work->parent_run_id));
        }
        if ($work->parent_intention_id !== null) {
            $this->requireIntention($work->parent_intention_id);
        }
        $existing = WorkItem::getByField('idempotency_key', $work->idempotency_key);
        if ($existing instanceof WorkItem) {
            return ['work_item' => $existing->getData(), 'deduplicated' => true];
        }

        $evidenceFenced = in_array($work->work_type, [
            Executive::MEMORY_CONSOLIDATION_WORK_TYPE,
            Executive::COMPLETION_WORK_TYPE,
            NarrativeSynthesis::PERSONALITY_WORK_TYPE,
            NarrativeSynthesis::MOTIVATION_WORK_TYPE,
            NarrativeSynthesis::INTENTION_WORK_TYPE,
            PublicReflection::WORK_TYPE,
        ], true);
        $memoryObservations = [];
        $workingContext = $evidenceFenced
            ? []
            : $this->executive->workingMemory->contextForWork($work->parent_intention_id, $inputRefs, $memoryObservations);
        $decisionInputs = null;
        if ($work->work_type === DecisionStateMachine::WORK_TYPE) {
            try {
                $decisionInputs = $this->executive->decisionStateMachine->prepareWorkspaceDependencies((int) ($inputRefs['decision_cycle_id'] ?? 0), $workingContext, $memoryObservations);
            } catch (DecisionPreparationChanged $changed) {

                $existing = WorkItem::getByField('idempotency_key', $work->idempotency_key);
                if (!$existing instanceof WorkItem) {
                    throw $changed;
                }
                return ['work_item' => $existing->getData(), 'deduplicated' => true];
            }
        }
        if ($workingContext !== []) {
            $workingCanonical = json_encode($workingContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $prompt .= "\n\nActive working memory (bounded typed state; claims retain their labels and provenance):\n"
                . PlainText::render($workingContext, 12000, 20);
            $inputRefs['working_memory_checksum'] = hash('sha256', $workingCanonical);
            $inputRefs['working_memory_roles'] = array_values(array_map( static fn (array $slot): string => (string) ($slot['slot_role'] ?? ''), $workingContext ));
        }

        if (!$evidenceFenced) {
            $backgroundCompiler = new BackgroundStateCompiler($this->executive);
            $backgroundState = $backgroundCompiler->compile($prompt);
            $backgroundCanonical = json_encode($backgroundState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $prompt .= "\n\nLive background cognition state:\n"
                . $backgroundCompiler->render($backgroundState);
            $inputRefs['background_state_protocol'] = BackgroundStateCompiler::PROTOCOL;
            $inputRefs['background_state_checksum'] = hash('sha256', $backgroundCanonical);
            $inputRefs['background_state_captured_at'] = $backgroundState['captured_at'];
            $inputRefs['background_state_sections'] = [
                'sensory_state',
                'motivational_state',
                'needs',
                'emotional_state',
            ];
        }

        if ($work->work_type !== PublicReflection::WORK_TYPE
            && !($work->work_type === Executive::MEMORY_CONSOLIDATION_WORK_TYPE
                && in_array($inputRefs['dream_protocol'] ?? null, ['dream-consolidation-v1', Executive::DREAM_REFERENCE_PROTOCOL], true))) {
            $prompt = PlainText::sanitize($prompt);
        }

        $existing = WorkItem::getByField('idempotency_key', $work->idempotency_key);
        if ($existing instanceof WorkItem) {
            return ['work_item' => $existing->getData(), 'deduplicated' => true];
        }

        $decisionCycle = null;
        if ($decisionInputs !== null) {

            $planningClaim = DecisionPlanningClaim::getByWhere(['cycle_id' => (int) $decisionInputs['cycle_id']])?->getData();

            if (!is_array($planningClaim) || (int) $planningClaim['settled'] !== 0
                || !hash_equals((string) $planningClaim['owner'], $this->dispatchOwner())) {
                throw new RuntimeException('Decision work publication requires its active planning owner.');
            }
            $decisionCycle = DecisionCycle::getByID((int) $decisionInputs['cycle_id']);
            if (!$decisionCycle instanceof DecisionCycle
                || $decisionCycle->status !== 'running' || $decisionCycle->state !== 'reason'
                || $decisionCycle->proposal_work_item_id !== null
                || (int) $decisionCycle->intention_id !== $work->parent_intention_id
                || (int) $decisionCycle->intention_id !== $decisionInputs['intention_id']
                || ($decisionCycle->thread_id === null ? null : (int) $decisionCycle->thread_id)
                    !== $decisionInputs['thread_id']
                || !hash_equals($decisionInputs['original_observations_hash'], hash('sha256', serialize((array) $decisionCycle->observations)))
            ) {
                throw new RuntimeException('Decision inputs changed before work publication; start a fresh cycle.');
            }
        }

        if (NarrativeSynthesis::isWorkType($work->work_type)
            && is_string($inputRefs['synthesis_checksum'] ?? null)) {
            $latest = WorkItem::getByWhere(['work_type' => $work->work_type], ['order' => ['id' => 'DESC']]);
            if ($latest instanceof WorkItem
                && in_array($latest->status, ['queued', 'leased', 'completed'], true)
                && ($latest->input_refs['synthesis_checksum'] ?? null) === $inputRefs['synthesis_checksum']) {
                return ['work_item' => $latest->getData(), 'deduplicated' => true];
            }
        }

        $work->setFields(['prompt' => $prompt, 'input_refs' => $inputRefs, 'updated_at' => time()]);
        $work->save();
        if ($work->work_type === Executive::MEMORY_CONSOLIDATION_WORK_TYPE
            && in_array($inputRefs['dream_protocol'] ?? null, ['dream-consolidation-v1', Executive::DREAM_REFERENCE_PROTOCOL], true)) {
            if ($work->parent_run_id !== null || $work->parent_intention_id !== null || $work->allowed_actions !== []) {
                throw new InvalidArgumentException('Dream consolidation cannot carry action or parent-cycle authority.');
            }

            $laneRecord = new WorkItemLane([ 'work_id' => (int) $work->id, 'lane' => 'opencode-dream' ], true, true);
            $laneRecord->save();
        }
        $event = $this->emit('work.queued', [
            'work_item_id' => $work->id,
            'parent_run_id' => $work->parent_run_id,
            'parent_intention_id' => $work->parent_intention_id,
            'work_type' => $work->work_type,
            'allowed_actions' => $work->allowed_actions,
            'token_budget' => $work->token_budget,
            'wall_budget_seconds' => $work->wall_budget_seconds,
            'depth' => $work->depth,
            'max_depth' => $work->max_depth,
        ]);

        if ($decisionCycle instanceof DecisionCycle) {
            $decisionCycle->setFields([ 'observations' => $decisionInputs['observations'], 'proposal_work_item_id' => (int) $work->id, 'status' => 'waiting', 'updated_at' => time(), ]);
            $decisionCycle->save();

            $this->closeDecisionPlanning((int) $decisionCycle->id, $this->dispatchOwner(), null);
            $this->emit('decision_cycle.waiting', [ 'decision_cycle_id' => (int) $decisionCycle->id, 'state' => 'reason', 'work_item_id' => (int) $work->id, ]);
        }

        return ['work_item' => $work->getData(), 'event' => $event->getData(), 'deduplicated' => false];
    }

    public function claimWork(WorkClaim $claim): ?array
    {
        $this->recoverLocallyRelinquishedDecisions();
        $claim->validate();
        $now = time();
        $this->recoverExpiredWorkLeases($now, $claim->workId);
        if (ExecutiveControl::status()['paused']) {
            return null;
        }

        $eligible = "status = 'queued' AND " . ($claim->lane === 'general'
            ? 'NOT EXISTS (SELECT 1 FROM work_item_lanes lane WHERE lane.work_id = work_items.id)'
            : "EXISTS (SELECT 1 FROM work_item_lanes lane WHERE lane.work_id = work_items.id AND lane.lane = 'opencode-dream')");
        if ($claim->workId !== null) {
            $eligible .= ' AND id = ' . $claim->workId;
        }
        if ($claim->excludedWorkTypes !== []) {
            $eligible .= ' AND work_type NOT IN (' . implode(', ', array_map(SQLite::quote(...), $claim->excludedWorkTypes)) . ')';
        }
        if ($claim->includedWorkTypes !== []) {
            $eligible .= ' AND work_type IN (' . implode(', ', array_map(SQLite::quote(...), $claim->includedWorkTypes)) . ')';
        }
        $work = WorkItem::getByQuery(
            'SELECT * FROM work_items WHERE ' . $eligible . "
             ORDER BY CASE work_type WHEN 'self_presence_answer' THEN 0 ELSE 1 END,
                      created_at ASC LIMIT 1"
        );
        if (!$work instanceof WorkItem) {
            return null;
        }
        $work->setFields([
            'status' => 'leased', 'lease_owner' => $claim->owner,
            'lease_expires_at' => $now + $claim->leaseSeconds,
            'fencing_token' => (int) $work->fencing_token + 1,
            'attempts' => (int) $work->attempts + 1, 'updated_at' => $now, 'error' => null,
        ]);
        $work->save();
        $this->extendParentRhythmLease($work, $now + $claim->leaseSeconds + 30);
        $event = $this->emit('work.claimed', [
            'work_item_id' => $work->id,
            'owner' => $claim->owner,
            'fencing_token' => $work->fencing_token,
            'lease_expires_at' => $work->lease_expires_at,
            'attempt' => $work->attempts,
        ]);

        return ['work_item' => $work->getData(), 'event' => $event->getData()];
    }

    public function deferWork(int $workId, string $owner, int $fencingToken, int $retryAt): void
    {
        $work = WorkItem::getByID($workId);
        if (!$work instanceof WorkItem || $work->status !== 'leased'
            || $work->lease_owner !== $owner || (int) $work->fencing_token !== $fencingToken
            || ($this->timestamp($work->lease_expires_at) ?? 0) < time()) {
            throw new RuntimeException('Worker lease is stale; deferral was rejected by the fencing check.');
        }

        $retryAt = max(time() + 1, $retryAt);
        $work->setFields(['lease_expires_at' => $retryAt, 'updated_at' => time()]);
        $work->save();
        $this->extendParentRhythmLease($work, $retryAt + 30);
        $this->emit('work.rate_limited', ['work_item_id' => $workId, 'retry_at' => $retryAt]);
    }

    public function listWorkItems(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['queued', 'leased', 'completed', 'failed', 'cancelled'], 'status');
            return $this->records(WorkItem::getAllByWhere( ['status' => $status], ['order' => ['created_at' => 'ASC']] ));
        }
        return $this->records(WorkItem::getAll(['order' => ['created_at' => 'ASC']]));
    }

    public function listThoughtArtifacts(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['proposed', 'accepted', 'rejected', 'expired'], 'status');
            return $this->records(ThoughtArtifact::getAllByWhere( ['status' => $status], ['order' => ['created_at' => 'DESC']] ));
        }
        return $this->records(ThoughtArtifact::getAll(['order' => ['created_at' => 'DESC']]));
    }
}
