<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Core\CognitionPaused;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Model\Procedure;
use NaviBrain\Model\ProcedureInvalidationClaim;
use NaviBrain\Model\ProcedureReplacementClaim;
use NaviBrain\Model\ProcedureRun;
use RuntimeException;

class ProcedureLifecycle extends Component
{
    public function beginProcedureInvalidation(int $procedureId, int $expectedMemoryId, ?int $actionId, string $reason): bool
    {
        $this->requireText($reason, 'procedure invalidation reason');
        $statementRecord = Procedure::getByWhere(['id' => $procedureId, 'memory_id' => $expectedMemoryId, 'status = \'active\'']);
        if ($statementRecord instanceof Procedure) {
            $statementRecord->setFields([ 'status' => 'retiring', 'invalidation_reason' => $reason, 'updated_at' => date('Y-m-d H:i:s') ]);
            $statementRecord->save();
        }
        if ($statementRecord instanceof Procedure) {

            $claimRecord = new ProcedureInvalidationClaim([ 'procedure_id' => $procedureId, 'memory_id' => $expectedMemoryId, 'action_id' => $actionId, 'reason' => $reason ], true, true);
            $claimRecord->save();
            return true;
        }

        $row = Procedure::getByWhere(['id' => $procedureId, 'memory_id' => $expectedMemoryId])?->getData();

        if (is_array($row) && in_array($row['status'], ['retiring', 'invalidated'], true)) {
            $claim = $this->procedureInvalidationClaim($procedureId, $expectedMemoryId);
            if ($claim !== null
                && $claim['action_id'] === $actionId
                && $claim['reason'] === $reason
                && (string) $row['invalidation_reason'] === $reason
            ) {
                return false;
            }
        }
        throw new RuntimeException('Procedure invalidation no longer owns the expected generation.');
    }

    public function procedureInvalidationClaim(int $procedureId, int $memoryId): ?array
    {

        $row = ProcedureInvalidationClaim::getByWhere(['procedure_id' => $procedureId, 'memory_id' => $memoryId])?->getData();

        if (!is_array($row)) {
            return null;
        }
        return [
            'action_id' => $row['action_id'] === null ? null : (int) $row['action_id'],
            'reason' => (string) $row['reason'],
        ];
    }

    public function beginProcedureReplacement(int $procedureId, int $previousMemoryId, string $shapeKey, int $generation, string $pendingHash): bool
    {
        if ($procedureId < 1 || $previousMemoryId < 1 || $generation < 1) {
            throw new InvalidArgumentException('Procedure replacement identity is invalid.');
        }
        $this->requireText($shapeKey, 'procedure replacement key');
        $this->requireText($pendingHash, 'procedure replacement hash');

        $retireRecord = Procedure::getByWhere(['id' => $procedureId, 'memory_id' => $previousMemoryId, 'status IN (\'active\',\'invalidated\')']);
        if ($retireRecord instanceof Procedure) {
            $retireRecord->setFields([ 'status' => 'retiring', 'updated_at' => date('Y-m-d H:i:s') ]);
            $retireRecord->save();
        }
        if ($retireRecord instanceof Procedure) {

            $claimRecord = new ProcedureReplacementClaim([
                'procedure_id' => $procedureId,
                'previous_memory_id' => $previousMemoryId,
                'shape_key' => $shapeKey,
                'generation' => $generation,
                'pending_hash' => $pendingHash,
                'next_memory_id' => null
            ], true, true);
            $claimRecord->save();
            return true;
        }

        $claim = $this->procedureReplacementClaim($procedureId, $previousMemoryId);
        if ($claim !== null
            && $claim['shape_key'] === $shapeKey
            && $claim['generation'] === $generation
            && hash_equals($claim['pending_hash'], $pendingHash)
        ) {
            $procedure = Procedure::getByID($procedureId);
            if ($procedure instanceof Procedure
                && ((string) $procedure->status === 'retiring'
                    || ($claim['next_memory_id'] !== null
                        && (string) $procedure->status === 'active'
                        && (int) $procedure->memory_id === $claim['next_memory_id']))
            ) {
                return false;
            }
        }
        throw new RuntimeException('Procedure replacement no longer owns the expected generation.');
    }

    public function procedureReplacementClaim(int $procedureId, int $previousMemoryId): ?array
    {

        $row = ProcedureReplacementClaim::getByWhere(['procedure_id' => $procedureId, 'previous_memory_id' => $previousMemoryId])?->getData();

        if (!is_array($row)) {
            return null;
        }
        return [
            'shape_key' => (string) $row['shape_key'],
            'generation' => (int) $row['generation'],
            'pending_hash' => (string) $row['pending_hash'],
            'next_memory_id' => $row['next_memory_id'] === null ? null : (int) $row['next_memory_id'],
        ];
    }

