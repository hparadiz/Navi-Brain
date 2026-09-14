<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Core\ActionSelector;
use NaviBrain\Core\CognitionPaused;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\DecisionAsyncClaim;
use NaviBrain\Model\DecisionCandidate;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Event;
use NaviBrain\Model\Memory;
use NaviBrain\Model\Procedure;
use RuntimeException;
use Throwable;

class ActionPlanning extends Component
{
    public function startAction(ActionTrace $action, ?ActionExecution $execution = null, ?DecisionCycle $decisionCycle = null): array
    {
        $intentionId = (int) $action->intention_id;
        $description = (string) $action->description;
        $expected = (string) $action->expected;
        $actionKind = $execution?->action_kind;
        $arguments = $execution?->arguments ?? [];
        $procedureId = $execution?->procedure_id;
        $procedureMemoryId = $execution?->procedure_memory_id;
        $procedureRunId = $execution?->procedure_run_id;
        $stepIndex = $execution?->step_index === null ? null : (int) $execution->step_index - 1;
        $verifier = $execution?->verifier ?: null;
        $decisionCycleId = $decisionCycle?->id;

        $this->requireText($description, 'description');
        $this->requireText($expected, 'expected result');
        $intention = $this->requireIntention($intentionId);
        if ($intention->status !== 'active') {
            throw new RuntimeException('Actions can only start for active intentions.');
        }
        if (($procedureRunId === null) !== ($stepIndex === null) || ($stepIndex !== null && $stepIndex < 0)) {
            throw new InvalidArgumentException('Procedure run actions require a non-negative run step identity.');
        }

        $procedure = $this->executive->proceduralMemory->recall($description, $expected, $actionKind, $arguments);
        if ($execution instanceof ActionExecution) {
            $execution->procedure_id ??= $procedure['procedure_id'] ?? null;
            $execution->procedure_memory_id ??= $procedure['memory_id'] ?? null;
        }
        if ($procedureRunId !== null && $stepIndex !== null) {
            $replay = $this->replayStartedProcedureStep($action, $execution, $procedure);
            if ($replay !== null) {
                return $replay;
            }
        }

        try {
        if (ExecutiveControl::status()['paused']) {
            throw new CognitionPaused('Cognition is paused; new actions are not admitted.');
        }
        if ($this->requireIntention($intentionId)->status !== 'active') {
            throw new RuntimeException('Actions can only start for active intentions.');
        }
        if ($decisionCycleId !== null) {
            $changes = $this->executive->decisionStateMachine->decisionInputChanges($decisionCycleId);
            if ($changes !== []) {
                throw new RuntimeException('Stale decision input; start a fresh cycle: ' . implode('; ', $changes));
            }
        }

        $action->setFields(['status' => 'pending', 'match_status' => 'pending']);
        $action->save();
        if ($execution instanceof ActionExecution) {
            $this->executive->proceduralMemory->recordExecution($action, $execution);
        }

        if ($decisionCycleId !== null) {
            $decisionExecution = $execution;
            if (!$decisionExecution instanceof ActionExecution || $procedureRunId !== null) {
                throw new RuntimeException('Decision action has a crossed execution identity.');
            }
            if (!$decisionCycle instanceof DecisionCycle
                || (string) $decisionCycle->status !== 'running'
                || (string) $decisionCycle->state !== 'execute'
                || (int) $decisionCycle->intention_id !== $intentionId
            ) {
                throw new RuntimeException('Action cannot bind a different decision cycle.');
            }
            $selection = is_array($decisionCycle->selection) ? $decisionCycle->selection : [];
            $candidate = DecisionCandidate::getByID((int) ($selection['candidate_id'] ?? 0));
            $selectedProcedureId = isset($selection['procedure_id'])
                && $selection['procedure_id'] !== null
                ? (int) $selection['procedure_id']
                : null;
            $selectedProcedureMemoryId = isset($selection['procedure_memory_id'])
                && $selection['procedure_memory_id'] !== null
                ? (int) $selection['procedure_memory_id']
                : null;
            $effectiveProcedureId = $procedureId ?? ($procedure['procedure_id'] ?? null);
            $effectiveProcedureMemoryId = $procedureMemoryId ?? ($procedure['memory_id'] ?? null);
            $candidateEvaluation = $candidate instanceof DecisionCandidate
                && is_array($candidate->evaluation)
                ? $candidate->evaluation
                : [];
            $candidateProcedureId = isset($candidateEvaluation['recalled_procedure_id'])
                && $candidateEvaluation['recalled_procedure_id'] !== null
                ? (int) $candidateEvaluation['recalled_procedure_id']
                : null;
            $candidateProcedureMemoryId = isset($candidateEvaluation['recalled_procedure_memory_id'])
                && $candidateEvaluation['recalled_procedure_memory_id'] !== null
                ? (int) $candidateEvaluation['recalled_procedure_memory_id']
                : null;
            $recalledProcedureId = isset($procedure['procedure_id'])
                ? (int) $procedure['procedure_id']
                : null;
            $recalledProcedureMemoryId = isset($procedure['memory_id'])
                ? (int) $procedure['memory_id']
                : null;
            if (!$candidate instanceof DecisionCandidate
                || (int) $candidate->decision_cycle_id !== $decisionCycleId
                || (string) $candidate->status !== 'selected'
                || !hash_equals((string) $candidate->action_kind, (string) $actionKind)
                || (array) $candidate->arguments !== $arguments
                || !hash_equals((string) $candidate->description, $description)
                || !hash_equals((string) $candidate->expected, $expected)
                || (int) ($selectedProcedureId ?? 0) !== (int) ($effectiveProcedureId ?? 0)
                || (int) ($selectedProcedureMemoryId ?? 0)
                    !== (int) ($effectiveProcedureMemoryId ?? 0)
                || (int) ($candidateProcedureId ?? 0) !== (int) ($selectedProcedureId ?? 0)
                || (int) ($candidateProcedureMemoryId ?? 0)
                    !== (int) ($selectedProcedureMemoryId ?? 0)
                || (int) ($recalledProcedureId ?? 0) !== (int) ($selectedProcedureId ?? 0)
                || (int) ($recalledProcedureMemoryId ?? 0)
                    !== (int) ($selectedProcedureMemoryId ?? 0)
            ) {
                throw new RuntimeException('Decision action does not match its selected candidate.');
            }
            $decisionCycle->setFields([
                'execution' => [
                    'candidate_id' => $selection['candidate_id'] ?? null,
                    'action_id' => (int) $action->id,
                    'status' => 'pending',
                    'adapter_dispatch' => null,
                ],
                'updated_at' => time(),
            ]);
            $decisionCycle->save();

            $decisionClaimRecord = new DecisionAsyncClaim([
                'action_id' => (int) $action->id,
                'decision_cycle_id' => $decisionCycleId,
                'request_event_id' => null,
                'owner' => $this->dispatchOwner(),
                'status' => 'started',
                'outcome_hash' => null
            ], true, true);
            $decisionClaimRecord->save();
        }

        $event = $this->emit('action.started', [
            'action_id' => $action->id,
            'intention_id' => $intentionId,
            'description' => $description,
            'expected' => $expected,
            'action_kind' => $actionKind,
            'arguments' => $actionKind === null ? null : $arguments,
            'procedure_id' => $procedureId ?? ($procedure['procedure_id'] ?? null),
            'procedure_memory_id' => $procedureMemoryId ?? ($procedure['memory_id'] ?? null),
            'decision_cycle_id' => $decisionCycleId,
        ]);

        $action->setField('start_event_id', $event->id);
        $action->save();

        $procedureEvent = null;
        if ($procedure !== null) {
            $procedureEvent = $this->emit('procedure.recalled', [
                'memory_id' => $procedure['memory_id'],
                'action_id' => (int) $action->id,
                'intention_id' => $intentionId,
                'procedure_id' => $procedureId ?? ($procedure['procedure_id'] ?? null),
                'procedure_key' => $procedure['procedure_key'],
                'recall_model_call_required' => false,
                'authorizes_execution' => (bool) ($procedure['authorizes_execution'] ?? false),
            ])->getData();
        }

        return [
            'action' => $action->getData(),
            'event' => $event->getData(),
            'execution' => $execution?->getData(),
            'procedure' => $procedure,
            'procedure_event' => $procedureEvent,
        ];
        } catch (Throwable $throwable) {
            if ($procedureRunId === null || $stepIndex === null) {
                throw $throwable;
            }
            $replay = $this->replayStartedProcedureStep($action, $execution, $procedure);
            if ($replay === null) {
                throw $throwable;
            }
            return $replay;
        }
    }

