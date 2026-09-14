<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Core\ExecutiveCore\Executive;

use InvalidArgumentException;
use NaviBrain\Model\Event;
use NaviBrain\Model\Memory;
use NaviBrain\Model\Procedure;
use NaviBrain\Model\SensorySource;
use NaviBrain\Perception\CommandSense;
use Throwable;

class ProceduralMemory
{
    private const DOMAINS = [
        'recordExecution' => ProceduralMemory\Actions::class,
        'recordObserved' => ProceduralMemory\Actions::class,
        'finishDurableExecution' => ProceduralMemory\Actions::class,
        'executionAuthorizationActive' => ProceduralMemory\Actions::class,
        'executeAction' => ProceduralMemory\Actions::class,
        'completePendingLook' => ProceduralMemory\Actions::class,
        'finishDurableAction' => ProceduralMemory\Actions::class,
        'dispatch' => ProceduralMemory\Actions::class,
        'compose' => ProceduralMemory\Composition::class,
        'validateComposition' => ProceduralMemory\Composition::class,
        'validateRuntimeInput' => ProceduralMemory\Composition::class,
        'unionInputSchemas' => ProceduralMemory\Composition::class,
        'emitComposed' => ProceduralMemory\Composition::class,
        'recoverPendingGenerations' => ProceduralMemory\GenerationRecovery::class,
        'recoverPendingProcedureGeneration' => ProceduralMemory\GenerationRecovery::class,
        'claimProcedureGeneration' => ProceduralMemory\GenerationRecovery::class,
        'commitProcedureGeneration' => ProceduralMemory\GenerationRecovery::class,
        'procedureGeneration' => ProceduralMemory\GenerationRecovery::class,
        'canReplayProcedureGeneration' => ProceduralMemory\GenerationRecovery::class,
        'generation' => ProceduralMemory\GenerationRecovery::class,
        'observe' => ProceduralMemory\Learning::class,
        'observeTypedFailure' => ProceduralMemory\Learning::class,
        'invalidateObserved' => ProceduralMemory\Learning::class,
        'invalidate' => ProceduralMemory\Learning::class,
        'expireMemoryForOperation' => ProceduralMemory\Learning::class,
        'typedShape' => ProceduralMemory\Learning::class,
        'typedShapeFromValues' => ProceduralMemory\Learning::class,
        'findDefinition' => ProceduralMemory\Learning::class,
        'reconcileInstalledTypedGeneration' => ProceduralMemory\Learning::class,
        'installProcedure' => ProceduralMemory\Learning::class,
        'procedureMatches' => ProceduralMemory\Learning::class,
        'requireProcedure' => ProceduralMemory\Learning::class,
        'typedMemorySourceMatches' => ProceduralMemory\Learning::class,
        'actionWindowThrough' => ProceduralMemory\Learning::class,
        'emitTypedCompiled' => ProceduralMemory\Learning::class,
        'observeLegacy' => ProceduralMemory\LegacyLearning::class,
        'observeLegacyFailure' => ProceduralMemory\LegacyLearning::class,
        'reconcileInstalledLegacyGeneration' => ProceduralMemory\LegacyLearning::class,
        'legacyMemorySourceMatches' => ProceduralMemory\LegacyLearning::class,
        'legacySourceEvent' => ProceduralMemory\LegacyLearning::class,
        'hasLegacyCompiledReceipt' => ProceduralMemory\LegacyLearning::class,
        'legacyShape' => ProceduralMemory\LegacyLearning::class,
        'findLegacy' => ProceduralMemory\LegacyLearning::class,
        'legacyEvidenceIds' => ProceduralMemory\LegacyLearning::class,
        'emitLegacyCompiled' => ProceduralMemory\LegacyLearning::class,
        'run' => ProceduralMemory\Runs::class,
        'advanceRun' => ProceduralMemory\Runs::class,
        'heldRun' => ProceduralMemory\Runs::class,
        'reconcileWaitingRun' => ProceduralMemory\Runs::class,
        'replayTerminalRun' => ProceduralMemory\Runs::class,
        'failRun' => ProceduralMemory\Runs::class,
        'activeRunProcedure' => ProceduralMemory\Runs::class,
        'completeRetiringProcedure' => ProceduralMemory\Runs::class,
        'cancelStaleRun' => ProceduralMemory\Runs::class,
        'procedureSnapshotForRun' => ProceduralMemory\Runs::class,
    ];

    private array $components = [];

    public function __call(string $name, array $arguments): mixed {
        if (method_exists($this, $name)) {
            return $this->{$name}(...$arguments);
        }
        $class = self::DOMAINS[$name] ?? throw new \BadMethodCallException('Unknown ProceduralMemory method: ' . $name);
        $component = $this->components[$class] ??= new $class($this);
        return $component->{$name}(...$arguments);
    }

    public function __get(string $name): mixed {
        return match ($name) {
            'core' => $this->core,
            default => throw new \OutOfBoundsException('Unknown ProceduralMemory state: ' . $name),
        };
    }

    public const SUCCESS_STREAK = 3;
    public const MAX_COMPOSED_STEPS = 8;
    public const LEGACY_MARKER = '/^\[procedure action-shape=([a-f0-9]{64})\]/';

