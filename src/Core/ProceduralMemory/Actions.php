<?php

declare(strict_types=1);

namespace NaviBrain\Core\ProceduralMemory;

use NaviBrain\Core\ProceduralMemory;

use InvalidArgumentException;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Event;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\WorkingMemorySlot;
use NaviBrain\Model\Procedure;
use NaviBrain\Model\ProcedureRun;
use NaviBrain\Model\SensorySource;
use NaviBrain\Perception\CommandSense;
use RuntimeException;
use Throwable;

class Actions extends Component
{
    public function recordExecution(ActionTrace $action, ActionExecution $execution): void {
        $actionKind = (string) $execution->action_kind;
        $arguments = (array) $execution->arguments;
        $procedureId = $execution->procedure_id;
        $procedureMemoryId = $execution->procedure_memory_id;
        $procedureRunId = $execution->procedure_run_id;
        $stepIndex = $execution->step_index === null ? null : (int) $execution->step_index - 1;
        $this->validateArguments($actionKind, $arguments);
        if ($procedureId !== null) {
            $procedure = $this->requireProcedure($procedureId);
            $procedureMemoryId ??= (int) $procedure->memory_id;
            $memory = Memory::inspectByID($procedureMemoryId);
            if ($procedure->status !== 'active'
                || (int) $procedure->memory_id !== $procedureMemoryId
                || !$memory instanceof Memory
                || $memory->status !== 'active'
            ) {
                throw new RuntimeException('Action execution requires an active token-memory procedure generation.');
            }
        }
        if ($procedureRunId !== null) {
            $run = ProcedureRun::getByID($procedureRunId);
            if (!$run instanceof ProcedureRun || $stepIndex === null) {
                throw new RuntimeException(sprintf('Procedure run %d does not exist.', $procedureRunId));
            }
            $parent = Procedure::getByID((int) $run->procedure_id);
            $steps = $parent instanceof Procedure && is_array($parent->steps) ? $parent->steps : [];
            $step = $steps[$stepIndex] ?? null;
            $expectedProcedureId = is_array($step)
                && isset($step['source_procedure_id'])
                && $step['source_procedure_id'] !== null
                ? (int) $step['source_procedure_id']
                : (int) $run->procedure_id;
            $expectedMemoryId = is_array($step)
                && isset($step['source_procedure_memory_id'])
                && $step['source_procedure_memory_id'] !== null
                ? (int) $step['source_procedure_memory_id']
                : (int) ($run->procedure_memory_id ?? 0);
            $stepDefaults = is_array($step['arguments'] ?? null) ? $step['arguments'] : [];
            $runArguments = is_array($run->arguments) ? $run->arguments : [];
            $expectedArguments = array_replace($stepDefaults, array_intersect_key($runArguments, $stepDefaults));
            if ((string) $run->status !== 'running'
                || (int) $run->current_step !== $stepIndex
                || !$parent instanceof Procedure
                || (string) $parent->status !== 'active'
                || (int) $parent->memory_id !== (int) ($run->procedure_memory_id ?? 0)
                || !is_array($step)
                || (int) ($procedureId ?? 0) !== $expectedProcedureId
                || (int) ($procedureMemoryId ?? 0) !== $expectedMemoryId
                || !hash_equals((string) ($step['action_kind'] ?? ''), $actionKind)
                || $expectedArguments !== $arguments
                || !hash_equals((string) ($step['description'] ?? $parent->description), (string) $action->description)
                || !hash_equals((string) ($step['expected'] ?? 'Installed postcondition verifies.'), (string) $action->expected)
            ) {
                throw new RuntimeException('Action execution does not match its immutable procedure run step.');
            }
        }
        /** @var ActionExecution $execution */
        $execution->setFields([
            'action_trace_id' => (int) $action->id,
            'verifier' => $execution->verifier ?: ProceduralMemory::ADAPTERS[$actionKind]['verifier'],
            'observed' => [],
            'verified' => 0,
            'status' => 'pending',
            'updated_at' => time(),
        ]);
        $execution->save();
    }

    public function recordObserved(int $actionId, array $observed, bool $verified, string $status): void {
        $this->core->finalizeActionExecution($actionId, $observed, $verified, $status);
    }