    public function replayStartedProcedureStep(ActionTrace $requestedAction, ActionExecution $requestedExecution, ?array $procedure): ?array
    {
        $intentionId = (int) $requestedAction->intention_id;
        $description = (string) $requestedAction->description;
        $expected = (string) $requestedAction->expected;
        $actionKind = (string) $requestedExecution->action_kind;
        $arguments = (array) $requestedExecution->arguments;
        $procedureId = $requestedExecution->procedure_id;
        $procedureMemoryId = $requestedExecution->procedure_memory_id;
        $procedureRunId = $requestedExecution->procedure_run_id;
        $stepIndex = (int) $requestedExecution->step_index - 1;
        $verifier = $requestedExecution->verifier ?: null;
        $executions = ActionExecution::getAllByWhere([ 'procedure_run_id' => $procedureRunId, 'step_index' => $stepIndex + 1, ]);
        if ($executions === []) {
            return null;
        }
        if (count($executions) !== 1 || $actionKind === null) {
            throw new RuntimeException('Procedure run step identity is not unique.');
        }
        $execution = $executions[0];
        $effectiveVerifier = $verifier ?? $this->executive->proceduralMemory->verifierFor($actionKind);

        if ((int) ($execution->procedure_id ?? 0) !== (int) ($procedureId ?? 0)
            || ($execution->procedure_memory_id !== null
                && (int) $execution->procedure_memory_id !== (int) ($procedureMemoryId ?? 0))
            || (string) $execution->action_kind !== $actionKind
            || (array) $execution->arguments !== $arguments
            || (array) $execution->verifier !== $effectiveVerifier
        ) {
            throw new RuntimeException('Procedure run step was already started with a different execution request.');
        }
        $action = ActionTrace::getByID((int) $execution->action_trace_id);
        if (!$action instanceof ActionTrace
            || (int) $action->intention_id !== $intentionId
            || (string) $action->description !== $description
            || (string) $action->expected !== $expected
        ) {
            throw new RuntimeException('Procedure run step points to a different action trace.');
        }
        $event = Event::getByID((int) ($action->start_event_id ?? 0));
        if (!$event instanceof Event) {
            throw new RuntimeException('Procedure run step is missing its start event.');
        }
        return [
            'action' => $action->getData(),
            'event' => $event->getData(),
            'execution' => $execution->getData(),
            'procedure' => $procedure,
            'procedure_event' => null,
            'replayed' => true,
        ];
    }