    /** @var array<string, array{action_class: string, effect: string, required: array<string, string>, optional: array<string, string>, verifier: array<string, mixed>}> */
    public const ADAPTERS = [
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
            'observation_contract' => 'fixed_kernel_files_v1',
            'verifier' => ['kind' => 'exit_code_zero'],
        ],
    ];

    private readonly Executive $core;

    public function __construct(Executive $core) {
        $this->core = $core;
    }

    /** @return list<array<string, mixed>> */
    public function adapters(): array {
        $rows = [];
        foreach (self::ADAPTERS as $kind => $adapter) {
            if ($kind === 'machine.look') {
                $adapter['operations'] = CommandSense::operations();
            }
            $rows[] = ['action_kind' => $kind] + $adapter;
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    public function verifierFor(string $actionKind): array {
        if (!isset(self::ADAPTERS[$actionKind])) {
            throw new InvalidArgumentException('No installed verifier exists for this action kind.');
        }
        return self::ADAPTERS[$actionKind]['verifier'];
    }

    /** @return list<array<string, mixed>> */
    public function terminalAdapters(): array {
        return $this->adaptersByClass(['grounding', 'learning']);
    }

    /** @return array<string, mixed> */
    public function decisionProcedure(): array {
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
    public function inspectCandidate(string $actionKind, array $arguments, string $description, string $expected): array {
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

    /** @return array<string, mixed> */
    public function simulateCandidate(string $actionKind, array $arguments): array {
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
            $operation = CommandSense::resolveOperation((string) $arguments['command']);
            $source = SensorySource::getByField('source_key', 'machine_inspection');
            $feasible = $operation !== null && $source instanceof SensorySource
                && $source->status === 'active'
                && $source->effect_ceiling === 'observe';
            return [
                'feasible' => $feasible,
                'confidence' => $feasible ? 0.8 : 0.0,
                'predicted' => ['request_queued' => $feasible, 'operation' => $operation, 'effect_contract' => 'fixed_kernel_files_v1'],
                'reason' => $feasible
                    ? 'The authorized source supports this fixed-file kernel observation.'
                    : ($operation === null
                        ? 'Unsupported observation; choose a named operation: ' . implode(', ', array_keys(CommandSense::operations()))
                        : 'The user-authorized machine inspection source is unavailable.'),
            ];
        }
        return ['feasible' => false, 'confidence' => 0.0, 'predicted' => [], 'reason' => 'No simulation exists.'];
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $status = null): array {
        if ($status !== null && !in_array($status, ['active', 'invalidated'], true)) {
            throw new InvalidArgumentException('procedure status must be active or invalidated.');
        }
        $records = $status === null
            ? Procedure::getAll(['order' => ['updated_at' => 'DESC']])
            : Procedure::getAllByWhere(['status' => $status], ['order' => ['updated_at' => 'DESC']]);
        return array_values(array_map(static fn (Procedure $procedure): array => $procedure->getData(), $records));
    }

    /** @return array<string, mixed>|null */
    public function recall(string $description, string $expected, ?string $actionKind = null, array $arguments = []): ?array {
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

    private function validateArguments(string $kind, array $arguments): void {
        $adapter = self::ADAPTERS[$kind] ?? null;
        if ($adapter === null) {
            throw new InvalidArgumentException(sprintf( 'action kind must be one of: %s.', implode(', ', array_keys(self::ADAPTERS)) ));
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

    /**
     * @param list<string> $classes
     * @return list<array<string, mixed>>
     */
    private function adaptersByClass(array $classes): array {
        return array_values(array_filter( $this->adapters(), static fn (array $adapter): bool => in_array((string) $adapter['action_class'], $classes, true) ));
    }

    private function validateType(string $name, mixed $value, string $type): void {
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

    /** @return array<string, mixed> */
    private function schemaForArguments(array $arguments): array {
        $properties = [];
        foreach ($arguments as $name => $value) {
            $properties[(string) $name] = ['type' => $this->typeOf($value)];
        }
        ksort($properties);
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    private function typeOf(mixed $value): string {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            default => throw new InvalidArgumentException('Procedure arguments must be scalar JSON values.'),
        };
    }

    private function eventTime(Event $event): int {
        $value = $event->created_at;
        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        $parsed = is_string($value) ? strtotime($value) : false;
        return $parsed === false ? time() : $parsed;
    }

    private function stableTimestamp(mixed $value): int {
        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return max(0, (int) $value);
        }
        $parsed = is_string($value) ? strtotime($value) : false;
        return $parsed === false ? 0 : max(0, $parsed);
    }

    private function normalize(string $value): string {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\b[0-9a-f]{12,}\b/i', '<token>', $value) ?? $value;
        $value = preg_replace('/\b\d+(?:\.\d+)?\b/', '#', $value) ?? $value;
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function effectRank(string $effect): int {
        return match ($effect) {
            'observe' => 0,
            'think' => 1,
            'prepare' => 2,
            'act' => 3,
            default => 4,
        };
    }

    private function encode(mixed $value): string {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