    public function finalizeProcedureReplacement(ProcedureReplacementClaim $replacement, array $fields): array
    {
        $nextMemoryId = (int) $replacement->next_memory_id;
        if ($nextMemoryId < 1) {
            throw new InvalidArgumentException('Replacement memory identity is invalid.');
        }
        $claim = ProcedureReplacementClaim::getByWhere([ 'procedure_id' => (int) $replacement->procedure_id, 'previous_memory_id' => (int) $replacement->previous_memory_id, ]);
        if (!$claim instanceof ProcedureReplacementClaim
            || $claim->shape_key !== $replacement->shape_key
            || (int) $claim->generation !== (int) $replacement->generation
            || !hash_equals((string) $claim->pending_hash, (string) $replacement->pending_hash)
            || ($claim->next_memory_id !== null && (int) $claim->next_memory_id !== $nextMemoryId)
        ) {
            throw new RuntimeException('Procedure replacement claim does not match its finalization.');
        }
        if ($claim->next_memory_id === null) {
            $claim->next_memory_id = $nextMemoryId;
            $claim->save();
        }
        $procedure = Procedure::getByID((int) $replacement->procedure_id);
        if (!$procedure instanceof Procedure) {
            throw new RuntimeException('Procedure replacement target disappeared.');
        }
        $applied = $procedure->status === 'retiring'
            && (int) $procedure->memory_id === (int) $replacement->previous_memory_id;
        if ($applied) {
            $fields['memory_id'] = $nextMemoryId;
            $fields['status'] = 'active';
            $procedure->setFields($fields);
            $procedure->save();
        } elseif ($procedure->status !== 'active' || (int) $procedure->memory_id !== $nextMemoryId) {
            throw new RuntimeException('Procedure replacement conflicts with durable procedure state.');
        }
        return ['procedure' => $procedure->getData(), 'applied' => $applied];
    }

    public function finalizeProcedureInvalidation(int $procedureId, int $expectedMemoryId, ?int $actionId, string $procedureKey, string $reason): bool
    {
        if ($procedureId < 1 || $expectedMemoryId < 1) {
            throw new InvalidArgumentException('Procedure invalidation identity is invalid.');
        }
        $this->requireText($procedureKey, 'procedure invalidation key');
        $this->requireText($reason, 'procedure invalidation reason');

        $claim = $this->procedureInvalidationClaim($procedureId, $expectedMemoryId);
        if ($claim === null
            || $claim['action_id'] !== $actionId
            || $claim['reason'] !== $reason
        ) {
            throw new RuntimeException('Procedure invalidation claim does not match its finalization.');
        }
        $now = date('Y-m-d H:i:s');

        $statementRecord = Procedure::getByWhere(['id' => $procedureId, 'memory_id' => $expectedMemoryId, 'status = \'retiring\'']);
        if (!$statementRecord instanceof Procedure) {
            return false;
        }
        $statementRecord->setFields([
            'status' => 'invalidated',
            'failure_count' => (int) $statementRecord->failure_count + 1,
            'invalidated_at' => $now,
            'invalidation_reason' => $reason,
            'updated_at' => $now
        ]);
        $statementRecord->save();

        $this->emitOnce(
            'procedure.invalidated:runtime:' . $procedureId . ':' .
                $expectedMemoryId . ':' . hash('sha256', $reason),
            'procedure.invalidated',
            [
                'procedure_id' => $procedureId,
                'memory_id' => $expectedMemoryId,
                'action_id' => $actionId,
                'procedure_key' => $procedureKey,
                'reason' => $reason,
            ]
        );
        return true;
    }