    public function executeAction(ActionTrace $action, ActionExecution $execution, ?DecisionCycle $decisionCycle = null): array
    {
        return $this->executive->proceduralMemory->executeAction($action, $execution, $decisionCycle);
    }

    public function runProcedure(int $procedureId, int $intentionId, array $arguments, string $invocationKey): array
    {
        return $this->executive->proceduralMemory->run($procedureId, $intentionId, $arguments, $invocationKey);
    }

    public function composeProcedure(string $name, string $description, array $procedureIds, string $authority): array
    {
        return $this->executive->proceduralMemory->compose($name, $description, $procedureIds, $authority);
    }

    public function listProcedures(?string $status = null): array
    {
        return $this->executive->proceduralMemory->list($status);
    }

    public function startDecisionCycle(int $intentionId, string $trigger, ?int $threadId = null, ?string $modelHint = null, ?int $parentRunId = null): array
    {
        return $this->executive->decisionStateMachine->start($intentionId, $trigger, $threadId, $modelHint, $parentRunId);
    }

    public function listDecisionCycles(?string $status = null, int $limit = 20): array
    {
        return $this->executive->decisionStateMachine->list($status, $limit);
    }

    public function showDecisionCycle(int $id): array
    {
        return $this->executive->decisionStateMachine->show($id);
    }

    public function compareDecisionModels(int $limit = 200): array
    {
        return $this->executive->decisionStateMachine->compare($limit);
    }

