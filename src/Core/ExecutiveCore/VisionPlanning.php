<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Support\Name;
use NaviBrain\Model\WorkItem;

use InvalidArgumentException;
use NaviBrain\Core\ExecutiveComposition;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Model\ActionDispatchClaim;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\DecisionAsyncClaim;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Intention;
use NaviBrain\Model\LookRequestClaim;
use NaviBrain\Model\Memory;
use NaviBrain\Model\Procedure;
use NaviBrain\Model\ProcedureRun;
use NaviBrain\Model\SensorySource;
use NaviBrain\Perception\CommandSense;
use RuntimeException;

class VisionPlanning extends Component
{
    public function requestLook(ActionExecution $request, ?Intention $intention = null, ?DecisionCycle $cycle = null): array
    {
        $command = (string) ($request->arguments['command'] ?? '');
        $because = (string) ($request->arguments['because'] ?? '');
        $intentionId = $intention?->id;
        $actionId = $request->action_trace_id;
        $procedureRunId = $request->procedure_run_id;
        $procedureStep = $request->step_index === null ? null : (int) $request->step_index - 1;
        $decisionCycleId = $cycle?->id;
        $this->requireText($command, 'command');
        $this->requireText($because, 'reason for looking');
        if (CommandSense::resolveOperation($command) === null) {
            throw new InvalidArgumentException('Unsupported machine observation. Choose one of: ' . implode(', ', array_keys(CommandSense::operations())) . '.');
        }
        if ($intentionId !== null) {
            $this->requireIntention($intentionId);
        }

        if ($intentionId !== null && $this->requireIntention($intentionId)->status !== 'active') {
            throw new RuntimeException('Look requests require an active intention.');
        }
        if (ExecutiveControl::status()['paused']) {
            throw new RuntimeException('Cognition is paused; new look requests are not admitted.');
        }
        $execution = null;
        $run = null;
        if ($actionId !== null) {
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            $action = ActionTrace::getByID($actionId);
            $executionArguments = $execution instanceof ActionExecution
                && is_array($execution->arguments)
                ? $execution->arguments
                : [];
            if (!$execution instanceof ActionExecution
                || !$action instanceof ActionTrace
                || (string) $execution->status !== 'dispatching'
                || (string) $execution->action_kind !== 'machine.look'
                || $intentionId === null
                || (int) $action->intention_id !== $intentionId
                || !hash_equals((string) ($executionArguments['command'] ?? ''), $command)
                || !hash_equals((string) ($executionArguments['because'] ?? ''), $because)
            ) {
                throw new RuntimeException('Look request does not own the supplied action execution.');
            }
        }
        if ($procedureRunId !== null || $procedureStep !== null) {
            if (!$execution instanceof ActionExecution
                || $procedureRunId === null
                || $procedureStep === null
                || (int) ($execution->procedure_run_id ?? 0) !== $procedureRunId
                || (int) ($execution->step_index ?? 0) !== $procedureStep + 1
            ) {
                throw new RuntimeException('Procedure look request has an incomplete or crossed run-step identity.');
            }
            $run = ProcedureRun::getByID($procedureRunId);
            $runProcedure = $run instanceof ProcedureRun
                ? Procedure::getByID((int) $run->procedure_id)
                : null;
            $stepProcedure = Procedure::getByID((int) ($execution->procedure_id ?? 0));
            $runSteps = $runProcedure instanceof Procedure && is_array($runProcedure->steps)
                ? $runProcedure->steps
                : [];
            $runStep = $runSteps[$procedureStep] ?? null;
            $expectedStepProcedureId = is_array($runStep)
                && isset($runStep['source_procedure_id'])
                && $runStep['source_procedure_id'] !== null
                ? (int) $runStep['source_procedure_id']
                : (int) ($run->procedure_id ?? 0);
            $expectedStepMemoryId = is_array($runStep)
                && isset($runStep['source_procedure_memory_id'])
                && $runStep['source_procedure_memory_id'] !== null
                ? (int) $runStep['source_procedure_memory_id']
                : (int) ($run->procedure_memory_id ?? 0);
            if (!$run instanceof ProcedureRun
                || (string) $run->status !== 'running'
                || (int) $run->current_step !== $procedureStep
                || !$runProcedure instanceof Procedure
                || (string) $runProcedure->status !== 'active'
                || (int) $runProcedure->memory_id !== (int) ($run->procedure_memory_id ?? 0)
                || !$stepProcedure instanceof Procedure
                || (string) $stepProcedure->status !== 'active'
                || (int) ($execution->procedure_id ?? 0) !== $expectedStepProcedureId
                || (int) $stepProcedure->memory_id
                    !== (int) ($execution->procedure_memory_id ?? 0)
                || (int) ($execution->procedure_memory_id ?? 0) !== $expectedStepMemoryId
            ) {
                throw new RuntimeException('Look request cannot suspend a different procedure generation.');
            }
        } elseif ($execution instanceof ActionExecution
            && ($execution->procedure_run_id !== null || $execution->step_index !== null)
        ) {
            throw new RuntimeException('Procedure look action is missing its run-step identity.');
        }
        $decisionCycle = null;
        if ($decisionCycleId !== null) {
            if (!$execution instanceof ActionExecution || $procedureRunId !== null) {
                throw new RuntimeException('Decision look request has a crossed execution identity.');
            }
            $decisionCycle = DecisionCycle::getByID($decisionCycleId);
            if (!$decisionCycle instanceof DecisionCycle
                || (string) $decisionCycle->status !== 'running'
                || (string) $decisionCycle->state !== 'execute'
                || (int) $decisionCycle->intention_id !== $intentionId
            ) {
                throw new RuntimeException('Look request cannot bind a different decision cycle.');
            }
            $changes = $this->executive->decisionStateMachine->decisionInputChanges($decisionCycleId);
            if ($changes !== []) {
                throw new RuntimeException('Stale look decision; start a fresh cycle: ' . implode('; ', $changes));
            }
        }

        $event = $this->emit('look.requested', [
            'command' => $command,
            'because' => $because,

            'intention_id' => $intentionId,
            'action_id' => $actionId,
            'procedure_run_id' => $procedureRunId,
            'procedure_step' => $procedureStep,
        ]);

        $queueRecord = new LookRequestClaim([ 'event_id' => (int) $event->id, 'owner' => '', 'status' => 'queued', 'outcome_hash' => null, 'outcome_data' => null ], true, true);
        $queueRecord->save();
        if ($actionId !== null) {

            $bindRecord = ActionExecution::getByWhere(['action_trace_id' => $actionId, 'status = \'dispatching\'']);
            if (!$bindRecord instanceof ActionExecution) {
                throw new RuntimeException('Look request could not bind its action execution atomically.');
            }
            $bindRecord->setFields([ 'dispatch_event_id' => (int) $event->id, 'status' => 'waiting', 'updated_at' => date('Y-m-d H:i:s') ]);
            $bindRecord->save();

            $waitClaimRecord = ActionDispatchClaim::getByWhere(['action_trace_id' => $actionId, 'owner' => $this->dispatchOwner(), 'status = \'claimed\'']);
            if (!$waitClaimRecord instanceof ActionDispatchClaim) {
                throw new RuntimeException('Look request lost its action dispatch claim.');
            }
            $waitClaimRecord->setFields([ 'status' => 'waiting' ]);
            $waitClaimRecord->save();
        }
        if ($procedureRunId !== null || $procedureStep !== null) {
            if ($actionId === null || $procedureRunId === null || $procedureStep === null) {
                throw new RuntimeException('Procedure look request has an incomplete run-step identity.');
            }
            $actionIds = array_values(array_unique(array_merge( array_map('intval', (array) $run->action_trace_ids), [$actionId] )));

            $suspendRecord = ProcedureRun::getByWhere(['id' => $procedureRunId, 'status = \'running\'', 'current_step' => $procedureStep]);
            if (!$suspendRecord instanceof ProcedureRun) {
                throw new RuntimeException('Look request could not suspend its procedure run atomically.');
            }
            $suspendRecord->setFields([ 'action_trace_ids' => $actionIds, 'status' => 'waiting', 'updated_at' => date('Y-m-d H:i:s') ]);
            $suspendRecord->save();
        }
        if ($decisionCycle instanceof DecisionCycle) {
            $selection = is_array($decisionCycle->selection) ? $decisionCycle->selection : [];
            $decisionCycle->setFields([
                'execution' => [
                    'candidate_id' => $selection['candidate_id'] ?? null,
                    'action_id' => $actionId,
                    'status' => 'waiting',
                    'adapter_dispatch' => [
                        'status' => 'waiting',
                        'dispatch_event_id' => (int) $event->id,
                        'wait_committed' => true,
                    ],
                ],
                'state' => 'verify',
                'status' => 'waiting',
                'updated_at' => time(),
            ]);
            $decisionCycle->save();

            $claimRecord = DecisionAsyncClaim::getByWhere(['action_id' => $actionId, 'decision_cycle_id' => $decisionCycleId, 'request_event_id' => null, 'status = \'started\'']);
            if (!$claimRecord instanceof DecisionAsyncClaim) {
                throw new RuntimeException('Look request lost its pre-dispatch decision binding.');
            }
            $claimRecord->setFields([ 'request_event_id' => (int) $event->id, 'status' => 'waiting' ]);
            $claimRecord->save();
            $this->emit('decision_cycle.transition', [ 'decision_cycle_id' => $decisionCycleId, 'completed_state' => 'execute', 'next_state' => 'verify', 'elapsed_ms' => 0, ]);
        }
        return ['event' => $event->getData()];
    }

