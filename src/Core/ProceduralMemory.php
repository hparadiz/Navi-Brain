<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use InvalidArgumentException;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Event;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\Procedure;
use NaviBrain\Model\ProcedureRun;
use NaviBrain\Model\SensorySource;
use RuntimeException;
use Throwable;

/**
 * Verified, typed procedural memory and its deliberately small runtime.
 *
 * Code owns authority and adapters; learned records only bind arguments to an
 * already-installed adapter. This preserves CoALA's distinction between risky
 * procedural writes and safe reuse: memory cannot manufacture a new effect.
 */
final class ProceduralMemory
{
    private const SUCCESS_STREAK = 3;
    private const MAX_COMPOSED_STEPS = 8;
    private const LEGACY_MARKER = '/^\[procedure action-shape=([a-f0-9]{64})\]/';

    /**
     * The complete executable action space. Adding a row here is a code review,
     * not something learned memory can do.
     *
     * @var array<string, array{action_class: string, effect: string, required: array<string, string>, optional: array<string, string>, verifier: array<string, mixed>}>
     */
    private const ADAPTERS = [
        'memory.search' => [
            'action_class' => 'retrieval',
            'effect' => 'think',
            'required' => ['query' => 'string'],
            'optional' => ['limit' => 'integer'],
            'verifier' => ['kind' => 'result_count_at_least', 'minimum' => 0],
        ],
        'working_memory.write' => [
            'action_class' => 'reasoning',
            'effect' => 'think',
            'required' => ['role' => 'string', 'claim' => 'string'],
            'optional' => [
                'scope' => 'string',
                'confidence' => 'number',
                'ttl_seconds' => 'integer',
                'record_type' => 'string',
                'record_id' => 'integer',
            ],
            'verifier' => ['kind' => 'working_slot_equals'],
        ],
        'memory.consolidate' => [
            'action_class' => 'learning',
            'effect' => 'prepare',
            'required' => [
                'episode_id' => 'integer',
                'content' => 'string',
                'confidence' => 'number',
            ],
            'optional' => [],
            'verifier' => ['kind' => 'semantic_memory_sourced_from_episode'],
        ],
        'machine.look' => [
            'action_class' => 'grounding',
            'effect' => 'observe',
            'required' => ['command' => 'string', 'because' => 'string'],
            'optional' => [],
            'verifier' => ['kind' => 'exit_code_zero'],
        ],
    ];

    public function __construct(private readonly ExecutiveCore $core)
    {
    }

