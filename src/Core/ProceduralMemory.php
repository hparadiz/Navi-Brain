<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use Divergence\IO\Database\Connections;
use InvalidArgumentException;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Event;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\Procedure;
use NaviBrain\Model\ProcedureRun;
use NaviBrain\Model\SensorySource;
use NaviBrain\Storage\TokenMemoryDaemon;
use PDO;
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

    /** @return array<string, mixed> */
    public function verifierFor(string $actionKind): array
    {
        if (!isset(self::ADAPTERS[$actionKind])) {
            throw new InvalidArgumentException('No installed verifier exists for this action kind.');
        }
        return self::ADAPTERS[$actionKind]['verifier'];
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
            $episode = Memory::inspectByID((int) $arguments['episode_id']);
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
        ?int $procedureMemoryId = null,
        ?int $procedureRunId = null,
        ?int $stepIndex = null,
        ?array $verifier = null
    ): array {
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
            $expectedArguments = array_replace(
                $stepDefaults,
                array_intersect_key($runArguments, $stepDefaults)
            );
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
        $execution = $this->core->insertRecord(ActionExecution::class, [
            'action_trace_id' => (int) $action->id,
            'procedure_id' => $procedureId,
            'procedure_memory_id' => $procedureMemoryId,
            'procedure_run_id' => $procedureRunId,
            // Divergence maps nullable integer zero to null. Persist a
            // one-based ordinal so procedure step zero remains identifiable.
            'step_index' => $stepIndex === null ? null : $stepIndex + 1,
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
        $this->core->finalizeActionExecution($actionId, $observed, $verified, $status);
    }

    /** @return array<string, mixed> */
    private function finishDurableExecution(int $actionId): array
    {
        $execution = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$execution instanceof ActionExecution
            || !in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)
        ) {
            throw new RuntimeException('Action execution has no durable terminal outcome to finish.');
        }
        $observed = is_array($execution->observed) ? $execution->observed : [];
        $matched = $execution->status === 'succeeded' && (int) $execution->verified === 1;
        // Re-prove the exact terminal execution and upgrade any pre-v21
        // three-column dispatch claim into a complete outcome receipt before
        // the human-readable ActionTrace is allowed to finish.
        $this->core->finalizeActionExecution(
            $actionId,
            $observed,
            (int) $execution->verified === 1,
            (string) $execution->status
        );
        if ((string) $execution->action_kind === 'machine.look') {
            $repairNote = $matched
                ? 'No repair required; sandboxed look returned successfully.'
                : 'The sandboxed look did not return successfully.';
        } elseif (isset($observed['error']) && is_string($observed['error'])) {
            $repairNote = 'Adapter dispatch failed: ' . $observed['error'];
        } else {
            $repairNote = $matched
                ? 'No repair required; installed postcondition verified.'
                : 'Installed postcondition failed.';
        }
        return $this->core->finishAction(
            $actionId,
            (string) $execution->status,
            $this->encode($observed),
            $matched,
            $repairNote
        );
    }

    private function executionAuthorizationActive(int $actionId): bool
    {
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

    /** @return array<string, mixed>|null */
    public function observe(ActionTrace $action, Event $event, Memory $episode): ?array
    {
        $execution = ActionExecution::getByField('action_trace_id', (int) $action->id);
        if (!$execution instanceof ActionExecution) {
            return $this->observeLegacy($action, $event, $episode);
        }

        $shape = $this->typedShape($action, $execution);
        $existing = $this->findDefinition($shape['key']);
        $guard = $this->procedureGeneration($shape['key']);
        $replayingPendingGeneration = $guard !== null
            && $guard['pending_generation'] === (int) $action->id;
        if (!$replayingPendingGeneration
            && $existing instanceof Procedure
            && $existing->status === 'active'
            && (int) ($existing->source_event_id ?? 0) === (int) $event->id
        ) {
            $existingMemory = Memory::inspectByID((int) $existing->memory_id);
            if ($existingMemory instanceof Memory
                && $existingMemory->status === 'active'
                && (int) ($existingMemory->source_event_id ?? 0) === (int) $event->id
            ) {
                $existingGeneration = $this->generation(
                    is_array($existing->evidence_action_ids) ? $existing->evidence_action_ids : []
                );
                if (!$this->canReplayProcedureGeneration($shape['key'], $existingGeneration)) {
                    $existingMemory = null;
                }
            } else {
                $existingMemory = null;
            }
            if ($existingMemory instanceof Memory) {
                $compiled = $this->emitTypedCompiled(
                    $event,
                    $existing,
                    $existingMemory,
                    $shape['key']
                );
                $this->commitProcedureGeneration($shape['key'], $existingGeneration);
                return [
                    'procedure' => $existing->getData(),
                    'memory' => $existingMemory->getData(),
                    'event' => $compiled,
                    'created' => false,
                    'deduplicated' => true,
                ];
            }
        }
        if ($action->status !== 'succeeded' || $action->match_status !== 'matched' || (int) $execution->verified !== 1) {
            $this->observeTypedFailure(
                $shape['key'],
                (int) $action->id,
                'A typed execution no longer satisfied its installed postcondition.'
            );
            return null;
        }

        $streak = [];
        foreach ($this->actionWindowThrough((int) $action->id) as $candidate) {
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
            'source_procedure_memory_id' => null,
        ];
        $content = implode("\n", [
            sprintf('[procedure key=%s adapter=%s]', $shape['key'], $kind),
            'Name: ' . mb_substr((string) $action->description, 0, 160),
            'Postcondition: ' . (string) $action->expected,
            'Evidence: consecutive verified action traces ' . implode(', ', $streak),
            'Authority: executable only through installed adapter ' . $kind . '.',
        ]);
        $eventTime = $this->eventTime($event);
        $generation = $this->generation($streak);
        $seedGeneration = $existing instanceof Procedure
            ? $this->generation(is_array($existing->evidence_action_ids) ? $existing->evidence_action_ids : [])
            : 0;
        $previousMemoryId = $existing instanceof Procedure ? (int) $existing->memory_id : null;
        if ($replayingPendingGeneration
            && $existing instanceof Procedure
            && (int) ($existing->source_event_id ?? 0) === (int) $event->id
            && $seedGeneration === $generation
        ) {
            $installedMemory = Memory::inspectByID((int) $existing->memory_id);
            if (!$installedMemory instanceof Memory) {
                throw new RuntimeException('Installed pending procedure memory disappeared.');
            }
            $previousMemoryId = $installedMemory->supersedes_id === null
                ? null
                : (int) $installedMemory->supersedes_id;
        }
        $pendingHash = hash('sha256', $this->encode([
            'kind' => 'typed',
            'source_event_id' => (int) $event->id,
            'source_memory_id' => (int) $episode->id,
            'previous_memory_id' => $previousMemoryId,
            'content' => $content,
            'confidence' => $confidence,
            'event_time' => $eventTime,
            'action_ids' => $streak,
        ]));
        $generationClaim = $this->claimProcedureGeneration(
            $shape['key'],
            $generation,
            $pendingHash,
            $seedGeneration
        );
        if ($generationClaim === 'obsolete') {
            $existing = $this->findDefinition($shape['key']);
            $guard = $this->procedureGeneration($shape['key']);
            if (!$existing instanceof Procedure
                || $existing->status !== 'active'
                || $guard === null
                || $this->generation(
                    is_array($existing->evidence_action_ids) ? $existing->evidence_action_ids : []
                ) < $guard['current_generation']
            ) {
                return null;
            }
            $currentMemory = Memory::inspectByID((int) $existing->memory_id);
            $currentSource = Event::getByID((int) ($existing->source_event_id ?? 0));
            if (!$currentMemory instanceof Memory || !$currentSource instanceof Event) {
                throw new RuntimeException('Current typed procedure generation is incomplete.');
            }
            return [
                'procedure' => $existing->getData(),
                'memory' => $currentMemory->getData(),
                'event' => $this->emitTypedCompiled(
                    $currentSource,
                    $existing,
                    $currentMemory,
                    $shape['key']
                ),
                'created' => false,
                'deduplicated' => true,
                'obsolete_generation' => true,
            ];
        }
        if ($generationClaim === 'blocked') {
            throw new RuntimeException(
                'An earlier procedure generation must be replayed before this observation can advance it.'
            );
        }

        // The generation claim and token-memory mutation are deliberately separate
        // durability boundaries. Never continue from the definition read before the
        // claim: another observer may have completed while this observer waited for
        // SQLite's writer lock.
        $existing = $this->findDefinition($shape['key']);
        $installed = $this->reconcileInstalledTypedGeneration(
            $existing,
            $event,
            $shape['key'],
            $generation
        );
        if ($installed !== null) {
            return $installed;
        }

        if ($existing instanceof Procedure) {
            $previousMemoryId = (int) $existing->memory_id;
            $this->core->beginProcedureReplacement(
                (int) $existing->id,
                $previousMemoryId,
                $shape['key'],
                $generation,
                $pendingHash
            );
            $previousMemory = Memory::inspectByID($previousMemoryId);
            if (!$previousMemory instanceof Memory) {
                throw new RuntimeException('Procedure points to missing procedural memory.');
            }
            $memory = Memory::replaceRecord($previousMemory, [
                'tier' => 'procedural',
                'content' => $content,
                'confidence' => $confidence,
                'status' => 'active',
                'source_event_id' => (int) $event->id,
                'source_memory_id' => (int) $episode->id,
                'created_at' => $eventTime,
                'updated_at' => $eventTime,
            ], 'expired', TokenMemoryDaemon::operationKey(
                'procedure-observe-replace',
                (string) $event->id . ':' . $shape['key']
            ));
            $replacement = $this->core->finalizeProcedureReplacement(
                (int) $existing->id,
                $previousMemoryId,
                (int) $memory->id,
                $shape['key'],
                $generation,
                $pendingHash,
                [
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
                'updated_at' => $eventTime,
                ]
            );
            $procedure = Procedure::getByID((int) ($replacement['procedure']['id'] ?? 0));
            if (!$procedure instanceof Procedure) {
                throw new RuntimeException('Published procedure replacement disappeared.');
            }
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
                'created_at' => $eventTime,
                'updated_at' => $eventTime,
                'operation_key' => TokenMemoryDaemon::operationKey(
                    'procedure-observe-create',
                    (string) $event->id . ':' . $shape['key']
                ),
            ]);
            [$procedure, $created] = $this->installProcedure([
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
                'created_at' => $eventTime,
                'updated_at' => $eventTime,
            ]);
        }

        $procedureEvent = $this->emitTypedCompiled($event, $procedure, $memory, $shape['key']);
        $this->commitProcedureGeneration($shape['key'], $generation);

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
        $memory = $this->findLegacy($shape['key'], true);
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
        ?int $procedureMemoryId = null,
        ?int $procedureRunId = null,
        ?int $stepIndex = null,
        ?array $verifier = null,
        ?int $decisionCycleId = null
    ): array {
        $this->validateArguments($actionKind, $arguments);
        $started = $this->core->startAction(
            $intentionId,
            $description,
            $expected,
            $actionKind,
            $arguments,
            $procedureId,
            $procedureMemoryId,
            $procedureRunId,
            $stepIndex,
            $verifier,
            $decisionCycleId
        );
        $actionId = (int) ($started['action']['id'] ?? 0);
        $executionBeforeClaim = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$executionBeforeClaim instanceof ActionExecution) {
            throw new RuntimeException('Action execution disappeared before dispatch claim.');
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
                // A concurrent process won the unique dispatch claim. Reload
                // that durable owner/status instead of surfacing its expected
                // uniqueness race as an execution failure.
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
            $dispatch = $this->dispatch(
                $intentionId,
                $actionId,
                $actionKind,
                $arguments,
                $procedureRunId,
                $stepIndex,
                $verifier ?? self::ADAPTERS[$actionKind]['verifier'],
                $decisionCycleId
            );
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
                    throw new RuntimeException(
                        'Committed look dispatch does not match its durable request.',
                        previous: $throwable
                    );
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

    /** @return array<string, mixed> */
    public function run(
        int $procedureId,
        int $intentionId,
        array $arguments,
        string $invocationKey
    ): array
    {
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
            // This also completes a split-phase parent invalidation whose token
            // generation was retired before the SQLite CAS/event committed.
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

        $started = $this->core->startProcedureRun(
            $procedureId,
            (int) $procedureMemory->id,
            $intentionId,
            $arguments,
            count(is_array($procedure->steps) ? $procedure->steps : []),
            $operationKey
        );
        $run = ProcedureRun::getByID((int) ($started['run']['id'] ?? 0));
        if (!$run instanceof ProcedureRun) {
            throw new RuntimeException('Started procedure run disappeared.');
        }
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
        $successStreaks = [];
        $compositionTime = 0;
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
                $step['source_procedure_memory_id'] = (int) $child->memory_id;
                $steps[] = $step;
            }
            $evidence = array_values(array_unique(array_merge($evidence, array_map('intval', (array) $child->evidence_action_ids))));
            $childMemory = Memory::inspectByID((int) $child->memory_id);
            if (!$childMemory instanceof Memory || $childMemory->status !== 'active') {
                throw new RuntimeException(sprintf('Procedure %d has no active token-memory generation.', $id));
            }
            $confidence = min($confidence, (float) $childMemory->confidence);
            $compositionTime = max($compositionTime, $this->stableTimestamp($childMemory->created_at));
            $successStreaks[] = (int) $child->success_streak;
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

        $inputSchema = $this->unionInputSchemas($steps);
        $componentIds = array_values(array_map('intval', $procedureIds));
        $successStreak = min($successStreaks);
        $key = hash('sha256', 'composition|' . $this->encode([
            'name' => $name,
            'description' => $description,
            'component_procedure_ids' => $componentIds,
            'steps' => $steps,
            'input_schema' => $inputSchema,
            'authority' => $authority,
            'effect_ceiling' => $effect,
            'evidence_action_ids' => $evidence,
            'success_streak' => $successStreak,
            'confidence' => $confidence,
        ]));
        $existing = $this->findDefinition($key);
        if ($existing instanceof Procedure) {
            $memory = Memory::inspectByID((int) $existing->memory_id);
            if (!$memory instanceof Memory || $memory->status !== 'active') {
                throw new RuntimeException('Composed procedure points to an inactive token-memory generation.');
            }
            return [
                'procedure' => $existing->getData(),
                'memory' => $memory->getData(),
                'event' => $this->emitComposed($existing, $memory, $componentIds),
                'created' => false,
            ];
        }
        $content = implode("\n", [
            sprintf('[procedure key=%s composition]', $key),
            'Name: ' . mb_substr($name, 0, 160),
            'Description: ' . $description,
            'Steps: ' . implode(', ', array_map(static fn (array $step): string => (string) $step['action_kind'], $steps)),
            'Authority: explicitly composed by ' . $authority . '; execution remains adapter-bounded.',
        ]);
        /** @var Memory $memory */
        $memory = $this->core->insertRecord(Memory::class, [
            'tier' => 'procedural',
            'content' => $content,
            'confidence' => $confidence,
            'status' => 'active',
            'created_at' => $compositionTime,
            'updated_at' => $compositionTime,
            'operation_key' => TokenMemoryDaemon::operationKey('procedure-compose', $key),
        ]);
        [$procedure, $created] = $this->installProcedure([
            'memory_id' => (int) $memory->id,
            'procedure_key' => $key,
            'name' => mb_substr($name, 0, 160),
            'description' => $description,
            'steps' => $steps,
            'input_schema' => $inputSchema,
            'effect_ceiling' => $effect,
            'authority' => $authority,
            'status' => 'active',
            'evidence_action_ids' => $evidence,
            'success_streak' => $successStreak,
            'failure_count' => 0,
            'execution_count' => 0,
            'created_at' => $compositionTime,
            'updated_at' => $compositionTime,
        ]);
        return [
            'procedure' => $procedure->getData(),
            'memory' => $memory->getData(),
            'event' => $this->emitComposed($procedure, $memory, $componentIds),
            'created' => $created,
        ];
    }

    /** Finish an asynchronous machine.look and resume any suspended composition. */
    public function completePendingLook(int $requestEventId, array $reading): ?array
    {
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
            // Resume the exact durable result after a crash between finishing
            // the action and terminalizing its ProcedureRun. New callback data
            // cannot rewrite an observation that was already accepted.
            $observed = is_array($execution->observed) ? $execution->observed : [];
            $worked = $execution->status === 'succeeded' && (int) $execution->verified === 1;
        }
        $actionId = (int) $execution->action_trace_id;
        if ($execution->status === 'waiting') {
            $this->recordObserved($actionId, $observed, $worked, $worked ? 'succeeded' : 'failed');
        }
        $finished = $this->finishDurableExecution($actionId);

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
        $this->core->resumeProcedureRunAfterAsync(
            (int) $run->id,
            (int) $run->procedure_id,
            $run->procedure_memory_id === null ? null : (int) $run->procedure_memory_id,
            $stepIndex,
            $results,
            $result
        );
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
    public function finishDurableAction(int $actionId): array
    {
        return $this->finishDurableExecution($actionId);
    }

    /** @return array<string, mixed> */
    private function advanceRun(ProcedureRun $run): array
    {
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
                $sourceProcedureMemoryId,
                (int) $run->id,
                $index,
                is_array($step['verifier'] ?? null) ? $step['verifier'] : null
            );
            $actionId = (int) ($outcome['started']['action']['id'] ?? 0);
            if ($actionId > 0) {
                $actionIds[] = $actionId;
            }
            $run->setField('action_trace_ids', array_values(array_unique($actionIds)));

            if (($outcome['status'] ?? null) === 'in_progress') {
                return [
                    'status' => 'in_progress',
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
            $this->core->checkpointProcedureRunStep(
                (int) $run->id,
                (int) $run->procedure_id,
                (int) $run->procedure_memory_id,
                $index,
                $results,
                $actionIds,
                $completedResult
            );
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
            $terminal = $this->core->finalizeProcedureRun(
                (int) $run->id,
                (int) $run->procedure_id,
                (int) $run->procedure_memory_id,
                'succeeded',
                $results,
                $actionIds
            );
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

    /** @return ProcedureRun|array<string, mixed> */
    private function reconcileWaitingRun(ProcedureRun $run): ProcedureRun|array
    {
        $stepIndex = (int) $run->current_step;
        $executions = ActionExecution::getAllByWhere([
            'procedure_run_id' => (int) $run->id,
            'step_index' => $stepIndex + 1,
        ]);
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
        $this->core->resumeProcedureRunAfterAsync(
            (int) $run->id,
            (int) $run->procedure_id,
            $run->procedure_memory_id === null ? null : (int) $run->procedure_memory_id,
            $stepIndex,
            $results,
            $result
        );
        $resumed = ProcedureRun::getByID((int) $run->id);
        if (!$resumed instanceof ProcedureRun) {
            throw new RuntimeException('Reconciled procedure run disappeared.');
        }
        return $resumed;
    }

    /** @return array<string, mixed> */
    private function replayTerminalRun(ProcedureRun $run): array
    {
        $status = (string) $run->status;
        $terminal = $this->core->finalizeProcedureRun(
            (int) $run->id,
            (int) $run->procedure_id,
            $run->procedure_memory_id === null ? null : (int) $run->procedure_memory_id,
            $status,
            is_array($run->results) ? $run->results : [],
            is_array($run->action_trace_ids) ? array_map('intval', $run->action_trace_ids) : [],
            $status === 'succeeded' ? null : (string) $run->error
        );
        return [
            'status' => $status,
            'procedure' => $this->procedureSnapshotForRun($run),
            'run' => $terminal['run'],
            'event' => $terminal['event'],
            'replayed' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function failRun(ProcedureRun $run, array $results, string $reason): array
    {
        // A failed execution is evidence, not authority to retire whatever
        // generation currently owns the mutable procedure row. Learned
        // invalidation remains generation-ledgered in observeTypedFailure().
        $terminal = $this->core->finalizeProcedureRun(
            (int) $run->id,
            (int) $run->procedure_id,
            $run->procedure_memory_id === null ? null : (int) $run->procedure_memory_id,
            'failed',
            $results,
            is_array($run->action_trace_ids) ? array_map('intval', $run->action_trace_ids) : [],
            $reason
        );
        return [
            'status' => 'failed',
            'procedure' => $this->procedureSnapshotForRun($run),
            'run' => $terminal['run'],
            'event' => $terminal['event'],
        ];
    }

    private function activeRunProcedure(ProcedureRun $run, bool $counted): ?Procedure
    {
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

    private function completeRetiringProcedure(Procedure $procedure): void
    {
        $replacement = $this->core->procedureReplacementClaim(
            (int) $procedure->id,
            (int) $procedure->memory_id
        );
        if ($replacement !== null) {
            // The observer that owns the pending compile generation will
            // replay the cross-store replacement. Until then this row is
            // intentionally non-authorizing and old-generation runs stop.
            return;
        }
        $claim = $this->core->procedureInvalidationClaim(
            (int) $procedure->id,
            (int) $procedure->memory_id
        );
        if ($claim === null) {
            throw new RuntimeException('Retiring procedure is missing its transition claim.');
        }
        $this->invalidate($procedure, $claim['action_id'], $claim['reason']);
    }

    /** @return array<string, mixed> */
    private function cancelStaleRun(ProcedureRun $run): array
    {
        $results = is_array($run->results) ? $run->results : [];
        $actionIds = is_array($run->action_trace_ids)
            ? array_map('intval', $run->action_trace_ids)
            : [];
        $executions = ActionExecution::getAllByWhere([
            'procedure_run_id' => (int) $run->id,
            'step_index' => (int) $run->current_step + 1,
        ]);
        if (count($executions) > 1) {
            throw new RuntimeException('Stale procedure run has no unique current action.');
        }
        if ($executions !== []) {
            $execution = $executions[0];
            $actionId = (int) $execution->action_trace_id;
            if ((string) $execution->status === 'pending') {
                $this->core->rejectPendingActionExecution($actionId, [
                    'error' => 'Procedure generation retired before its pending step could dispatch.',
                ]);
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
        $terminal = $this->core->finalizeProcedureRun(
            (int) $run->id,
            (int) $run->procedure_id,
            $run->procedure_memory_id === null ? null : (int) $run->procedure_memory_id,
            'cancelled',
            $results,
            $actionIds,
            $reason
        );
        return [
            'status' => 'cancelled',
            'procedure' => $this->procedureSnapshotForRun($run),
            'run' => $terminal['run'],
            'event' => $terminal['event'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function procedureSnapshotForRun(ProcedureRun $run): ?array
    {
        if ($run->procedure_memory_id === null) {
            return null;
        }
        $procedure = Procedure::getByID((int) $run->procedure_id);
        return $procedure instanceof Procedure
            && (int) $procedure->memory_id === (int) $run->procedure_memory_id
                ? $procedure->getData()
                : null;
    }

    /** @return array<string, mixed> */
    private function dispatch(
        int $intentionId,
        int $actionId,
        string $kind,
        array $arguments,
        ?int $runId,
        ?int $stepIndex,
        array $verifier,
        ?int $decisionCycleId = null
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
                $this->core->searchMemory((string) $arguments['query'], min(100, max(20, $limit * 4))),
                static fn (array $row): bool => ($row['tier'] ?? null) !== 'working'
            ));
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
                $stepIndex,
                $decisionCycleId
            );
            return [
                'status' => 'waiting',
                'dispatch_event_id' => (int) ($queued['event']['id'] ?? 0),
                'wait_committed' => true,
                'observed' => ['queued' => true],
            ];
        }

        throw new RuntimeException(sprintf('No installed adapter for action kind %s.', $kind));
    }

    private function observeTypedFailure(string $shapeKey, int $generation, string $reason): void
    {
        $existing = $this->findDefinition($shapeKey);
        $seed = $existing instanceof Procedure
            ? $this->generation(is_array($existing->evidence_action_ids) ? $existing->evidence_action_ids : [])
            : 0;
        $claim = $this->claimProcedureGeneration(
            $shapeKey,
            $generation,
            hash('sha256', $this->encode([
                'kind' => 'typed-invalidation',
                'generation' => $generation,
                'reason' => $reason,
            ])),
            $seed
        );
        if ($claim === 'obsolete') {
            return;
        }
        if ($claim === 'blocked') {
            throw new RuntimeException(
                'An earlier procedure observation must be replayed before this failure can advance it.'
            );
        }

        $existing = $this->findDefinition($shapeKey);
        if ($existing instanceof Procedure) {
            $existingGeneration = $this->generation(
                is_array($existing->evidence_action_ids) ? $existing->evidence_action_ids : []
            );
            if ($existingGeneration <= $generation) {
                if ((string) $existing->status === 'invalidated') {
                    $memory = Memory::inspectByID((int) $existing->memory_id);
                    if ($memory instanceof Memory && (string) $memory->status === 'active') {
                        throw new RuntimeException(
                            'Invalidated procedure still has an active token generation.'
                        );
                    }
                } else {
                    $this->invalidateObserved($existing, $generation, $reason);
                }
            }
        }
        $this->commitProcedureGeneration($shapeKey, $generation);
    }

    private function observeLegacyFailure(string $shapeKey, int $generation, string $reason): void
    {
        $existing = $this->findLegacy($shapeKey, false);
        $seed = $existing instanceof Memory
            ? $this->generation($this->legacyEvidenceIds($existing))
            : 0;
        $claim = $this->claimProcedureGeneration(
            $shapeKey,
            $generation,
            hash('sha256', $this->encode([
                'kind' => 'legacy-invalidation',
                'generation' => $generation,
                'reason' => $reason,
            ])),
            $seed
        );
        if ($claim === 'obsolete') {
            return;
        }
        if ($claim === 'blocked') {
            throw new RuntimeException(
                'An earlier legacy observation must be replayed before this failure can advance it.'
            );
        }

        $existing = $this->findLegacy($shapeKey, false);
        if ($existing instanceof Memory
            && $this->generation($this->legacyEvidenceIds($existing)) <= $generation
        ) {
            $this->expireMemoryForOperation(
                $existing,
                TokenMemoryDaemon::operationKey(
                    'legacy-procedure-invalidate',
                    (string) $generation . ':' . $shapeKey
                )
            );
            $this->core->emitEventOnce(
                'procedure.invalidated:' . $shapeKey . ':' . $generation,
                'procedure.invalidated',
                [
                    'memory_id' => (int) $existing->id,
                    'action_id' => $generation,
                    'procedure_key' => $shapeKey,
                    'reason' => $reason,
                ]
            );
        }
        $this->commitProcedureGeneration($shapeKey, $generation);
    }

    private function invalidateObserved(Procedure $procedure, int $actionId, string $reason): void
    {
        $procedureId = (int) $procedure->id;
        $expectedMemoryId = (int) $procedure->memory_id;
        $this->core->beginProcedureInvalidation($procedureId, $expectedMemoryId, $actionId, $reason);
        $this->expireMemoryForOperation(
            Memory::inspectByID($expectedMemoryId),
            TokenMemoryDaemon::operationKey(
                'procedure-invalidate-memory',
                $procedureId . ':' . $expectedMemoryId . ':' . $actionId . ':' . hash('sha256', $reason)
            )
        );

        // Retire the exact authorizing token generation first. The SQLite
        // control-row CAS and its event are inseparable and cannot invalidate
        // a successor that reused the mutable procedure row.
        $this->core->finalizeProcedureInvalidation(
            $procedureId,
            $expectedMemoryId,
            $actionId,
            (string) $procedure->procedure_key,
            $reason
        );
    }

    private function invalidate(Procedure $procedure, ?int $actionId, string $reason): void
    {
        $procedureId = (int) $procedure->id;
        $expectedMemoryId = (int) $procedure->memory_id;
        $memory = Memory::inspectByID($expectedMemoryId);
        $this->core->beginProcedureInvalidation($procedureId, $expectedMemoryId, $actionId, $reason);
        $operationKey = TokenMemoryDaemon::operationKey(
            'procedure-invalidate-memory',
            $procedureId . ':' . $expectedMemoryId . ':' .
            (string) ($actionId ?? 0) . ':' . hash('sha256', $reason)
        );
        $this->expireMemoryForOperation($memory, $operationKey);

        // The token record is non-recallable before the SQLite row changes.
        // A crash here is safe and a retry completes the exact CAS + event.
        $this->core->finalizeProcedureInvalidation(
            $procedureId,
            $expectedMemoryId,
            $actionId,
            (string) $procedure->procedure_key,
            $reason
        );
    }

    private function expireMemoryForOperation(?Memory $memory, string $operationKey): void
    {
        if (!$memory instanceof Memory) {
            return;
        }
        if ($memory->status === 'active') {
            // The request must be byte-identical after a receipt-window crash,
            // so derive its timestamp from durable record state, not wall time.
            $updatedAt = $memory->updated_at;
            $parsed = is_int($updatedAt) || (is_string($updatedAt) && is_numeric($updatedAt))
                ? (int) $updatedAt
                : (is_string($updatedAt) ? strtotime($updatedAt) : false);
            $memory->setFields([
                'status' => 'expired',
                'updated_at' => ($parsed === false ? 0 : $parsed) + 1,
            ]);
        }
        if ($memory->status === 'expired') {
            // Replay an unchanged expired record too: the blob may already be
            // visible while its durable daemon receipt is still pending.
            $memory->saveWithOperation($operationKey);
        }
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
                $expectedChildMemoryId = isset($step['source_procedure_memory_id'])
                    ? (int) $step['source_procedure_memory_id']
                    : 0;
                $child = Procedure::getByID($childId);
                $childMemory = $expectedChildMemoryId > 0
                    ? Memory::inspectByID($expectedChildMemoryId)
                    : null;
                if (!$child instanceof Procedure
                    || $child->status !== 'active'
                    || (int) $child->memory_id !== $expectedChildMemoryId
                    || !$childMemory instanceof Memory
                    || $childMemory->status !== 'active'
                ) {
                    $reason = sprintf('Component procedure %d has no active token-memory generation.', $childId);
                    $this->invalidate($procedure, null, $reason);
                    throw new RuntimeException($reason);
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

    /** @return array<string, mixed>|null */
    private function reconcileInstalledTypedGeneration(
        ?Procedure $procedure,
        Event $sourceEvent,
        string $shapeKey,
        int $generation
    ): ?array {
        if (!$procedure instanceof Procedure
            || (string) $procedure->status !== 'active'
            || (int) ($procedure->source_event_id ?? 0) !== (int) $sourceEvent->id
            || $this->generation((array) $procedure->evidence_action_ids) !== $generation
        ) {
            return null;
        }
        $memory = Memory::inspectByID((int) $procedure->memory_id);
        if (!$memory instanceof Memory
            || (string) $memory->status !== 'active'
            || (int) ($memory->source_event_id ?? 0) !== (int) $sourceEvent->id
        ) {
            return null;
        }
        $compiled = $this->emitTypedCompiled($sourceEvent, $procedure, $memory, $shapeKey);
        $this->commitProcedureGeneration($shapeKey, $generation);
        return [
            'procedure' => $procedure->getData(),
            'memory' => $memory->getData(),
            'event' => $compiled,
            'created' => false,
            'deduplicated' => true,
        ];
    }

    /**
     * Install an immutable procedure definition or reconcile the exact winner
     * of a concurrent insert. The token daemon's operation receipt makes the
     * associated memory creation idempotent; this closes the SQLite unique-key
     * race without treating a different definition as a successful replay.
     *
     * @param array<string, mixed> $fields
     * @return array{0: Procedure, 1: bool}
     */
    private function installProcedure(array $fields): array
    {
        $key = (string) ($fields['procedure_key'] ?? '');
        $memoryId = (int) ($fields['memory_id'] ?? 0);
        if ($key === '' || $memoryId < 1) {
            throw new InvalidArgumentException('Procedure installation identity is invalid.');
        }

        $existing = $this->findDefinition($key);
        if ($existing instanceof Procedure) {
            if (!$this->procedureMatches($existing, $fields)) {
                throw new RuntimeException('Procedure key already belongs to a different definition.');
            }
            return [$existing, false];
        }

        try {
            /** @var Procedure $procedure */
            $procedure = $this->core->insertRecord(Procedure::class, $fields);
            return [$procedure, true];
        } catch (Throwable $throwable) {
            $winner = $this->findDefinition($key);
            if (!$winner instanceof Procedure || !$this->procedureMatches($winner, $fields)) {
                throw $throwable;
            }
            return [$winner, false];
        }
    }

    /** @param array<string, mixed> $fields */
    private function procedureMatches(Procedure $procedure, array $fields): bool
    {
        foreach ($fields as $field => $expected) {
            if (in_array($field, ['created_at', 'updated_at'], true)) {
                continue;
            }
            if ($procedure->{$field} !== $expected) {
                return false;
            }
        }
        return true;
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
        $existing = $this->findLegacy($shape['key'], false);
        $guard = $this->procedureGeneration($shape['key']);
        $replayingPendingGeneration = $guard !== null
            && $guard['pending_generation'] === (int) $action->id;
        if (!$replayingPendingGeneration
            && $existing instanceof Memory
            && $existing->status === 'active'
            && (int) ($existing->source_event_id ?? 0) === (int) $event->id
        ) {
            $existingGeneration = $this->generation($this->legacyEvidenceIds($existing));
            if ($this->canReplayProcedureGeneration($shape['key'], $existingGeneration)) {
                $compiled = $this->emitLegacyCompiled($event, $existing, $shape['key']);
                $this->commitProcedureGeneration($shape['key'], $existingGeneration);
                return [
                    'memory' => $existing->getData(),
                    'event' => $compiled,
                    'created' => false,
                    'deduplicated' => true,
                ];
            }
        }
        if ($action->status !== 'succeeded' || $action->match_status !== 'matched') {
            $this->observeLegacyFailure(
                $shape['key'],
                (int) $action->id,
                'Legacy advisory action no longer matched its expectation.'
            );
            return null;
        }

        $streak = [];
        foreach ($this->actionWindowThrough((int) $action->id) as $candidate) {
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
        $eventTime = $this->eventTime($event);
        $generation = $this->generation($streak);
        $seedGeneration = $existing instanceof Memory
            ? $this->generation($this->legacyEvidenceIds($existing))
            : 0;
        $previousMemoryId = $existing instanceof Memory ? (int) $existing->id : null;
        if ($replayingPendingGeneration
            && $existing instanceof Memory
            && (int) ($existing->source_event_id ?? 0) === (int) $event->id
            && $seedGeneration === $generation
        ) {
            $previousMemoryId = $existing->supersedes_id === null
                ? null
                : (int) $existing->supersedes_id;
        }
        $pendingHash = hash('sha256', $this->encode([
            'kind' => 'legacy',
            'source_event_id' => (int) $event->id,
            'source_memory_id' => (int) $episode->id,
            'previous_memory_id' => $previousMemoryId,
            'content' => $content,
            'confidence' => $confidence,
            'event_time' => $eventTime,
            'action_ids' => $streak,
        ]));
        $generationClaim = $this->claimProcedureGeneration(
            $shape['key'],
            $generation,
            $pendingHash,
            $seedGeneration
        );
        if ($generationClaim === 'obsolete') {
            $existing = $this->findLegacy($shape['key'], false);
            $guard = $this->procedureGeneration($shape['key']);
            if (!$existing instanceof Memory
                || $existing->status !== 'active'
                || $guard === null
                || $this->generation($this->legacyEvidenceIds($existing)) < $guard['current_generation']
            ) {
                return null;
            }
            $currentSource = Event::getByID((int) ($existing->source_event_id ?? 0));
            if (!$currentSource instanceof Event) {
                throw new RuntimeException('Current legacy procedure generation is incomplete.');
            }
            return [
                'memory' => $existing->getData(),
                'event' => $this->emitLegacyCompiled($currentSource, $existing, $shape['key']),
                'created' => false,
                'deduplicated' => true,
                'obsolete_generation' => true,
            ];
        }
        if ($generationClaim === 'blocked') {
            throw new RuntimeException(
                'An earlier legacy procedure generation must be replayed before this observation can advance it.'
            );
        }

        $existing = $this->findLegacy($shape['key'], false);
        $installed = $this->reconcileInstalledLegacyGeneration(
            $existing,
            $event,
            $shape['key'],
            $generation
        );
        if ($installed !== null) {
            return $installed;
        }
        if ($existing instanceof Memory) {
            $memory = Memory::replaceRecord($existing, [
                'tier' => 'procedural',
                'content' => $content,
                'confidence' => $confidence,
                'status' => 'active',
                'source_event_id' => (int) $event->id,
                'source_memory_id' => (int) $episode->id,
                'created_at' => $eventTime,
                'updated_at' => $eventTime,
            ], 'expired', TokenMemoryDaemon::operationKey(
                'legacy-procedure-replace',
                (string) $event->id . ':' . $shape['key']
            ));
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
                'created_at' => $eventTime,
                'updated_at' => $eventTime,
                'operation_key' => TokenMemoryDaemon::operationKey(
                    'legacy-procedure-create',
                    (string) $event->id . ':' . $shape['key']
                ),
            ]);
            $created = true;
        }
        $procedureEvent = $this->emitLegacyCompiled($event, $memory, $shape['key']);
        $this->commitProcedureGeneration($shape['key'], $generation);
        return ['memory' => $memory->getData(), 'event' => $procedureEvent, 'created' => $created];
    }

    /** @return array<string, mixed>|null */
    private function reconcileInstalledLegacyGeneration(
        ?Memory $memory,
        Event $sourceEvent,
        string $shapeKey,
        int $generation
    ): ?array {
        if (!$memory instanceof Memory
            || (string) $memory->status !== 'active'
            || (int) ($memory->source_event_id ?? 0) !== (int) $sourceEvent->id
            || $this->generation($this->legacyEvidenceIds($memory)) !== $generation
        ) {
            return null;
        }
        $compiled = $this->emitLegacyCompiled($sourceEvent, $memory, $shapeKey);
        $this->commitProcedureGeneration($shapeKey, $generation);
        return [
            'memory' => $memory->getData(),
            'event' => $compiled,
            'created' => false,
            'deduplicated' => true,
        ];
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

    private function findLegacy(string $key, bool $activeOnly): ?Memory
    {
        $afterId = 0;
        $match = null;
        do {
            $page = Memory::inspectPage(
                $afterId,
                256,
                'procedural',
                $activeOnly ? 'active' : null
            );
            foreach ($page as $memory) {
                $afterId = max($afterId, (int) $memory->id);
                if (preg_match(self::LEGACY_MARKER, (string) $memory->content, $matches) === 1
                    && hash_equals($key, (string) $matches[1])
                ) {
                    $match = $memory;
                }
            }
        } while (count($page) === 256);

        if ($activeOnly && $match instanceof Memory) {
            Memory::observeRecords([$match]);
        }
        return $match;
    }

    /** @return list<ActionTrace> */
    private function actionWindowThrough(int $actionId): array
    {
        $statement = Connections::getConnection()->prepare(
            'SELECT id FROM action_traces WHERE id <= :id ORDER BY id DESC LIMIT 300'
        );
        $statement->execute(['id' => $actionId]);
        $records = [];
        while (($id = $statement->fetchColumn()) !== false) {
            $action = ActionTrace::getByID((int) $id);
            if ($action instanceof ActionTrace) {
                $records[] = $action;
            }
        }
        $statement->closeCursor();
        return $records;
    }

    /**
     * Replay ownerless compile claims through their exact finished action.
     * The original ActionTrace, completion event, episodic operation receipt,
     * and token operation key make this bounded recovery idempotent.
     *
     * @return list<array{shape_key: string, generation: int, action_id: int}>
     */
    public function recoverPendingGenerations(int $limit = 16): array
    {
        if ($limit < 1 || $limit > 256) {
            throw new InvalidArgumentException('Procedure recovery limit is out of range.');
        }
        $statement = Connections::getConnection()->prepare(
            'SELECT shape_key,pending_generation,pending_hash
             FROM procedure_compile_guards
             WHERE pending_generation IS NOT NULL
             ORDER BY pending_generation ASC,shape_key ASC
             LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $pending = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        $recovered = [];
        foreach ($pending as $row) {
            $shapeKey = (string) ($row['shape_key'] ?? '');
            $generation = (int) ($row['pending_generation'] ?? 0);
            $pendingHash = $row['pending_hash'] ?? null;
            if ($shapeKey === '' || $generation < 1 || !is_string($pendingHash)) {
                throw new RuntimeException('Procedure generation guard has invalid pending identity.');
            }
            $this->recoverPendingProcedureGeneration($shapeKey, $generation, $pendingHash);
            $recovered[] = [
                'shape_key' => $shapeKey,
                'generation' => $generation,
                'action_id' => $generation,
            ];
        }
        return $recovered;
    }

    private function recoverPendingProcedureGeneration(
        string $shapeKey,
        int $generation,
        string $pendingHash
    ): void {
        $guard = $this->procedureGeneration($shapeKey);
        if ($guard === null) {
            throw new RuntimeException('Pending procedure generation guard disappeared.');
        }
        if ($guard['current_generation'] >= $generation
            && $guard['pending_generation'] === null
        ) {
            return;
        }
        if ($guard['pending_generation'] !== $generation
            || !is_string($guard['pending_hash'])
            || !hash_equals($guard['pending_hash'], $pendingHash)
        ) {
            throw new RuntimeException('Procedure recovery no longer owns the pending generation.');
        }

        $action = ActionTrace::getByID($generation);
        if (!$action instanceof ActionTrace
            || !in_array($action->status, ['succeeded', 'failed', 'cancelled'], true)
        ) {
            throw new RuntimeException('Pending procedure generation has no finished source action.');
        }
        $execution = ActionExecution::getByField('action_trace_id', $generation);
        $actualShape = $execution instanceof ActionExecution
            ? $this->typedShape($action, $execution)['key']
            : $this->legacyShape((string) $action->description, (string) $action->expected)['key'];
        if (!hash_equals($shapeKey, $actualShape)) {
            throw new RuntimeException('Pending procedure generation crossed its action shape.');
        }

        if ($execution instanceof ActionExecution) {
            if (!in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)) {
                throw new RuntimeException('Pending typed procedure action has no terminal execution.');
            }
            $this->finishDurableExecution($generation);
        } else {
            $observed = (string) ($action->observed ?? '');
            $repairNote = (string) ($action->repair_note ?? '');
            if ($observed === '' || $repairNote === '') {
                throw new RuntimeException('Pending legacy procedure action is not replayable.');
            }
            $this->core->finishAction(
                $generation,
                (string) $action->status,
                $observed,
                (string) $action->match_status === 'matched',
                $repairNote
            );
        }

        $guard = $this->procedureGeneration($shapeKey);
        if ($guard === null
            || $guard['current_generation'] < $generation
            || $guard['pending_generation'] !== null
            || $guard['pending_hash'] !== null
        ) {
            throw new RuntimeException('Procedure generation recovery did not commit its exact guard.');
        }
    }

    /** @return 'claimed'|'replay'|'obsolete'|'blocked' */
    private function claimProcedureGeneration(
        string $shapeKey,
        int $generation,
        string $pendingHash,
        int $seedGeneration
    ): string {
        $connection = Connections::getConnection();
        $pending = $this->procedureGeneration($shapeKey);
        if (!$connection->inTransaction()
            && $pending !== null
            && $pending['pending_generation'] !== null
            && $pending['pending_generation'] < $generation
        ) {
            if (!is_string($pending['pending_hash'])) {
                throw new RuntimeException('Procedure generation guard is missing its pending hash.');
            }
            $this->recoverPendingProcedureGeneration(
                $shapeKey,
                $pending['pending_generation'],
                $pending['pending_hash']
            );
        }
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }
        try {
            $insert = $connection->prepare(
                'INSERT OR IGNORE INTO procedure_compile_guards
                 (shape_key, current_generation, pending_generation, pending_hash)
                 VALUES (:shape_key, :current_generation, NULL, NULL)'
            );
            $insert->execute([
                'shape_key' => $shapeKey,
                'current_generation' => max(0, $seedGeneration),
            ]);
            $row = $this->procedureGeneration($shapeKey);
            if ($row === null) {
                throw new RuntimeException('Unable to initialize procedure generation guard.');
            }

            if ($generation <= $row['current_generation']) {
                $result = 'obsolete';
            } elseif ($row['pending_generation'] === $generation) {
                if (!is_string($row['pending_hash'])
                    || !hash_equals($row['pending_hash'], $pendingHash)
                ) {
                    throw new RuntimeException(
                        'Procedure generation was replayed with different compiled data.'
                    );
                }
                $result = 'replay';
            } elseif ($row['pending_generation'] !== null) {
                $result = $row['pending_generation'] < $generation ? 'blocked' : 'obsolete';
            } else {
                $claim = $connection->prepare(
                    'UPDATE procedure_compile_guards
                     SET pending_generation = :generation, pending_hash = :pending_hash
                     WHERE shape_key = :shape_key
                       AND current_generation < :generation
                       AND pending_generation IS NULL'
                );
                $claim->execute([
                    'generation' => $generation,
                    'pending_hash' => $pendingHash,
                    'shape_key' => $shapeKey,
                ]);
                if ($claim->rowCount() !== 1) {
                    throw new RuntimeException('Procedure generation claim changed concurrently.');
                }
                $result = 'claimed';
            }
            if ($ownsTransaction) {
                $connection->commit();
            }
            return $result;
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
    }

    private function commitProcedureGeneration(string $shapeKey, int $generation): void
    {
        $connection = Connections::getConnection();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }
        try {
            $insert = $connection->prepare(
                'INSERT OR IGNORE INTO procedure_compile_guards
                 (shape_key, current_generation, pending_generation, pending_hash)
                 VALUES (:shape_key, :generation, NULL, NULL)'
            );
            $insert->execute(['shape_key' => $shapeKey, 'generation' => $generation]);
            $row = $this->procedureGeneration($shapeKey);
            if ($row === null
                || ($row['pending_generation'] !== null
                    && $row['pending_generation'] !== $generation)
            ) {
                throw new RuntimeException('Cannot commit a procedure generation out of order.');
            }
            $commit = $connection->prepare(
                'UPDATE procedure_compile_guards
                 SET current_generation = MAX(current_generation, :generation),
                     pending_generation = NULL,
                     pending_hash = NULL
                 WHERE shape_key = :shape_key'
            );
            $commit->execute(['generation' => $generation, 'shape_key' => $shapeKey]);
            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
    }

    /** @return array{current_generation: int, pending_generation: ?int, pending_hash: ?string}|null */
    private function procedureGeneration(string $shapeKey): ?array
    {
        $statement = Connections::getConnection()->prepare(
            'SELECT current_generation, pending_generation, pending_hash
             FROM procedure_compile_guards WHERE shape_key = :shape_key'
        );
        $statement->execute(['shape_key' => $shapeKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (!is_array($row)) {
            return null;
        }
        return [
            'current_generation' => (int) $row['current_generation'],
            'pending_generation' => $row['pending_generation'] === null
                ? null
                : (int) $row['pending_generation'],
            'pending_hash' => $row['pending_hash'] === null ? null : (string) $row['pending_hash'],
        ];
    }

    private function canReplayProcedureGeneration(string $shapeKey, int $generation): bool
    {
        $guard = $this->procedureGeneration($shapeKey);
        return $guard === null
            || ($guard['current_generation'] <= $generation
                && ($guard['pending_generation'] === null
                    || $guard['pending_generation'] === $generation));
    }

    /** @param list<mixed> $actionIds */
    private function generation(array $actionIds): int
    {
        $ids = array_values(array_filter(array_map('intval', $actionIds), static fn (int $id): bool => $id > 0));
        return $ids === [] ? 0 : max($ids);
    }

    /** @return list<int> */
    private function legacyEvidenceIds(Memory $memory): array
    {
        if (preg_match(
            '/^Evidence: consecutive matched action traces ([0-9, ]+)$/m',
            (string) $memory->content,
            $matches
        ) !== 1) {
            return [];
        }
        return array_values(array_map(
            'intval',
            preg_split('/,\s*/', trim((string) $matches[1])) ?: []
        ));
    }

    /** @return array<string, mixed> */
    private function emitTypedCompiled(
        Event $sourceEvent,
        Procedure $procedure,
        Memory $memory,
        string $shapeKey
    ): array {
        $steps = is_array($procedure->steps) ? $procedure->steps : [];
        $kind = (string) ($steps[0]['action_kind'] ?? '');
        $actionIds = array_values(array_map(
            'intval',
            is_array($procedure->evidence_action_ids) ? $procedure->evidence_action_ids : []
        ));
        return $this->core->emitEventOnce(
            'procedure.compiled:' . (int) $sourceEvent->id . ':' . $shapeKey,
            'procedure.compiled',
            [
                'procedure_id' => (int) $procedure->id,
                'memory_id' => (int) $memory->id,
                'procedure_key' => $shapeKey,
                'action_kind' => $kind,
                'action_ids' => $actionIds,
                'success_streak' => (int) $procedure->success_streak,
                'created' => $memory->supersedes_id === null,
                'effect_ceiling' => (string) $procedure->effect_ceiling,
                'adapter_installed' => true,
            ]
        );
    }

    /** @return array<string, mixed> */
    private function emitLegacyCompiled(Event $sourceEvent, Memory $memory, string $shapeKey): array
    {
        $actionIds = $this->legacyEvidenceIds($memory);
        return $this->core->emitEventOnce(
            'procedure.compiled:' . (int) $sourceEvent->id . ':' . $shapeKey,
            'procedure.compiled',
            [
                'memory_id' => (int) $memory->id,
                'procedure_key' => $shapeKey,
                'action_ids' => $actionIds,
                'success_streak' => count($actionIds),
                'created' => $memory->supersedes_id === null,
                'adapter_installed' => false,
            ]
        );
    }

    /** @param list<int> $componentIds
     *  @return array<string, mixed>
     */
    private function emitComposed(Procedure $procedure, Memory $memory, array $componentIds): array
    {
        return $this->core->emitEventOnce(
            'procedure.composed:' . (string) $procedure->procedure_key,
            'procedure.composed',
            [
                'procedure_id' => (int) $procedure->id,
                'memory_id' => (int) $memory->id,
                'component_procedure_ids' => $componentIds,
                'step_count' => count(is_array($procedure->steps) ? $procedure->steps : []),
                'effect_ceiling' => (string) $procedure->effect_ceiling,
                'authority' => (string) $procedure->authority,
            ]
        );
    }

    private function eventTime(Event $event): int
    {
        $value = $event->created_at;
        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        $parsed = is_string($value) ? strtotime($value) : false;
        return $parsed === false ? time() : $parsed;
    }

    private function stableTimestamp(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return max(0, (int) $value);
        }
        $parsed = is_string($value) ? strtotime($value) : false;
        return $parsed === false ? 0 : max(0, $parsed);
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