    public function actionableSenses(): array
    {
        $actionable = [];
        foreach (SensorySource::getAllByWhere(['status' => 'active']) as $source) {
            if ($source->acquisition !== 'on_demand') {
                continue;
            }
            $actionable[] = [
                'source_key' => (string) $source->source_key,
                'reveals' => (string) $source->reveals,
                'effect_ceiling' => (string) $source->effect_ceiling,
            ];
        }
        return $actionable;
    }

    public function enqueueLookProposal(?int $runId, int $now): ?array
    {
        $learned = [];
        $learnedMemories = [];
        foreach (Memory::inspectAllByWhere(['status' => 'active'], ['order' => ['id' => 'DESC'], 'limit' => 200]) as $memory) {
            if (!str_contains((string) $memory->content, 'this machine')) {
                continue;
            }
            $learnedMemories[] = $memory;
            $learned[] = mb_substr((string) $memory->content, 0, 260);
            if (count($learned) >= 8) {
                break;
            }
        }
        Memory::observeRecords($learnedMemories);

        $unexplained = array_map(static fn (array $event): array => [ 'sense' => $event['sense_key'], 'noticed' => $event['summary'], ], $this->sensoryCortex()->pendingEvents(5));

        $composition = new ExecutiveComposition(sprintf('%s can request one installed observation of this machine. The service reads bounded, fixed kernel information files for the selected operation.', Name::get()));

        $thread = CognitiveThread::getByField('thread_key', Executive::SELF_PRESENCE_THREAD_KEY);
        $track = $thread instanceof CognitiveThread ? $this->heldFocus($thread, $now) : null;
        if ($track !== null) {
            $composition->contribute('intent', sprintf('This is what %s is working on. If something could be found out that moves it forward, look at that rather than at whatever is merely nearby.', Name::get()), $track);
        }

        $composition->contribute(
            'curiosity',
            'Name one thing about this machine worth finding out right now, and the installed observation that would reveal it. '
            . 'It must be something the answer is not already known for. Prefer a question raised by what the senses '
            . 'just noticed over a general survey.',
            $unexplained
        );
        $composition->contribute(
            'what_looking_has_taught',
            $learned === []
                ? 'Nothing has been learned by looking yet, so anything found now is new.'
                : 'Already established about this machine. Do not re-run something whose answer is here; build on it.',
            $learned
        );
        $composition->contribute(
            'constraint',
            'Select exactly one operation from this catalog. Put its name in content and what it is meant '
            . 'to reveal in challenged_assumption. Arbitrary commands, paths and arguments are unsupported.',
            CommandSense::operations()
        );

        return $this->enqueueWork(new WorkItem([
            'parent_run_id' => $runId,
            'parent_intention_id' => null,
            'work_type' => Executive::LOOK_WORK_TYPE,
            'prompt' => $composition->prompt() . "\n\n" . implode("\n", [
                'Return kind machine_look, content holding only the operation name with no explanation or formatting,',
                'confidence in it being worth running, and challenged_assumption stating what it should reveal.',
                'Output exactly the four JSON fields in the supplied schema and nothing else.',
            ]),
            'input_refs' => [
                'looked_before' => count($learned),
                'for_intention' => $track === null ? null : (string) $track['following'],
            ],
            'token_budget' => 256,
            'wall_budget_seconds' => 300,
            'idempotency_key' => 'look_proposal:' . intdiv($now, 300)
        ], true, true));
    }

