<?php

declare(strict_types=1);

namespace NaviBrain\Core\ProceduralMemory;

use InvalidArgumentException;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\Procedure;
use NaviBrain\Model\ProcedureRun;
use NaviBrain\Storage\TokenMemoryDaemon;
use RuntimeException;
use NaviBrain\Core\CognitionPaused;
use NaviBrain\Core\ExecutiveControl;

class Runs extends Component
{
    /** @return array<string, mixed> */
    public function run(int $procedureId, int $intentionId, array $arguments, string $invocationKey): array {
        $invocationKey = trim($invocationKey);
        if ($invocationKey === '') {
            throw new InvalidArgumentException('Procedure run requires a caller-stable invocation key.');
        }
        $operationKey = TokenMemoryDaemon::operationKey('procedure-run', $invocationKey);
        $existingRun = ProcedureRun::getByField('operation_key', $operationKey);
        if ($existingRun instanceof ProcedureRun) {
            if ((int) $existingRun->procedure_id !== $procedureId
                || (int) $existingRun->intention_id !== $intentionId
                || (array) $existingRun->arguments !== $arguments
            ) {
                throw new RuntimeException('Procedure run invocation key belongs to a different request.');
            }
            return $this->advanceRun($existingRun);
        }
        $procedure = $this->requireProcedure($procedureId);
        if ($procedure->status === 'retiring') {
            $this->completeRetiringProcedure($procedure);
            throw new RuntimeException('Procedure generation is retiring and cannot start a new run.');
        }
        $procedureMemory = Memory::getByID((int) $procedure->memory_id);
        if ($procedure->status === 'active') {

            $this->validateComposition($procedure);
        }
        if ($procedure->status !== 'active'
            || !$procedureMemory instanceof Memory
            || $procedureMemory->status !== 'active'
        ) {
            throw new RuntimeException('A procedure requires an active token-memory generation before it can run.');
        }
        $intention = Intention::getByID($intentionId);
        if (!$intention instanceof Intention || $intention->status !== 'active') {
            throw new RuntimeException('Procedures can only run for an active intention.');
        }
        $this->validateRuntimeInput($procedure, $arguments);

        $started = $this->core->startProcedureRun($procedure, new ProcedureRun([ 'intention_id' => $intentionId, 'arguments' => $arguments, 'operation_key' => $operationKey, ], true, true));
        $run = ProcedureRun::getByID((int) ($started['run']['id'] ?? 0));
        if (!$run instanceof ProcedureRun) {
            throw new RuntimeException('Started procedure run disappeared.');
        }
        return $this->advanceRun($run);
    }