    /** @return array<string, mixed> */
    public function finishDurableExecution(int $actionId): array {
        $execution = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$execution instanceof ActionExecution
            || !in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)
        ) {
            throw new RuntimeException('Action execution has no durable terminal outcome to finish.');
        }
        $observed = is_array($execution->observed) ? $execution->observed : [];
        $matched = $execution->status === 'succeeded' && (int) $execution->verified === 1;

        $this->core->finalizeActionExecution($actionId, $observed, (int) $execution->verified === 1, (string) $execution->status);
        if (($observed['dispatch_not_attempted'] ?? false) === true) {
            $repairNote = 'No adapter was attempted; start a fresh decision after cognition resumes.';
        } elseif ((string) $execution->action_kind === 'machine.look') {
            $repairNote = $matched
                ? 'No repair required; fixed-file observation returned successfully.'
                : 'The fixed-file observation did not return successfully.';
        } elseif (isset($observed['error']) && is_string($observed['error'])) {
            $repairNote = 'Adapter dispatch failed: ' . $observed['error'];
        } else {
            $repairNote = $matched
                ? 'No repair required; installed postcondition verified.'
                : 'Installed postcondition failed.';
        }
        return $this->core->finishAction($actionId, (string) $execution->status, $this->encode($observed), $matched, $repairNote);
    }

    public function executionAuthorizationActive(int $actionId): bool {
        $execution = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$execution instanceof ActionExecution) {
            throw new RuntimeException('Action execution disappeared before authorization.');
        }
        if ($execution->procedure_id === null) {
            return true;
        }
        if ($execution->procedure_memory_id === null) {
            return false;
        }
        $procedure = Procedure::getByID((int) $execution->procedure_id);
        if (!$procedure instanceof Procedure
            || $procedure->status !== 'active'
            || (int) $procedure->memory_id !== (int) $execution->procedure_memory_id
        ) {
            return false;
        }
        $memory = Memory::inspectByID((int) $execution->procedure_memory_id);
        return $memory instanceof Memory && $memory->status === 'active';
    }

    /** @return array<string, mixed> */
    public function executeAction(ActionTrace $action, ActionExecution $request, ?DecisionCycle $decisionCycle = null): array {
        $intentionId = (int) $action->intention_id;
        $actionKind = (string) $request->action_kind;
        $arguments = (array) $request->arguments;
        $this->validateArguments($actionKind, $arguments);
        $started = $this->core->startAction($action, $request, $decisionCycle);
        $actionId = (int) ($started['action']['id'] ?? 0);
        $executionBeforeClaim = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$executionBeforeClaim instanceof ActionExecution) {
            throw new RuntimeException('Action execution disappeared before dispatch claim.');
        }
        $request = $executionBeforeClaim;
        if ((int) $action->id !== $actionId) {
            $action = ActionTrace::getByID($actionId);
            if (!$action instanceof ActionTrace) {
                throw new RuntimeException('Replayed action trace disappeared.');
            }
        }
        if ($executionBeforeClaim->status === 'pending'
            && !$this->executionAuthorizationActive($actionId)
        ) {
            $observed = ['error' => 'The exact token-memory procedure generation retired before dispatch.'];
            $this->core->rejectPendingActionExecution($actionId, $observed);
            return [
                'status' => 'failed',
                'started' => $started,
                'finished' => $this->finishDurableExecution($actionId),
            ];
        }
        try {
            $dispatchClaim = $this->core->claimActionDispatch($actionId);
        } catch (Throwable $throwable) {
            $executionAfterFailure = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$executionAfterFailure instanceof ActionExecution) {
                throw $throwable;
            }
            if ($executionAfterFailure->status !== 'pending') {

                $dispatchClaim = $this->core->claimActionDispatch($actionId);
            } elseif ($this->executionAuthorizationActive($actionId)) {
                throw $throwable;
            } else {
                $observed = ['error' => 'The exact token-memory procedure generation retired during dispatch claim.'];
                $this->core->rejectPendingActionExecution($actionId, $observed);
                return [
                    'status' => 'failed',
                    'started' => $started,
                    'finished' => $this->finishDurableExecution($actionId),
                ];
            }
        }
        if (!$dispatchClaim['claimed']) {
            if ($dispatchClaim['recover']) {
                return [
                    'status' => 'failed',
                    'started' => $started,
                    'finished' => $this->finishDurableExecution($actionId),
                    'indeterminate' => true,
                ];
            }
            $durableStatus = (string) ($dispatchClaim['execution']['status'] ?? '');
            if ($durableStatus === 'pending') {

                return ['status' => 'held', 'reason' => 'cognition_paused', 'started' => $started];
            }
            if ($durableStatus === 'dispatching') {
                return ['status' => 'in_progress', 'started' => $started];
            }
            if ($durableStatus === 'waiting') {
                return ['status' => 'waiting', 'started' => $started, 'replayed' => true];
            }
            if (in_array($durableStatus, ['succeeded', 'failed', 'cancelled'], true)) {
                return [
                    'status' => $durableStatus,
                    'started' => $started,
                    'finished' => $this->finishDurableExecution($actionId),
                    'replayed' => true,
                ];
            }
            throw new RuntimeException('Action dispatch is in an invalid durable state.');
        }

        try {
            $dispatch = $this->dispatch($action, $request, $decisionCycle);
        } catch (Throwable $throwable) {
            $committed = ActionExecution::getByField('action_trace_id', $actionId);
            if ($actionKind === 'machine.look'
                && $committed instanceof ActionExecution
                && (int) ($committed->dispatch_event_id ?? 0) > 0
                && in_array($committed->status, ['waiting', 'succeeded', 'failed', 'cancelled'], true)
            ) {
                $request = Event::getByID((int) $committed->dispatch_event_id);
                $payload = $request instanceof Event && is_array($request->payload)
                    ? $request->payload
                    : [];
                if (!$request instanceof Event
                    || (string) $request->kind !== 'look.requested'
                    || (int) ($payload['action_id'] ?? 0) !== $actionId
                    || !hash_equals((string) ($payload['command'] ?? ''), (string) ($arguments['command'] ?? ''))
                    || !hash_equals((string) ($payload['because'] ?? ''), (string) ($arguments['because'] ?? ''))
                ) {
                    throw new RuntimeException('Committed look dispatch does not match its durable request.', previous: $throwable);
                }
                $dispatch = [
                    'status' => 'waiting',
                    'dispatch_event_id' => (int) $request->id,
                    'wait_committed' => true,
                    'observed' => ['queued' => true],
                ];
                if ((string) $committed->status === 'waiting') {
                    return ['status' => 'waiting', 'started' => $started, 'dispatch' => $dispatch];
                }
                return [
                    'status' => (string) $committed->status,
                    'started' => $started,
                    'dispatch' => $dispatch,
                    'finished' => $this->finishDurableExecution($actionId),
                    'replayed' => true,
                ];
            }
            $observed = ['error' => $throwable->getMessage()];
            $this->recordObserved($actionId, $observed, false, 'failed');
            $finished = $this->finishDurableExecution($actionId);
            return ['status' => 'failed', 'started' => $started, 'finished' => $finished];
        }

        if (($dispatch['status'] ?? null) === 'waiting') {
            if (($dispatch['wait_committed'] ?? false) !== true) {
                throw new RuntimeException('Asynchronous adapter returned before committing its wait state.');
            }
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof ActionExecution
                || (int) ($execution->dispatch_event_id ?? 0) !== (int) ($dispatch['dispatch_event_id'] ?? 0)
            ) {
                throw new RuntimeException('Asynchronous adapter lost its durable request identity.');
            }
            if (in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)) {
                return [
                    'status' => (string) $execution->status,
                    'started' => $started,
                    'dispatch' => $dispatch,
                    'finished' => $this->finishDurableExecution($actionId),
                    'replayed' => true,
                ];
            }
            if ((string) $execution->status !== 'waiting') {
                throw new RuntimeException('Asynchronous adapter has an invalid durable execution state.');
            }
            return ['status' => 'waiting', 'started' => $started, 'dispatch' => $dispatch];
        }

        $observed = is_array($dispatch['observed'] ?? null) ? $dispatch['observed'] : [];
        $matched = ($dispatch['matched'] ?? false) === true;
        $status = $matched ? 'succeeded' : 'failed';
        $this->recordObserved($actionId, $observed, $matched, $status);
        $finished = $this->finishDurableExecution($actionId);
        return ['status' => $status, 'started' => $started, 'dispatch' => $dispatch, 'finished' => $finished];
    }

    public function completePendingLook(int $requestEventId, array $reading): ?array {
        $execution = ActionExecution::getByField('dispatch_event_id', $requestEventId);
        if (!$execution instanceof ActionExecution
            || !in_array($execution->status, ['waiting', 'succeeded', 'failed'], true)
        ) {
            return null;
        }
        if ($execution->status === 'waiting') {
            $worked = ($reading['refused'] ?? false) !== true && ($reading['exit_code'] ?? null) === 0;
            $observed = [
                'refused' => ($reading['refused'] ?? false) === true,
                'exit_code' => $reading['exit_code'] ?? null,
                'timed_out' => ($reading['timed_out'] ?? false) === true,
                'output_digest' => $reading['output_digest'] ?? null,
                'output_bytes' => $reading['output_bytes'] ?? null,
            ];
        } else {

            $observed = is_array($execution->observed) ? $execution->observed : [];
            $worked = $execution->status === 'succeeded' && (int) $execution->verified === 1;
        }
        $actionId = (int) $execution->action_trace_id;
        if ($execution->status === 'waiting') {
            $this->recordObserved($actionId, $observed, $worked, $worked ? 'succeeded' : 'failed');
        }
        $finished = $this->finishDurableExecution($actionId);

        $decisionCycle = $this->core->decisionStateMachine()->completeAsyncAction($actionId, $worked, $finished);

        $runId = $execution->procedure_run_id === null ? null : (int) $execution->procedure_run_id;
        if ($runId === null) {
            return ['action' => $finished, 'procedure_run' => null, 'decision_cycle' => $decisionCycle];
        }
        $run = ProcedureRun::getByID($runId);
        if (!$run instanceof ProcedureRun
            || !in_array($run->status, ['waiting', 'running', 'succeeded', 'failed', 'cancelled'], true)
        ) {
            return ['action' => $finished, 'procedure_run' => null, 'decision_cycle' => $decisionCycle];
        }
        if (in_array($run->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return [
                'action' => $finished,
                'procedure_run' => $this->replayTerminalRun($run),
                'decision_cycle' => $decisionCycle,
            ];
        }
        $stepIndex = $execution->step_index === null ? -1 : (int) $execution->step_index - 1;
        if ($stepIndex < 0) {
            throw new RuntimeException('Asynchronous action has no procedure step identity.');
        }
        $results = is_array($run->results) ? $run->results : [];
        $result = [
            'step' => $stepIndex,
            'action_id' => $actionId,
            'status' => $worked ? 'succeeded' : 'failed',
        ];
        $matchingResult = null;
        foreach ($results as $candidate) {
            if (is_array($candidate) && (int) ($candidate['action_id'] ?? 0) === $actionId) {
                $matchingResult = $candidate;
                break;
            }
        }
        if ($matchingResult !== null && $matchingResult !== $result) {
            throw new RuntimeException('Asynchronous procedure step was already recorded differently.');
        }
        if ($matchingResult === null) {
            $results[] = $result;
        }
        if (!$worked) {
            return [
                'action' => $finished,
                'procedure_run' => $this->failRun($run, $results, 'Asynchronous step postcondition failed.'),
                'decision_cycle' => $decisionCycle,
            ];
        }
        $this->core->resumeProcedureRunAfterAsync($run, $results, $result);
        $run = ProcedureRun::getByID((int) $run->id);
        if (!$run instanceof ProcedureRun) {
            throw new RuntimeException('Resumed procedure run disappeared.');
        }
        return [
            'action' => $finished,
            'procedure_run' => $this->advanceRun($run),
            'decision_cycle' => $decisionCycle,
        ];
    }

    /** @return array<string, mixed> */
    public function finishDurableAction(int $actionId): array {
        return $this->finishDurableExecution($actionId);
    }

    /** @return array<string, mixed> */
    public function dispatch(ActionTrace $action, ActionExecution $execution, ?DecisionCycle $decisionCycle = null): array {
        $intentionId = (int) $action->intention_id;
        $kind = (string) $execution->action_kind;
        $arguments = (array) $execution->arguments;
        $verifier = (array) $execution->verifier;
        if ($kind === 'memory.search') {
            $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 20;
            $limit = max(1, min(100, $limit));

            $rows = array_values(array_filter($this->core->searchMemory((string) $arguments['query'], min(100, max(20, $limit * 4))), static fn (array $row): bool => ($row['tier'] ?? null) !== 'working'));
            $rows = array_slice($rows, 0, $limit);
            $observed = [
                'result_count' => count($rows),
                'memory_ids' => array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows)),
                'memories' => $rows,
            ];
            $minimum = max(0, (int) ($verifier['minimum'] ?? 0));
            return ['status' => 'completed', 'matched' => count($rows) >= $minimum, 'observed' => $observed];
        }

        if ($kind === 'working_memory.write') {
            $intention = Intention::getByID($intentionId);
            $scope = (string) ($arguments['scope'] ?? 'shared');
            if ($scope === 'shared'
                && (string) $arguments['role'] === 'safety_notice'
                && (!$intention instanceof Intention || !in_array($intention->authority, ['user', 'developer'], true))
            ) {
                throw new RuntimeException('Only user/developer-authorized work may write the shared safety slot.');
            }
            $threadId = null;
            if (preg_match('/^thread:(\d+)$/', $scope, $matches) === 1) {
                $threadId = (int) $matches[1];
            }
            $stored = $this->core->workingMemory()->publish(new WorkingMemorySlot([
                'slot_role' => (string) $arguments['role'],
                'claim' => (string) $arguments['claim'],
                'record_type' => (string) ($arguments['record_type'] ?? 'reasoning'),
                'record_id' => isset($arguments['record_id']) ? (int) $arguments['record_id'] : null,
                'confidence' => isset($arguments['confidence']) ? (float) $arguments['confidence'] : 0.7,
                'expires_at' => time() + (int) ($arguments['ttl_seconds'] ?? 900),
                'scope_key' => $scope,
                'thread_id' => $threadId,
            ], true, true));
            $matched = (string) ($stored['claim'] ?? '') === trim((string) $arguments['claim']);
            return ['status' => 'completed', 'matched' => $matched, 'observed' => ['slot' => $stored]];
        }

        if ($kind === 'memory.consolidate') {
            $episodeId = (int) $arguments['episode_id'];
            $confidence = (float) $arguments['confidence'];
            if ($confidence < 0.0 || $confidence > 1.0) {
                throw new InvalidArgumentException('memory.consolidate confidence must be between 0 and 1.');
            }
            $stored = $this->core->consolidateMemory($episodeId, (string) $arguments['content'], $confidence);
            $memory = is_array($stored['memory'] ?? null) ? $stored['memory'] : [];
            $matched = ($memory['tier'] ?? null) === 'semantic'
                && (int) ($memory['source_memory_id'] ?? 0) === $episodeId
                && ($memory['status'] ?? null) === 'active';
            return [
                'status' => 'completed',
                'matched' => $matched,
                'observed' => [
                    'memory_id' => $memory['id'] ?? null,
                    'tier' => $memory['tier'] ?? null,
                    'source_memory_id' => $memory['source_memory_id'] ?? null,
                ],
            ];
        }

        if ($kind === 'machine.look') {
            if (CommandSense::resolveOperation((string) $arguments['command']) === null) {
                throw new InvalidArgumentException('machine.look requires a named fixed-file observation; arbitrary commands are unsupported.');
            }
            $source = SensorySource::getByField('source_key', 'machine_inspection');
            if (!$source instanceof SensorySource
                || $source->status !== 'active'
                || $source->effect_ceiling !== 'observe'
            ) {
                throw new RuntimeException('The user-authorized machine inspection source is not active.');
            }
            $queued = $this->core->requestLook($execution, Intention::getByID($intentionId), $decisionCycle);
            return [
                'status' => 'waiting',
                'dispatch_event_id' => (int) ($queued['event']['id'] ?? 0),
                'wait_committed' => true,
                'observed' => ['queued' => true],
            ];
        }

        throw new RuntimeException(sprintf('No installed adapter for action kind %s.', $kind));
    }
}