    /** @return list<array<string, mixed>> */
    public function adapters(): array
    {
        $rows = [];
        foreach (self::ADAPTERS as $kind => $adapter) {
            $rows[] = ['action_kind' => $kind] + $adapter;
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function planningAdapters(): array
    {
        return $this->adaptersByClass(['retrieval', 'reasoning']);
    }

    /** @return list<array<string, mixed>> */
    public function terminalAdapters(): array
    {
        return $this->adaptersByClass(['grounding', 'learning']);
    }

    /**
     * The top-level decision procedure is code-based procedural memory. It is
     * installed by the designer rather than learned into the Procedure table.
     *
     * @return array<string, mixed>
     */
    public function decisionProcedure(): array
    {
        return [
            'procedure_key' => 'decision-cycle-v1',
            'implementation' => DecisionStateMachine::class,
            'authority' => 'developer',
            'status' => 'installed',
            'planning_action_classes' => ['retrieval', 'reasoning'],
            'terminal_action_classes' => ['grounding', 'learning'],
            'stages' => ['observe', 'retrieve', 'reason', 'propose', 'evaluate', 'select', 'execute', 'verify', 'adapt'],
            'impasse_policy' => 'record and reconsider on the next scheduled cycle',
        ];
    }

    /** @return array<string, mixed> */
    public function inspectCandidate(
        string $actionKind,
        array $arguments,
        string $description,
        string $expected
    ): array {
        try {
            $this->validateArguments($actionKind, $arguments);
        } catch (Throwable $throwable) {
            return [
                'valid' => false,
                'error' => $throwable->getMessage(),
                'adapter' => null,
                'procedure' => null,
            ];
        }
        $procedure = $this->recall($description, $expected, $actionKind, $arguments);
        $simulation = $this->simulateCandidate($actionKind, $arguments);
        return [
            'valid' => ($simulation['feasible'] ?? false) === true,
            'error' => ($simulation['feasible'] ?? false) === true
                ? null
                : (string) ($simulation['reason'] ?? 'Candidate precondition is not satisfied.'),
            'adapter' => ['action_kind' => $actionKind] + self::ADAPTERS[$actionKind],
            'procedure' => $procedure,
            'forward_simulation' => $simulation,
        ];
    }

    /**
     * Cheap one-step simulation over installed adapter contracts. This does not
     * pretend to predict arbitrary worlds: it states the postcondition the
     * fixed adapter can produce and checks every precondition visible now.
     *
     * @return array<string, mixed>
     */
    public function simulateCandidate(string $actionKind, array $arguments): array
    {
        $this->validateArguments($actionKind, $arguments);
        if ($actionKind === 'memory.search') {
            return [
                'feasible' => true,
                'confidence' => 1.0,
                'predicted' => ['result_set' => 'bounded', 'working_memory_write' => true],
                'reason' => 'Read-only retrieval is locally available.',
            ];
        }
        if ($actionKind === 'working_memory.write') {
            $scope = (string) ($arguments['scope'] ?? 'shared');
            $feasible = $scope === 'shared' || preg_match('/^thread:\d+$/', $scope) === 1;
            return [
                'feasible' => $feasible,
                'confidence' => $feasible ? 1.0 : 0.0,
                'predicted' => ['scope' => $scope, 'slot_role' => $arguments['role']],
                'reason' => $feasible ? 'The bounded workspace accepts this scope.' : 'Working-memory scope is invalid.',
            ];
        }
        if ($actionKind === 'memory.consolidate') {
            $episode = Memory::getByID((int) $arguments['episode_id']);
            $feasible = $episode instanceof Memory
                && $episode->tier === 'episodic'
                && $episode->status === 'active';
            return [
                'feasible' => $feasible,
                'confidence' => $feasible ? 1.0 : 0.0,
                'predicted' => [
                    'new_memory_tier' => 'semantic',
                    'source_memory_id' => (int) $arguments['episode_id'],
                ],
                'reason' => $feasible
                    ? 'The source episode exists and can ground a semantic write.'
                    : 'Learning requires an active episodic source memory.',
            ];
        }
        if ($actionKind === 'machine.look') {
            $source = SensorySource::getByField('source_key', 'machine_inspection');
            $feasible = $source instanceof SensorySource
                && $source->status === 'active'
                && $source->effect_ceiling === 'observe';
            return [
                'feasible' => $feasible,
                'confidence' => $feasible ? 0.8 : 0.0,
                'predicted' => ['request_queued' => true, 'feedback' => 'sandboxed machine observation'],
                'reason' => $feasible
                    ? 'The user-authorized observe-only source is active.'
                    : 'The user-authorized machine inspection source is unavailable.',
            ];
        }
        return ['feasible' => false, 'confidence' => 0.0, 'predicted' => [], 'reason' => 'No simulation exists.'];
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $status = null): array
    {
        if ($status !== null && !in_array($status, ['active', 'invalidated'], true)) {
            throw new InvalidArgumentException('procedure status must be active or invalidated.');
        }
        $records = $status === null
            ? Procedure::getAll(['order' => ['updated_at' => 'DESC']])
            : Procedure::getAllByWhere(['status' => $status], ['order' => ['updated_at' => 'DESC']]);
        return array_values(array_map(static fn (Procedure $procedure): array => $procedure->getData(), $records));
    }

    /**
     * Attach typed execution state to a trace before an adapter is dispatched.
     *
     * @return array<string, mixed>
     */
    public function recordExecution(
        ActionTrace $action,
        string $actionKind,
        array $arguments,
        ?int $procedureId = null,
        ?int $procedureRunId = null,
        ?int $stepIndex = null,
        ?array $verifier = null
    ): array {
        $this->validateArguments($actionKind, $arguments);
        if ($procedureId !== null) {
            $this->requireProcedure($procedureId);
        }
        if ($procedureRunId !== null && !ProcedureRun::getByID($procedureRunId) instanceof ProcedureRun) {
            throw new RuntimeException(sprintf('Procedure run %d does not exist.', $procedureRunId));
        }
        /** @var ActionExecution $execution */
        $execution = $this->core->insertRecord(ActionExecution::class, [
            'action_trace_id' => (int) $action->id,
            'procedure_id' => $procedureId,
            'procedure_run_id' => $procedureRunId,
            'step_index' => $stepIndex,
            'action_kind' => $actionKind,
            'arguments' => $arguments,
            'verifier' => $verifier ?? self::ADAPTERS[$actionKind]['verifier'],
            'observed' => [],
            'verified' => 0,
            'status' => 'pending',
            'updated_at' => time(),
        ]);
        return $execution->getData();
    }

    /** Store the machine-readable observation before the ordinary trace closes. */
    public function recordObserved(int $actionId, array $observed, bool $verified, string $status): void
    {
        $execution = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$execution instanceof ActionExecution) {
            return;
        }
        $execution->setFields([
            'observed' => $observed,
            'verified' => $verified ? 1 : 0,
            'status' => $status,
            'completed_at' => in_array($status, ['succeeded', 'failed', 'cancelled'], true) ? time() : null,
            'updated_at' => time(),
        ]);
        $execution->save();
    }

    /** @return array<string, mixed>|null */
    public function observe(ActionTrace $action, Event $event, Memory $episode): ?array
    {
        $execution = ActionExecution::getByField('action_trace_id', (int) $action->id);
        if (!$execution instanceof ActionExecution) {
            return $this->observeLegacy($action, $event, $episode);
        }

        $shape = $this->typedShape($action, $execution);
        $existing = $this->findDefinition($shape['key']);
        if ($action->status !== 'succeeded' || $action->match_status !== 'matched' || (int) $execution->verified !== 1) {
            if ($existing instanceof Procedure && $existing->status === 'active') {
                $this->invalidate(
                    $existing,
                    (int) $action->id,
                    'A typed execution no longer satisfied its installed postcondition.'
                );
            }
            return null;
        }

        $streak = [];
        foreach (ActionTrace::getAll(['order' => ['id' => 'DESC'], 'limit' => 300]) as $candidate) {
            $candidateExecution = ActionExecution::getByField('action_trace_id', (int) $candidate->id);
            if (!$candidateExecution instanceof ActionExecution
                || $this->typedShape($candidate, $candidateExecution)['key'] !== $shape['key']
            ) {
                continue;
            }
            if ($candidate->status !== 'succeeded'
                || $candidate->match_status !== 'matched'
                || (int) $candidateExecution->verified !== 1
            ) {
                break;
            }
            $streak[] = (int) $candidate->id;
        }
        if (count($streak) < self::SUCCESS_STREAK) {
            return null;
        }

        $intention = Intention::getByID((int) $action->intention_id);
        $authority = $intention instanceof Intention ? (string) $intention->authority : 'agent';
        $kind = (string) $execution->action_kind;
        $confidence = min(0.99, 0.7 + (0.05 * count($streak)));
        $step = [
            'action_kind' => $kind,
            'description' => (string) $action->description,
            'expected' => (string) $action->expected,
            'arguments' => is_array($execution->arguments) ? $execution->arguments : [],
            'verifier' => is_array($execution->verifier) ? $execution->verifier : [],
            'source_procedure_id' => null,
        ];
        $content = implode("\n", [
            sprintf('[procedure key=%s adapter=%s]', $shape['key'], $kind),
            'Name: ' . mb_substr((string) $action->description, 0, 160),
            'Postcondition: ' . (string) $action->expected,
            'Evidence: consecutive verified action traces ' . implode(', ', $streak),
            'Authority: executable only through installed adapter ' . $kind . '.',
        ]);

        if ($existing instanceof Procedure) {
            $memory = Memory::getByID((int) $existing->memory_id);
            if (!$memory instanceof Memory) {
                throw new RuntimeException('Procedure points to missing procedural memory.');
            }
            $memory->setFields([
                'content' => $content,
                'confidence' => $confidence,
                'status' => 'active',
                'source_event_id' => (int) $event->id,
                'source_memory_id' => (int) $episode->id,
                'updated_at' => time(),
            ]);
            $memory->save();
            $existing->setFields([
                'name' => mb_substr((string) $action->description, 0, 160),
                'description' => (string) $action->description,
                'steps' => [$step],
                'input_schema' => $this->schemaForArguments($step['arguments']),
                'effect_ceiling' => self::ADAPTERS[$kind]['effect'],
                'authority' => $authority,
                'status' => 'active',
                'evidence_action_ids' => $streak,
                'success_streak' => count($streak),
                'invalidated_at' => null,
                'invalidation_reason' => null,
                'source_event_id' => (int) $event->id,
                'updated_at' => time(),
            ]);
            $existing->save();
            $procedure = $existing;
            $created = false;
        } else {
            /** @var Memory $memory */
            $memory = $this->core->insertRecord(Memory::class, [
                'tier' => 'procedural',
                'content' => $content,
                'confidence' => $confidence,
                'status' => 'active',
                'source_event_id' => (int) $event->id,
                'source_memory_id' => (int) $episode->id,
                'updated_at' => time(),
            ]);
            /** @var Procedure $procedure */
            $procedure = $this->core->insertRecord(Procedure::class, [
                'memory_id' => (int) $memory->id,
                'procedure_key' => $shape['key'],
                'name' => mb_substr((string) $action->description, 0, 160),
                'description' => (string) $action->description,
                'steps' => [$step],
                'input_schema' => $this->schemaForArguments($step['arguments']),
                'effect_ceiling' => self::ADAPTERS[$kind]['effect'],
                'authority' => $authority,
                'status' => 'active',
                'evidence_action_ids' => $streak,
                'success_streak' => count($streak),
                'failure_count' => 0,
                'execution_count' => 0,
                'source_event_id' => (int) $event->id,
                'updated_at' => time(),
            ]);
            $created = true;
        }

        $procedureEvent = $this->core->emitEvent('procedure.compiled', [
            'procedure_id' => (int) $procedure->id,
            'memory_id' => (int) $memory->id,
            'procedure_key' => $shape['key'],
            'action_kind' => $kind,
            'action_ids' => $streak,
            'success_streak' => count($streak),
            'created' => $created,
            'effect_ceiling' => self::ADAPTERS[$kind]['effect'],
            'adapter_installed' => true,
        ]);

        return [
            'procedure' => $procedure->getData(),
            'memory' => $memory->getData(),
            'event' => $procedureEvent,
            'created' => $created,
        ];
    }

    /** @return array<string, mixed>|null */
    public function recall(
        string $description,
        string $expected,
        ?string $actionKind = null,
        array $arguments = []
    ): ?array {
        if ($actionKind !== null) {
            $this->validateArguments($actionKind, $arguments);
            $shape = $this->typedShapeFromValues($description, $expected, $actionKind, $arguments);
            $procedure = $this->findDefinition($shape['key'], true);
            if (!$procedure instanceof Procedure) {
                return null;
            }
            $memory = Memory::getByID((int) $procedure->memory_id);
            if (!$memory instanceof Memory || $memory->status !== 'active') {
                return null;
            }
            return [
                'procedure_id' => (int) $procedure->id,
                'memory_id' => (int) $memory->id,
                'procedure_key' => (string) $procedure->procedure_key,
                'content' => (string) $memory->content,
                'confidence' => (float) $memory->confidence,
                'action_kind' => $actionKind,
                'effect_ceiling' => (string) $procedure->effect_ceiling,
                'authorizes_execution' => true,
            ];
        }

        $shape = $this->legacyShape($description, $expected);
        $memory = $this->findLegacy($shape['key']);
        if (!$memory instanceof Memory) {
            return null;
        }
        return [
            'procedure_id' => null,
            'memory_id' => (int) $memory->id,
            'procedure_key' => $shape['key'],
            'content' => (string) $memory->content,
            'confidence' => (float) $memory->confidence,
            'action_kind' => null,
            'effect_ceiling' => null,
            'authorizes_execution' => false,
        ];
    }

    /**
     * Execute one installed action adapter and close its trace automatically.
     *
     * @return array<string, mixed>
     */
    public function executeAction(
        int $intentionId,
        string $actionKind,
        array $arguments,
        string $description,
        string $expected,
        ?int $procedureId = null,
        ?int $procedureRunId = null,
        ?int $stepIndex = null,
        ?array $verifier = null
    ): array {
        $this->validateArguments($actionKind, $arguments);
        $started = $this->core->startAction(
            $intentionId,
            $description,
            $expected,
            $actionKind,
            $arguments,
            $procedureId,
            $procedureRunId,
            $stepIndex,
            $verifier
        );
        $actionId = (int) ($started['action']['id'] ?? 0);

        try {
            $dispatch = $this->dispatch(
                $intentionId,
                $actionId,
                $actionKind,
                $arguments,
                $procedureRunId,
                $stepIndex,
                $verifier ?? self::ADAPTERS[$actionKind]['verifier']
            );
        } catch (Throwable $throwable) {
            $observed = ['error' => $throwable->getMessage()];
            $this->recordObserved($actionId, $observed, false, 'failed');
            $finished = $this->core->finishAction(
                $actionId,
                'failed',
                $this->encode($observed),
                false,
                'Adapter dispatch failed: ' . $throwable->getMessage()
            );
            return ['status' => 'failed', 'started' => $started, 'finished' => $finished];
        }

        if (($dispatch['status'] ?? null) === 'waiting') {
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if ($execution instanceof ActionExecution) {
                $execution->setFields([
                    'dispatch_event_id' => $dispatch['dispatch_event_id'] ?? null,
                    'status' => 'waiting',
                    'updated_at' => time(),
                ]);
                $execution->save();
            }
            return ['status' => 'waiting', 'started' => $started, 'dispatch' => $dispatch];
        }

        $observed = is_array($dispatch['observed'] ?? null) ? $dispatch['observed'] : [];
        $matched = ($dispatch['matched'] ?? false) === true;
        $status = $matched ? 'succeeded' : 'failed';
        $this->recordObserved($actionId, $observed, $matched, $status);
        $finished = $this->core->finishAction(
            $actionId,
            $status,
            $this->encode($observed),
            $matched,
            $matched ? 'No repair required; installed postcondition verified.' : 'Installed postcondition failed.'
        );
        return ['status' => $status, 'started' => $started, 'dispatch' => $dispatch, 'finished' => $finished];
    }

    /** @return array<string, mixed> */
    public function run(int $procedureId, int $intentionId, array $arguments = []): array
    {
        $procedure = $this->requireProcedure($procedureId);
        if ($procedure->status !== 'active') {
            throw new RuntimeException('An invalidated procedure cannot run.');
        }
        $intention = Intention::getByID($intentionId);
        if (!$intention instanceof Intention || $intention->status !== 'active') {
            throw new RuntimeException('Procedures can only run for an active intention.');
        }
        $this->validateRuntimeInput($procedure, $arguments);
        $this->validateComposition($procedure);

        /** @var ProcedureRun $run */
        $run = $this->core->insertRecord(ProcedureRun::class, [
            'procedure_id' => $procedureId,
            'intention_id' => $intentionId,
            'arguments' => $arguments,
            'current_step' => 0,
            'results' => [],
            'action_trace_ids' => [],
            'status' => 'running',
            'updated_at' => time(),
        ]);
        $this->core->emitEvent('procedure.run.started', [
            'procedure_id' => $procedureId,
            'procedure_run_id' => (int) $run->id,
            'intention_id' => $intentionId,
            'step_count' => count(is_array($procedure->steps) ? $procedure->steps : []),
        ]);
        return $this->advanceRun($run);
    }

    /** @param list<int> $procedureIds
     *  @return array<string, mixed>
     */
    public function compose(
        string $name,
        string $description,
        array $procedureIds,
        string $authority
    ): array {
        $name = trim($name);
        $description = trim($description);
        if ($name === '' || $description === '') {
            throw new InvalidArgumentException('A composed procedure needs a name and description.');
        }
        if (!in_array($authority, ['user', 'developer'], true)) {
            throw new RuntimeException('Composing executable procedures requires user or developer authority.');
        }
        if (count($procedureIds) < 2) {
            throw new InvalidArgumentException('Composition requires at least two procedures.');
        }

        $steps = [];
        $evidence = [];
        $confidence = 1.0;
        $effect = 'observe';
        foreach ($procedureIds as $id) {
            $child = $this->requireProcedure((int) $id);
            if ($child->status !== 'active') {
                throw new RuntimeException(sprintf('Procedure %d is invalidated.', $id));
            }
            foreach ((array) $child->steps as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $step['source_procedure_id'] = (int) $child->id;
                $steps[] = $step;
            }
            $evidence = array_values(array_unique(array_merge($evidence, array_map('intval', (array) $child->evidence_action_ids))));
            $memory = Memory::getByID((int) $child->memory_id);
            $confidence = min($confidence, $memory instanceof Memory ? (float) $memory->confidence : 0.0);
            if ($this->effectRank((string) $child->effect_ceiling) > $this->effectRank($effect)) {
                $effect = (string) $child->effect_ceiling;
            }
        }
        if (count($steps) > self::MAX_COMPOSED_STEPS) {
            throw new InvalidArgumentException(sprintf(
                'A composed procedure may contain at most %d flattened steps.',
                self::MAX_COMPOSED_STEPS
            ));
        }

        $key = hash('sha256', 'composition|' . $this->encode([$name, $steps]));
        $existing = $this->findDefinition($key);
        if ($existing instanceof Procedure) {
            return ['procedure' => $existing->getData(), 'created' => false];
        }
        /** @var Memory $memory */
        $memory = $this->core->insertRecord(Memory::class, [
            'tier' => 'procedural',
            'content' => implode("\n", [
                sprintf('[procedure key=%s composition]', $key),
                'Name: ' . mb_substr($name, 0, 160),
                'Description: ' . $description,
                'Steps: ' . implode(', ', array_map(static fn (array $step): string => (string) $step['action_kind'], $steps)),
                'Authority: explicitly composed by ' . $authority . '; execution remains adapter-bounded.',
            ]),
            'confidence' => $confidence,
            'status' => 'active',
            'updated_at' => time(),
        ]);
        /** @var Procedure $procedure */
        $procedure = $this->core->insertRecord(Procedure::class, [
            'memory_id' => (int) $memory->id,
            'procedure_key' => $key,
            'name' => mb_substr($name, 0, 160),
            'description' => $description,
            'steps' => $steps,
            'input_schema' => $this->unionInputSchemas($steps),
            'effect_ceiling' => $effect,
            'authority' => $authority,
            'status' => 'active',
            'evidence_action_ids' => $evidence,
            'success_streak' => min(array_map(
                static fn (int $id): int => (int) ($id > 0 ? Procedure::getByID($id)?->success_streak ?? 0 : 0),
                $procedureIds
            )),
            'failure_count' => 0,
            'execution_count' => 0,
            'updated_at' => time(),
        ]);
        $event = $this->core->emitEvent('procedure.composed', [
            'procedure_id' => (int) $procedure->id,
            'memory_id' => (int) $memory->id,
            'component_procedure_ids' => array_values(array_map('intval', $procedureIds)),
            'step_count' => count($steps),
            'effect_ceiling' => $effect,
            'authority' => $authority,
        ]);
        return ['procedure' => $procedure->getData(), 'memory' => $memory->getData(), 'event' => $event, 'created' => true];
    }

    /** Finish an asynchronous machine.look and resume any suspended composition. */
    public function completePendingLook(int $requestEventId, array $reading): ?array
    {
        $execution = ActionExecution::getByField('dispatch_event_id', $requestEventId);
        if (!$execution instanceof ActionExecution || $execution->status !== 'waiting') {
            return null;
        }
        $worked = ($reading['refused'] ?? false) !== true && ($reading['exit_code'] ?? null) === 0;
        $observed = [
            'refused' => ($reading['refused'] ?? false) === true,
            'exit_code' => $reading['exit_code'] ?? null,
            'timed_out' => ($reading['timed_out'] ?? false) === true,
            'output_digest' => $reading['output_digest'] ?? null,
            'output_bytes' => $reading['output_bytes'] ?? null,
        ];
        $actionId = (int) $execution->action_trace_id;
        $this->recordObserved($actionId, $observed, $worked, $worked ? 'succeeded' : 'failed');
        $finished = $this->core->finishAction(
            $actionId,
            $worked ? 'succeeded' : 'failed',
            $this->encode($observed),
            $worked,
            $worked ? 'No repair required; sandboxed look returned successfully.' : 'The sandboxed look did not return successfully.'
        );

        // A decision cycle may be waiting on this exact asynchronous action
        // even when no composed ProcedureRun is involved.
        $decisionCycle = $this->core->decisionStateMachine()->completeAsyncAction(
            $actionId,
            $worked,
            $finished
        );

        $runId = $execution->procedure_run_id === null ? null : (int) $execution->procedure_run_id;
        if ($runId === null) {
            return ['action' => $finished, 'procedure_run' => null, 'decision_cycle' => $decisionCycle];
        }
        $run = ProcedureRun::getByID($runId);
        if (!$run instanceof ProcedureRun || $run->status !== 'waiting') {
            return ['action' => $finished, 'procedure_run' => null, 'decision_cycle' => $decisionCycle];
        }
        $results = is_array($run->results) ? $run->results : [];
        $results[] = ['step' => (int) $run->current_step, 'action_id' => $actionId, 'status' => $worked ? 'succeeded' : 'failed'];
        if (!$worked) {
            return [
                'action' => $finished,
                'procedure_run' => $this->failRun($run, $results, 'Asynchronous step postcondition failed.'),
                'decision_cycle' => $decisionCycle,
            ];
        }
        $run->setFields([
            'current_step' => (int) $run->current_step + 1,
            'results' => $results,
            'status' => 'running',
            'updated_at' => time(),
        ]);
        $run->save();
        return [
            'action' => $finished,
            'procedure_run' => $this->advanceRun($run),
            'decision_cycle' => $decisionCycle,
        ];
    }

    /** @return array<string, mixed> */
    private function advanceRun(ProcedureRun $run): array
    {
        $procedure = $this->requireProcedure((int) $run->procedure_id);
        $steps = is_array($procedure->steps) ? $procedure->steps : [];
        $results = is_array($run->results) ? $run->results : [];
        $actionIds = is_array($run->action_trace_ids) ? array_map('intval', $run->action_trace_ids) : [];

        while ((int) $run->current_step < count($steps)) {
            $index = (int) $run->current_step;
            $step = $steps[$index] ?? null;
            if (!is_array($step)) {
                return $this->failRun($run, $results, 'Procedure contains a malformed step.');
            }
            $sourceProcedureId = isset($step['source_procedure_id']) && $step['source_procedure_id'] !== null
                ? (int) $step['source_procedure_id']
                : (int) $procedure->id;
            $defaults = is_array($step['arguments'] ?? null) ? $step['arguments'] : [];
            $runtime = is_array($run->arguments) ? $run->arguments : [];
            // A composite exposes the union of its inputs, but each adapter
            // receives only the names belonging to its own step.
            $arguments = array_replace($defaults, array_intersect_key($runtime, $defaults));
            $outcome = $this->executeAction(
                (int) $run->intention_id,
                (string) ($step['action_kind'] ?? ''),
                $arguments,
                (string) ($step['description'] ?? $procedure->description),
                (string) ($step['expected'] ?? 'Installed postcondition verifies.'),
                $sourceProcedureId,
                (int) $run->id,
                $index,
                is_array($step['verifier'] ?? null) ? $step['verifier'] : null
            );
            $actionId = (int) ($outcome['started']['action']['id'] ?? 0);
            if ($actionId > 0) {
                $actionIds[] = $actionId;
            }
            $run->setField('action_trace_ids', array_values(array_unique($actionIds)));

            if (($outcome['status'] ?? null) === 'waiting') {
                $run->setFields(['status' => 'waiting', 'updated_at' => time()]);
                $run->save();
                return ['status' => 'waiting', 'procedure' => $procedure->getData(), 'run' => $run->getData(), 'step' => $outcome];
            }
            $results[] = ['step' => $index, 'action_id' => $actionId, 'status' => $outcome['status'] ?? 'failed'];
            if (($outcome['status'] ?? null) !== 'succeeded') {
                return $this->failRun($run, $results, sprintf('Procedure step %d failed.', $index));
            }
            $run->setFields([
                'current_step' => $index + 1,
                'results' => $results,
                'action_trace_ids' => array_values(array_unique($actionIds)),
                'updated_at' => time(),
            ]);
            $run->save();
        }

        $now = time();
        $run->setFields(['status' => 'succeeded', 'completed_at' => $now, 'updated_at' => $now]);
        $run->save();
        $procedure->setFields([
            'execution_count' => (int) $procedure->execution_count + 1,
            'last_executed_at' => $now,
            'updated_at' => $now,
        ]);
        $procedure->save();
        $event = $this->core->emitEvent('procedure.run.finished', [
            'procedure_id' => (int) $procedure->id,
            'procedure_run_id' => (int) $run->id,
            'status' => 'succeeded',
            'steps_completed' => count($results),
            'action_trace_ids' => $actionIds,
        ]);
        return ['status' => 'succeeded', 'procedure' => $procedure->getData(), 'run' => $run->getData(), 'event' => $event];
    }

    /** @return array<string, mixed> */
    private function failRun(ProcedureRun $run, array $results, string $reason): array
    {
        $now = time();
        $run->setFields([
            'status' => 'failed',
            'results' => $results,
            'error' => $reason,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
        $run->save();
        $procedure = $this->requireProcedure((int) $run->procedure_id);
        if ($procedure->status === 'active') {
            $this->invalidate($procedure, null, 'Composed execution failed: ' . $reason);
        }
        $event = $this->core->emitEvent('procedure.run.finished', [
            'procedure_id' => (int) $procedure->id,
            'procedure_run_id' => (int) $run->id,
            'status' => 'failed',
            'reason' => $reason,
        ]);
        return ['status' => 'failed', 'procedure' => $procedure->getData(), 'run' => $run->getData(), 'event' => $event];
    }

    /** @return array<string, mixed> */
    private function dispatch(
        int $intentionId,
        int $actionId,
        string $kind,
        array $arguments,
        ?int $runId,
        ?int $stepIndex,
        array $verifier
    ): array {
        if ($kind === 'memory.search') {
            $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 20;
            $limit = max(1, min(100, $limit));
            // Retrieval reads long-term memory into working memory. The generic
            // search surface also exposes working projections for operators,
            // so deliberately filter those out here and over-fetch enough that
            // a busy workspace cannot crowd episodic/semantic/procedural rows
            // out of the bounded result set.
            $rows = array_values(array_filter(
                $this->core->searchMemory((string) $arguments['query'], min(400, max(20, $limit * 4))),
                static fn (array $row): bool => ($row['tier'] ?? null) !== 'working'
            ));
            $rows = array_slice($rows, 0, $limit);
            $observed = [
                'result_count' => count($rows),
                'memory_ids' => array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows)),
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
            $stored = $this->core->workingMemory()->publish(
                role: (string) $arguments['role'],
                claim: (string) $arguments['claim'],
                recordType: (string) ($arguments['record_type'] ?? 'reasoning'),
                recordId: isset($arguments['record_id']) ? (int) $arguments['record_id'] : null,
                confidence: isset($arguments['confidence']) ? (float) $arguments['confidence'] : 0.7,
                ttlSeconds: isset($arguments['ttl_seconds']) ? (int) $arguments['ttl_seconds'] : 900,
                scope: $scope,
                threadId: $threadId
            );
            $matched = (string) ($stored['claim'] ?? '') === trim((string) $arguments['claim']);
            return ['status' => 'completed', 'matched' => $matched, 'observed' => ['slot' => $stored]];
        }

        if ($kind === 'memory.consolidate') {
            $episodeId = (int) $arguments['episode_id'];
            $confidence = (float) $arguments['confidence'];
            if ($confidence < 0.0 || $confidence > 1.0) {
                throw new InvalidArgumentException('memory.consolidate confidence must be between 0 and 1.');
            }
            $stored = $this->core->consolidateMemory(
                $episodeId,
                (string) $arguments['content'],
                $confidence
            );
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
            $source = SensorySource::getByField('source_key', 'machine_inspection');
            if (!$source instanceof SensorySource
                || $source->status !== 'active'
                || $source->effect_ceiling !== 'observe'
            ) {
                throw new RuntimeException('The user-authorized machine inspection source is not active.');
            }
            $queued = $this->core->requestLook(
                (string) $arguments['command'],
                (string) $arguments['because'],
                $intentionId,
                $actionId,
                $runId,
                $stepIndex
            );
            return [
                'status' => 'waiting',
                'dispatch_event_id' => (int) ($queued['event']['id'] ?? 0),
                'observed' => ['queued' => true],
            ];
        }

        throw new RuntimeException(sprintf('No installed adapter for action kind %s.', $kind));
    }

    private function invalidate(Procedure $procedure, ?int $actionId, string $reason): void
    {
        $now = time();
        $procedure->setFields([
            'status' => 'invalidated',
            'failure_count' => (int) $procedure->failure_count + 1,
            'invalidated_at' => $now,
            'invalidation_reason' => $reason,
            'updated_at' => $now,
        ]);
        $procedure->save();
        $memory = Memory::getByID((int) $procedure->memory_id);
        if ($memory instanceof Memory) {
            $memory->setFields(['status' => 'expired', 'updated_at' => $now]);
            $memory->save();
        }
        $this->core->emitEvent('procedure.invalidated', [
            'procedure_id' => (int) $procedure->id,
            'memory_id' => $memory instanceof Memory ? (int) $memory->id : null,
            'action_id' => $actionId,
            'procedure_key' => (string) $procedure->procedure_key,
            'reason' => $reason,
        ]);
    }

    private function validateComposition(Procedure $procedure): void
    {
        foreach ((array) $procedure->steps as $step) {
            if (!is_array($step) || !isset(self::ADAPTERS[(string) ($step['action_kind'] ?? '')])) {
                throw new RuntimeException('Procedure references an adapter that is not installed.');
            }
            $childId = isset($step['source_procedure_id']) && $step['source_procedure_id'] !== null
                ? (int) $step['source_procedure_id']
                : null;
            if ($childId !== null) {
                $child = Procedure::getByID($childId);
                if (!$child instanceof Procedure || $child->status !== 'active') {
                    $this->invalidate($procedure, null, sprintf('Component procedure %d is not active.', $childId));
                    throw new RuntimeException(sprintf('Component procedure %d is not active.', $childId));
                }
            }
        }
    }

    private function validateArguments(string $kind, array $arguments): void
    {
        $adapter = self::ADAPTERS[$kind] ?? null;
        if ($adapter === null) {
            throw new InvalidArgumentException(sprintf(
                'action kind must be one of: %s.',
                implode(', ', array_keys(self::ADAPTERS))
            ));
        }
        $allowed = $adapter['required'] + $adapter['optional'];
        foreach ($adapter['required'] as $name => $type) {
            if (!array_key_exists($name, $arguments)) {
                throw new InvalidArgumentException(sprintf('%s requires argument %s.', $kind, $name));
            }
            $this->validateType($name, $arguments[$name], $type);
            if ($type === 'string' && trim((string) $arguments[$name]) === '') {
                throw new InvalidArgumentException(sprintf('%s argument %s cannot be empty.', $kind, $name));
            }
        }
        foreach ($arguments as $name => $value) {
            if (!is_string($name) || !isset($allowed[$name])) {
                throw new InvalidArgumentException(sprintf('%s does not accept argument %s.', $kind, (string) $name));
            }
            $this->validateType($name, $value, $allowed[$name]);
        }
        if ($kind === 'memory.consolidate') {
            $confidence = (float) ($arguments['confidence'] ?? -1.0);
            if ($confidence < 0.0 || $confidence > 1.0) {
                throw new InvalidArgumentException('memory.consolidate confidence must be between 0 and 1.');
            }
        }
    }

    /** @param list<string> $classes
     *  @return list<array<string, mixed>>
     */
    private function adaptersByClass(array $classes): array
    {
        return array_values(array_filter(
            $this->adapters(),
            static fn (array $adapter): bool => in_array((string) $adapter['action_class'], $classes, true)
        ));
    }

    private function validateType(string $name, mixed $value, string $type): void
    {
        $valid = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException(sprintf('argument %s must be %s.', $name, $type));
        }
    }

    private function validateRuntimeInput(Procedure $procedure, array $arguments): void
    {
        $schema = is_array($procedure->input_schema) ? $procedure->input_schema : [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($arguments as $name => $value) {
            $spec = $properties[$name] ?? null;
            if (!is_string($name) || !is_array($spec) || !is_string($spec['type'] ?? null)) {
                throw new InvalidArgumentException(sprintf('Procedure does not accept runtime argument %s.', (string) $name));
            }
            $this->validateType($name, $value, (string) $spec['type']);
        }
    }

    /** @return array<string, mixed> */
    private function schemaForArguments(array $arguments): array
    {
        $properties = [];
        foreach ($arguments as $name => $value) {
            $properties[(string) $name] = ['type' => $this->typeOf($value)];
        }
        ksort($properties);
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    /** @param list<array<string, mixed>> $steps
     *  @return array<string, mixed>
     */
    private function unionInputSchemas(array $steps): array
    {
        $properties = [];
        foreach ($steps as $step) {
            foreach ((array) ($step['arguments'] ?? []) as $name => $value) {
                $type = $this->typeOf($value);
                if (isset($properties[$name]) && ($properties[$name]['type'] ?? null) !== $type) {
                    throw new InvalidArgumentException(sprintf(
                        'Cannot compose procedures whose argument %s has conflicting types.',
                        (string) $name
                    ));
                }
                $properties[(string) $name] = ['type' => $type];
            }
        }
        ksort($properties);
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            default => throw new InvalidArgumentException('Procedure arguments must be scalar JSON values.'),
        };
    }

    /** @return array{key: string} */
    private function typedShape(ActionTrace $action, ActionExecution $execution): array
    {
        return $this->typedShapeFromValues(
            (string) $action->description,
            (string) $action->expected,
            (string) $execution->action_kind,
            is_array($execution->arguments) ? $execution->arguments : [],
            is_array($execution->verifier) ? $execution->verifier : []
        );
    }

    /** @return array{key: string} */
    private function typedShapeFromValues(
        string $description,
        string $expected,
        string $actionKind,
        array $arguments,
        ?array $verifier = null
    ): array {
        $shape = [
            'action_kind' => $actionKind,
            'description' => $this->normalize($description),
            'expected' => $this->normalize($expected),
            'argument_schema' => $this->schemaForArguments($arguments),
            'verifier' => $verifier ?? self::ADAPTERS[$actionKind]['verifier'],
        ];
        return ['key' => hash('sha256', $this->encode($shape))];
    }

    private function findDefinition(string $key, bool $activeOnly = false): ?Procedure
    {
        $procedure = Procedure::getByField('procedure_key', $key);
        if (!$procedure instanceof Procedure || ($activeOnly && $procedure->status !== 'active')) {
            return null;
        }
        return $procedure;
    }

    private function requireProcedure(int $id): Procedure
    {
        $procedure = Procedure::getByID($id);
        if (!$procedure instanceof Procedure) {
            throw new RuntimeException(sprintf('Procedure %d does not exist.', $id));
        }
        return $procedure;
    }

    /** @return array<string, mixed>|null */
    private function observeLegacy(ActionTrace $action, Event $event, Memory $episode): ?array
    {
        $shape = $this->legacyShape((string) $action->description, (string) $action->expected);
        $existing = $this->findLegacy($shape['key']);
        if ($action->status !== 'succeeded' || $action->match_status !== 'matched') {
            if ($existing instanceof Memory) {
                $existing->setFields(['status' => 'expired', 'updated_at' => time()]);
                $existing->save();
                $this->core->emitEvent('procedure.invalidated', [
                    'memory_id' => (int) $existing->id,
                    'action_id' => (int) $action->id,
                    'procedure_key' => $shape['key'],
                    'reason' => 'Legacy advisory action no longer matched its expectation.',
                ]);
            }
            return null;
        }

        $streak = [];
        foreach (ActionTrace::getAll(['order' => ['id' => 'DESC'], 'limit' => 300]) as $candidate) {
            if (ActionExecution::getByField('action_trace_id', (int) $candidate->id) instanceof ActionExecution) {
                continue;
            }
            if ($this->legacyShape((string) $candidate->description, (string) $candidate->expected)['key'] !== $shape['key']) {
                continue;
            }
            if ($candidate->status !== 'succeeded' || $candidate->match_status !== 'matched') {
                break;
            }
            $streak[] = (int) $candidate->id;
        }
        if (count($streak) < self::SUCCESS_STREAK) {
            return null;
        }
        $content = implode("\n", [
            sprintf('[procedure action-shape=%s]', $shape['key']),
            'When: ' . $shape['description'],
            'Expect: ' . $shape['expected'],
            'Evidence: consecutive matched action traces ' . implode(', ', $streak),
            'Authority: advisory only; no typed adapter was observed.',
        ]);
        $confidence = min(0.99, 0.7 + (0.05 * count($streak)));
        if ($existing instanceof Memory) {
            $existing->setFields([
                'content' => $content,
                'confidence' => $confidence,
                'source_event_id' => (int) $event->id,
                'source_memory_id' => (int) $episode->id,
                'updated_at' => time(),
            ]);
            $existing->save();
            $memory = $existing;
            $created = false;
        } else {
            /** @var Memory $memory */
            $memory = $this->core->insertRecord(Memory::class, [
                'tier' => 'procedural',
                'content' => $content,
                'confidence' => $confidence,
                'status' => 'active',
                'source_event_id' => (int) $event->id,
                'source_memory_id' => (int) $episode->id,
                'updated_at' => time(),
            ]);
            $created = true;
        }
        $procedureEvent = $this->core->emitEvent('procedure.compiled', [
            'memory_id' => (int) $memory->id,
            'procedure_key' => $shape['key'],
            'action_ids' => $streak,
            'success_streak' => count($streak),
            'created' => $created,
            'adapter_installed' => false,
        ]);
        return ['memory' => $memory->getData(), 'event' => $procedureEvent, 'created' => $created];
    }

    /** @return array{key: string, description: string, expected: string} */
    private function legacyShape(string $description, string $expected): array
    {
        $description = $this->normalize($description);
        $expected = $this->normalize($expected);
        return [
            'key' => hash('sha256', $description . "\n" . $expected),
            'description' => $description,
            'expected' => $expected,
        ];
    }

    private function findLegacy(string $key): ?Memory
    {
        foreach (Memory::getAllByWhere(
            ['tier' => 'procedural', 'status' => 'active'],
            ['order' => ['updated_at' => 'DESC']]
        ) as $memory) {
            if (preg_match(self::LEGACY_MARKER, (string) $memory->content, $matches) === 1
                && hash_equals($key, (string) $matches[1])
            ) {
                return $memory;
            }
        }
        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\b[0-9a-f]{12,}\b/i', '<token>', $value) ?? $value;
        $value = preg_replace('/\b\d+(?:\.\d+)?\b/', '#', $value) ?? $value;
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function effectRank(string $effect): int
    {
        return match ($effect) {
            'observe' => 0,
            'think' => 1,
            'prepare' => 2,
            'act' => 3,
            default => 4,
        };
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