    public function finishAction(int $actionId, string $status, string $observed, bool $matched, string $repairNote): array
    {
        $this->requireChoice($status, ['succeeded', 'failed', 'cancelled'], 'status');
        $this->requireText($observed, 'observed result');
        $this->requireText($repairNote, 'repair note');

        $matchStatus = $matched ? 'matched' : 'mismatched';
        $action = $this->requireAction($actionId);
        if ($action->status === 'pending') {
            $event = $this->emitOnce('action.finished:' . $actionId, 'action.finished', [
                'action_id' => (int) $action->id,
                'intention_id' => $action->intention_id,
                'status' => $status,
                'expected' => $action->expected,
                'observed' => $observed,
                'match_status' => $matchStatus,
                'repair_note' => $repairNote,
            ]);

            $action->setFields([
                'completed_at' => time(),
                'observed' => $observed,
                'status' => $status,
                'match_status' => $matchStatus,
                'repair_note' => $repairNote,
                'completion_event_id' => $event->id,
            ]);
            $action->save();

            $execution = \NaviBrain\Model\ActionExecution::getByField('action_trace_id', (int) $action->id);
            if ($execution instanceof \NaviBrain\Model\ActionExecution
                && $execution->status === 'pending'
            ) {
                $execution->setFields([ 'observed' => ['reported' => $observed], 'status' => $status, 'completed_at' => time(), 'updated_at' => time(), ]);
                $execution->save();
            }
        } else {
            if ((string) $action->status !== $status
                || (string) $action->observed !== $observed
                || (string) $action->match_status !== $matchStatus
                || (string) $action->repair_note !== $repairNote
            ) {
                throw new RuntimeException('The action was already finished with a different result.');
            }
            $event = $this->eventByDedupeKey('action.finished:' . $actionId);
            if (!$event instanceof Event || $event->kind !== 'action.finished') {
                throw new RuntimeException('The finished action is missing its completion event.');
            }
        }

        $memory = Memory::fromAction($action, $event);
        $memory->save();
        $procedure = $this->executive->proceduralMemory->observe($action, $event, $memory);

        return [
            'action' => $action->getData(),
            'event' => $event->getData(),
            'episodic_memory' => $memory->getData(),
            'procedure' => $procedure,
        ];
    }

    public function listActions(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['pending', 'succeeded', 'failed', 'cancelled'], 'status');
            $records = ActionTrace::getAllByWhere(['status' => $status], ['order' => ['created_at' => 'DESC']]);
        } else {
            $records = ActionTrace::getAll(['order' => ['created_at' => 'DESC']]);
        }

        return $this->records($records);
    }

    public function selectAction(int $now): array
    {
        $affect = $this->appraiseNow($now);
        $cortex = $this->sensoryCortex();
        $pending = $cortex->pendingEvents(8);

        $addressed = array_filter($pending, static fn (array $event): bool => ($event['addressed'] ?? false) === true) !== [];

        $consolidated = [];
        foreach (Memory::inspectAllByWhere(['tier' => 'semantic'], ['limit' => 500]) as $semantic) {
            if ($semantic->source_memory_id !== null) {
                $consolidated[(int) $semantic->source_memory_id] = true;
            }
        }
        $unconsolidated = 0;
        foreach (Memory::inspectAllByWhere(['tier' => 'episodic', 'status' => 'active'], ['order' => ['id' => 'DESC'], 'limit' => 200]) as $episode) {
            if (!isset($consolidated[(int) $episode->id]) && $this->isEvidence((string) $episode->content)) {
                $unconsolidated++;
            }
        }

        $thread = CognitiveThread::getByField('thread_key', Executive::SELF_PRESENCE_THREAD_KEY);
        $otherAgent = $this->executive->otherModel->decisionState();
        $decision = ActionSelector::choose(
            $affect,
            [
                'answer' => $addressed,
                'speak' => true,
                'look' => $this->actionableSenses() !== [],
                'think' => true,
                'consolidate' => $unconsolidated >= 2,
            ],
            [
                'user_present' => $this->presenceNow() === true,
                'unexplained_edges' => count($pending),
                'unconsolidated_episodes' => $unconsolidated,
                'holding_a_track' => $thread instanceof CognitiveThread
                    && trim((string) $thread->desired_outcome) !== '',
                'other_agent_speech_factor' => $otherAgent['speech_factor'],
                'other_agent_counterfactual_speech_factor' => $otherAgent['counterfactual_speech_factor'],
                'other_agent_attention_mode' => $otherAgent['attention_mode'],
                'other_agent_model_ablated' => $otherAgent['ablated'],
            ]
        );

        $decision['feeling'] = $affect->dominant;
        $this->emit('action.selected', $decision);
        return $decision;
    }
}