    public function startProcedureRun(Procedure $procedure, ProcedureRun $run): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', (string) $run->operation_key) !== 1) {
            throw new InvalidArgumentException('Procedure run operation key must be a SHA-256 value.');
        }
        $run->setFields(['procedure_id' => (int) $procedure->id, 'procedure_memory_id' => (int) $procedure->memory_id]);
        $existing = ProcedureRun::getByField('operation_key', $run->operation_key);
        $created = !$existing instanceof ProcedureRun;
        if ($existing instanceof ProcedureRun) {
            if ((int) $existing->procedure_id !== (int) $run->procedure_id
                || (int) $existing->procedure_memory_id !== (int) $run->procedure_memory_id
                || (int) $existing->intention_id !== (int) $run->intention_id
                || (array) $existing->arguments !== (array) $run->arguments
            ) {
                throw new RuntimeException('Procedure run operation key belongs to a different invocation.');
            }
            $run = $existing;
        } else {
            if (ExecutiveControl::status()['paused']) {
                throw new CognitionPaused('Cognition is paused; new procedure runs are not admitted.');
            }
            if ($procedure->status !== 'active') {
                throw new RuntimeException('Procedure generation changed before its run could start.');
            }
            $run->setFields([ 'current_step' => 0, 'results' => [], 'action_trace_ids' => [], 'status' => 'running', 'updated_at' => time(), ]);
            $run->save();
        }
        $event = $this->emitOnce('procedure.run.started:' . $run->operation_key, 'procedure.run.started', [
            'procedure_id' => (int) $procedure->id,
            'procedure_memory_id' => (int) $procedure->memory_id,
            'intention_id' => (int) $run->intention_id,
            'step_count' => count((array) $procedure->steps),
            'procedure_run_id' => (int) $run->id,
        ]);
        return ['run' => $run->getData(), 'event' => $event->getData(), 'created' => $created];
    }

    public function finalizeProcedureRun(ProcedureRun $run, string $status, array $results, array $actionTraceIds, ?string $reason = null): array
    {
        $this->requireChoice($status, ['succeeded', 'failed', 'cancelled'], 'procedure run status');
        if ($status !== 'succeeded') {
            $this->requireText((string) $reason, 'procedure run terminal reason');
        } elseif ($reason !== null) {
            throw new InvalidArgumentException('A successful procedure run cannot have an error reason.');
        }
        $actionTraceIds = array_values(array_unique(array_map('intval', $actionTraceIds)));
        $applied = in_array($run->status, ['running', 'waiting'], true);
        if ($applied) {
            $procedure = Procedure::getByID((int) $run->procedure_id);
            if ($status === 'succeeded' && (!$procedure instanceof Procedure
                || $procedure->status !== 'active'
                || (int) $procedure->memory_id !== (int) $run->procedure_memory_id)
            ) {
                throw new RuntimeException('Procedure generation changed before its run completed.');
            }
            $now = time();
            $run->setFields([ 'status' => $status, 'results' => $results, 'action_trace_ids' => $actionTraceIds, 'error' => $reason, 'completed_at' => $now, 'updated_at' => $now, ]);
            $run->save();
            if ($status === 'succeeded') {
                $procedure->setFields([ 'execution_count' => (int) $procedure->execution_count + 1, 'last_executed_at' => $now, 'updated_at' => $now, ]);
                $procedure->save();
            }
        } elseif ($run->status !== $status
            || (array) $run->results !== $results
            || array_values(array_map('intval', (array) $run->action_trace_ids)) !== $actionTraceIds
            || ($status === 'succeeded' ? $run->error !== null : (string) $run->error !== (string) $reason)
        ) {
            throw new RuntimeException('Procedure run already has a different terminal result.');
        }
        $payload = [
            'procedure_id' => (int) $run->procedure_id,
            'procedure_run_id' => (int) $run->id,
            'procedure_memory_id' => $run->procedure_memory_id,
            'status' => $status,
        ];
        if ($status === 'succeeded') {
            $payload['steps_completed'] = count($results);
            $payload['action_trace_ids'] = $actionTraceIds;
        } else {
            $payload['reason'] = $reason;
        }
        $event = $this->emitOnce('procedure.run.finished:' . $run->id, 'procedure.run.finished', $payload);
        return ['run' => $run->getData(), 'event' => $event->getData(), 'applied' => $applied];
    }

    public function resumeProcedureRunAfterAsync(ProcedureRun $run, array $results, array $completedResult): array
    {
        $stepIndex = (int) ($completedResult['step'] ?? -1);
        if ((int) $run->id < 1 || $stepIndex < 0) {
            throw new InvalidArgumentException('Asynchronous procedure result identity is invalid.');
        }
        $applied = $run->status === 'waiting' && (int) $run->current_step === $stepIndex;
        if ($applied) {
            $run->setFields([ 'current_step' => $stepIndex + 1, 'results' => $results, 'status' => 'running', 'updated_at' => time(), ]);
            $run->save();
        }
        if ((int) $run->current_step < $stepIndex + 1 || !in_array($completedResult, (array) $run->results, true)) {
            throw new RuntimeException('Asynchronous procedure result conflicts with durable run state.');
        }
        return ['run' => $run->getData(), 'applied' => $applied];
    }

    public function checkpointProcedureRunStep(ProcedureRun $run, array $results, array $actionTraceIds, array $completedResult): array
    {
        $stepIndex = (int) ($completedResult['step'] ?? -1);
        $actionTraceIds = array_values(array_unique(array_map('intval', $actionTraceIds)));
        $applied = $run->status === 'running' && (int) $run->current_step === $stepIndex;
        if ($applied) {
            $run->setFields([ 'current_step' => $stepIndex + 1, 'results' => $results, 'action_trace_ids' => $actionTraceIds, 'updated_at' => time(), ]);
            $run->save();
        }
        if ($stepIndex < 0 || (int) $run->current_step < $stepIndex + 1
            || !in_array($completedResult, (array) $run->results, true)
            || array_diff($actionTraceIds, (array) $run->action_trace_ids) !== []
        ) {
            throw new RuntimeException('Procedure step checkpoint conflicts with durable run state.');
        }
        return ['run' => $run->getData(), 'applied' => $applied];
    }
}