    public function integrateLookProposal(array $work, array $proposal, ?string $model): array
    {
        $command = trim((string) ($proposal['content'] ?? ''));
        $because = trim((string) ($proposal['challenged_assumption'] ?? ''));

        if ($command === '' || $because === '' || str_contains($command, "\n")) {
            return ['status' => 'rejected', 'reason' => 'not_a_single_command'];
        }
        if (mb_strlen($command) > 400) {
            return ['status' => 'rejected', 'reason' => 'command_too_long'];
        }
        if (CommandSense::resolveOperation($command) === null) {
            return ['status' => 'rejected', 'reason' => 'unsupported_operation'];
        }

        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $intentionId = null;
        $title = $refs['for_intention'] ?? null;
        if (is_string($title) && $title !== '') {
            $intention = Intention::getByField('title', $title);
            if ($intention instanceof Intention && $intention->status === 'active') {
                $intentionId = (int) $intention->id;
            }
        }

        $request = new ActionExecution([ 'action_kind' => 'machine.look', 'arguments' => ['command' => $command, 'because' => $because], ], true, true);
        $queued = $this->requestLook($request, $intentionId === null ? null : Intention::getByID($intentionId));
        return [
            'status' => 'look_queued',
            'command' => $command,
            'because' => $because,
            'for_intention_id' => $intentionId,
            'model' => $model,
            'event' => $queued['event'] ?? null,
        ];
    }
}