    /** @return array<string, mixed> */
    public function advanceRun(ProcedureRun $run): array {
        if (in_array($run->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return $this->replayTerminalRun($run);
        }
        $procedure = $this->activeRunProcedure($run, true);
        if (!$procedure instanceof Procedure) {
            return $this->cancelStaleRun($run);
        }
        try {
            $this->validateComposition($procedure);
        } catch (RuntimeException) {
            return $this->cancelStaleRun($run);
        }
        if ($run->status === 'waiting') {
            $reconciled = $this->reconcileWaitingRun($run);
            if (is_array($reconciled)) {
                return $reconciled;
            }
            $run = $reconciled;
        }
        $steps = is_array($procedure->steps) ? $procedure->steps : [];
        $results = is_array($run->results) ? $run->results : [];
        $actionIds = is_array($run->action_trace_ids) ? array_map('intval', $run->action_trace_ids) : [];

        while ((int) $run->current_step < count($steps)) {
            if (ExecutiveControl::status()['paused']) {
                return $this->heldRun($run, $procedure);
            }
            $currentProcedure = $this->activeRunProcedure($run, false);
            if (!$currentProcedure instanceof Procedure) {
                return $this->cancelStaleRun($run);
            }
            $procedure = $currentProcedure;
            try {
                $this->validateComposition($procedure);
            } catch (RuntimeException) {
                return $this->cancelStaleRun($run);
            }
            $steps = is_array($procedure->steps) ? $procedure->steps : [];
            $index = (int) $run->current_step;
            $step = $steps[$index] ?? null;
            if (!is_array($step)) {
                return $this->failRun($run, $results, 'Procedure contains a malformed step.');
            }
            $sourceProcedureId = isset($step['source_procedure_id']) && $step['source_procedure_id'] !== null
                ? (int) $step['source_procedure_id']
                : (int) $procedure->id;
            $sourceProcedureMemoryId = isset($step['source_procedure_memory_id'])
                && $step['source_procedure_memory_id'] !== null
                ? (int) $step['source_procedure_memory_id']
                : (int) $run->procedure_memory_id;
            $defaults = is_array($step['arguments'] ?? null) ? $step['arguments'] : [];
            $runtime = is_array($run->arguments) ? $run->arguments : [];

            $arguments = array_replace($defaults, array_intersect_key($runtime, $defaults));
            try {
                $outcome = $this->executeAction(new ActionTrace([
                    'intention_id' => (int) $run->intention_id,
                    'description' => (string) ($step['description'] ?? $procedure->description),
                    'expected' => (string) ($step['expected'] ?? 'Installed postcondition verifies.'),
                ], true, true), new ActionExecution([
                    'action_kind' => (string) ($step['action_kind'] ?? ''),
                    'arguments' => $arguments,
                    'procedure_id' => $sourceProcedureId,
                    'procedure_memory_id' => $sourceProcedureMemoryId,
                    'procedure_run_id' => (int) $run->id,
                    'step_index' => $index + 1,
                    'verifier' => is_array($step['verifier'] ?? null) ? $step['verifier'] : [],
                ], true, true));
            } catch (CognitionPaused) {

                $durableRun = ProcedureRun::getByID((int) $run->id);
                if (!$durableRun instanceof ProcedureRun) {
                    throw new RuntimeException('Paused procedure run disappeared.');
                }
                if (in_array($durableRun->status, ['succeeded', 'failed', 'cancelled'], true)) {
                    return $this->replayTerminalRun($durableRun);
                }
                return $this->heldRun($durableRun, $procedure);
            }
            $actionId = (int) ($outcome['started']['action']['id'] ?? 0);
            if ($actionId > 0) {
                $actionIds[] = $actionId;
            }
            $run->setField('action_trace_ids', array_values(array_unique($actionIds)));

            if (in_array($outcome['status'] ?? null, ['in_progress', 'held'], true)) {
                return [
                    'status' => $outcome['status'],
                    'procedure' => $procedure->getData(),
                    'run' => $run->getData(),
                    'step' => $outcome,
                ];
            }
            if (($outcome['status'] ?? null) === 'waiting') {
                if (($outcome['dispatch']['wait_committed'] ?? false) !== true) {
                    throw new RuntimeException('Procedure adapter returned before committing its wait state.');
                }
                $durableRun = ProcedureRun::getByID((int) $run->id);
                if (!$durableRun instanceof ProcedureRun) {
                    throw new RuntimeException('Waiting procedure run disappeared.');
                }
                if (in_array($durableRun->status, ['succeeded', 'failed', 'cancelled'], true)) {
                    return $this->replayTerminalRun($durableRun);
                }
                if ((string) $durableRun->status === 'waiting') {
                    $reconciled = $this->reconcileWaitingRun($durableRun);
                    if (is_array($reconciled)) {
                        $reconciled['step'] = $outcome;
                        return $reconciled;
                    }
                    $durableRun = $reconciled;
                }
                if ((string) $durableRun->status !== 'running'
                    || (int) $durableRun->current_step <= $index
                ) {
                    throw new RuntimeException('Asynchronous procedure wait conflicts with durable run state.');
                }
                $run = $durableRun;
                $results = is_array($run->results) ? $run->results : [];
                $actionIds = is_array($run->action_trace_ids)
                    ? array_values(array_map('intval', $run->action_trace_ids))
                    : [];
                continue;
            }
            $durableRun = ProcedureRun::getByID((int) $run->id);
            if (!$durableRun instanceof ProcedureRun) {
                throw new RuntimeException('Procedure run disappeared after action completion.');
            }
            if (in_array($durableRun->status, ['succeeded', 'failed', 'cancelled'], true)) {
                return $this->replayTerminalRun($durableRun);
            }
            if ((string) $durableRun->status === 'waiting') {
                $reconciled = $this->reconcileWaitingRun($durableRun);
                if (is_array($reconciled)) {
                    return $reconciled;
                }
                $run = $reconciled;
                $results = is_array($run->results) ? $run->results : [];
                $actionIds = is_array($run->action_trace_ids)
                    ? array_values(array_map('intval', $run->action_trace_ids))
                    : [];
                continue;
            }
            if ((int) $durableRun->current_step > $index) {
                $run = $durableRun;
                $results = is_array($run->results) ? $run->results : [];
                $actionIds = is_array($run->action_trace_ids)
                    ? array_values(array_map('intval', $run->action_trace_ids))
                    : [];
                continue;
            }
            $completedResult = [
                'step' => $index,
                'action_id' => $actionId,
                'status' => $outcome['status'] ?? 'failed',
            ];
            $results[] = $completedResult;
            if (($outcome['status'] ?? null) !== 'succeeded') {
                return $this->failRun($run, $results, sprintf('Procedure step %d failed.', $index));
            }
            $this->core->checkpointProcedureRunStep($run, $results, $actionIds, $completedResult);
            $run = ProcedureRun::getByID((int) $run->id);
            if (!$run instanceof ProcedureRun) {
                throw new RuntimeException('Checkpointed procedure run disappeared.');
            }
            $results = is_array($run->results) ? $run->results : [];
            $actionIds = is_array($run->action_trace_ids)
                ? array_values(array_map('intval', $run->action_trace_ids))
                : [];
        }

        $procedure = $this->activeRunProcedure($run, false);
        if (!$procedure instanceof Procedure) {
            return $this->cancelStaleRun($run);
        }
        try {
            $terminal = $this->core->finalizeProcedureRun($run, 'succeeded', $results, $actionIds);
        } catch (RuntimeException $throwable) {
            $durableRun = ProcedureRun::getByID((int) $run->id);
            if ($durableRun instanceof ProcedureRun
                && !in_array($durableRun->status, ['succeeded', 'failed', 'cancelled'], true)
                && !$this->activeRunProcedure($durableRun, false) instanceof Procedure
            ) {
                return $this->cancelStaleRun($durableRun);
            }
            throw $throwable;
        }
        return [
            'status' => 'succeeded',
            'procedure' => $procedure->getData(),
            'run' => $terminal['run'],
            'event' => $terminal['event'],
        ];
    }

    /** @return array<string, mixed> */
    public function heldRun(ProcedureRun $run, Procedure $procedure): array {
        return [
            'status' => 'held',
            'reason' => 'cognition_paused',
            'procedure' => $procedure->getData(),
            'run' => $run->getData(),
        ];
    }

    /** @return ProcedureRun|array<string, mixed> */
    public function reconcileWaitingRun(ProcedureRun $run): ProcedureRun|array {
        $stepIndex = (int) $run->current_step;
        $executions = ActionExecution::getAllByWhere([ 'procedure_run_id' => (int) $run->id, 'step_index' => $stepIndex + 1, ]);
        if (count($executions) !== 1) {
            throw new RuntimeException('Waiting procedure run has no unique current action.');
        }
        $execution = $executions[0];
        if (!in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return [
                'status' => $execution->status === 'dispatching' ? 'in_progress' : 'waiting',
                'procedure' => $this->procedureSnapshotForRun($run),
                'run' => $run->getData(),
                'replayed' => true,
            ];
        }

        $this->finishDurableExecution((int) $execution->action_trace_id);
        $result = [
            'step' => $stepIndex,
            'action_id' => (int) $execution->action_trace_id,
            'status' => (string) $execution->status,
        ];
        $results = is_array($run->results) ? $run->results : [];
        $matching = null;
        foreach ($results as $candidate) {
            if (is_array($candidate)
                && (int) ($candidate['step'] ?? -1) === $stepIndex
                && (int) ($candidate['action_id'] ?? 0) === (int) $execution->action_trace_id
            ) {
                $matching = $candidate;
                break;
            }
        }
        if ($matching !== null && $matching !== $result) {
            throw new RuntimeException('Waiting procedure result conflicts with its durable action.');
        }
        if ($matching === null) {
            $results[] = $result;
        }
        if ($execution->status !== 'succeeded' || (int) $execution->verified !== 1) {
            return $this->failRun($run, $results, 'Asynchronous step postcondition failed.');
        }
        $this->core->resumeProcedureRunAfterAsync($run, $results, $result);
        $resumed = ProcedureRun::getByID((int) $run->id);
        if (!$resumed instanceof ProcedureRun) {
            throw new RuntimeException('Reconciled procedure run disappeared.');
        }
        return $resumed;
    }

    /** @return array<string, mixed> */
    public function replayTerminalRun(ProcedureRun $run): array {
        $status = (string) $run->status;
        $terminal = $this->core->finalizeProcedureRun($run, $status, is_array($run->results) ? $run->results : [], is_array($run->action_trace_ids) ? array_map('intval', $run->action_trace_ids) : [], $status === 'succeeded' ? null : (string) $run->error);
        return [
            'status' => $status,
            'procedure' => $this->procedureSnapshotForRun($run),
            'run' => $terminal['run'],
            'event' => $terminal['event'],
            'replayed' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function failRun(ProcedureRun $run, array $results, string $reason): array {

        $terminal = $this->core->finalizeProcedureRun($run, 'failed', $results, is_array($run->action_trace_ids) ? array_map('intval', $run->action_trace_ids) : [], $reason);
        return [
            'status' => 'failed',
            'procedure' => $this->procedureSnapshotForRun($run),
            'run' => $terminal['run'],
            'event' => $terminal['event'],
        ];
    }

    public function activeRunProcedure(ProcedureRun $run, bool $counted): ?Procedure {
        if ($run->procedure_memory_id === null) {
            return null;
        }
        $procedure = Procedure::getByID((int) $run->procedure_id);
        if ($procedure instanceof Procedure && $procedure->status === 'retiring') {
            $this->completeRetiringProcedure($procedure);
            return null;
        }
        if (!$procedure instanceof Procedure
            || $procedure->status !== 'active'
            || (int) $procedure->memory_id !== (int) $run->procedure_memory_id
        ) {
            return null;
        }
        $memory = $counted
            ? Memory::getByID((int) $run->procedure_memory_id)
            : Memory::inspectByID((int) $run->procedure_memory_id);
        return $memory instanceof Memory && $memory->status === 'active'
            ? $procedure
            : null;
    }

    public function completeRetiringProcedure(Procedure $procedure): void {
        $replacement = $this->core->procedureReplacementClaim((int) $procedure->id, (int) $procedure->memory_id);
        if ($replacement !== null) {

            return;
        }
        $claim = $this->core->procedureInvalidationClaim((int) $procedure->id, (int) $procedure->memory_id);
        if ($claim === null) {
            throw new RuntimeException('Retiring procedure is missing its transition claim.');
        }
        $this->invalidate($procedure, $claim['action_id'], $claim['reason']);
    }

    /** @return array<string, mixed> */
    public function cancelStaleRun(ProcedureRun $run): array {
        $results = is_array($run->results) ? $run->results : [];
        $actionIds = is_array($run->action_trace_ids)
            ? array_map('intval', $run->action_trace_ids)
            : [];
        $executions = ActionExecution::getAllByWhere([ 'procedure_run_id' => (int) $run->id, 'step_index' => (int) $run->current_step + 1, ]);
        if (count($executions) > 1) {
            throw new RuntimeException('Stale procedure run has no unique current action.');
        }
        if ($executions !== []) {
            $execution = $executions[0];
            $actionId = (int) $execution->action_trace_id;
            if ((string) $execution->status === 'pending') {
                $this->core->rejectPendingActionExecution($actionId, [ 'error' => 'Procedure generation retired before its pending step could dispatch.', ]);
            } elseif ((string) $execution->status === 'dispatching') {
                $claim = $this->core->claimActionDispatch($actionId);
                $execution = ActionExecution::getByField('action_trace_id', $actionId);
                if (!$execution instanceof ActionExecution) {
                    throw new RuntimeException('Stale procedure action disappeared during recovery.');
                }
                if ((string) $execution->status === 'dispatching' && !$claim['recover']) {
                    return [
                        'status' => 'in_progress',
                        'procedure' => $this->procedureSnapshotForRun($run),
                        'run' => $run->getData(),
                        'execution' => $execution->getData(),
                        'replayed' => true,
                    ];
                }
            } elseif ((string) $execution->status === 'waiting') {
                return [
                    'status' => 'waiting',
                    'procedure' => $this->procedureSnapshotForRun($run),
                    'run' => $run->getData(),
                    'execution' => $execution->getData(),
                    'replayed' => true,
                ];
            }

            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof ActionExecution
                || !in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)
            ) {
                throw new RuntimeException('Stale procedure action did not reach a durable terminal state.');
            }
            $this->finishDurableExecution($actionId);
            $result = [
                'step' => (int) $run->current_step,
                'action_id' => $actionId,
                'status' => (string) $execution->status,
            ];
            $matching = null;
            foreach ($results as $candidate) {
                if (is_array($candidate)
                    && (int) ($candidate['step'] ?? -1) === (int) $run->current_step
                    && (int) ($candidate['action_id'] ?? 0) === $actionId
                ) {
                    $matching = $candidate;
                    break;
                }
            }
            if ($matching !== null && $matching !== $result) {
                throw new RuntimeException('Stale procedure action conflicts with its durable run result.');
            }
            if ($matching === null) {
                $results[] = $result;
            }
            $actionIds[] = $actionId;
            $actionIds = array_values(array_unique($actionIds));
        }
        $reason = $run->procedure_memory_id === null
            ? 'Legacy run has no immutable procedure generation snapshot.'
            : 'Procedure generation changed or retired while the run was in progress.';
        $terminal = $this->core->finalizeProcedureRun($run, 'cancelled', $results, $actionIds, $reason);
        return [
            'status' => 'cancelled',
            'procedure' => $this->procedureSnapshotForRun($run),
            'run' => $terminal['run'],
            'event' => $terminal['event'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function procedureSnapshotForRun(ProcedureRun $run): ?array {
        if ($run->procedure_memory_id === null) {
            return null;
        }
        $procedure = Procedure::getByID((int) $run->procedure_id);
        return $procedure instanceof Procedure
            && (int) $procedure->memory_id === (int) $run->procedure_memory_id
                ? $procedure->getData()
                : null;
    }
}
