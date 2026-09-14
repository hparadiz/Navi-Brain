<?php

declare(strict_types=1);

namespace NaviBrain\Core\ProceduralMemory;

use NaviBrain\Core\ProceduralMemory;

use InvalidArgumentException;
use NaviBrain\Model\Memory;
use NaviBrain\Model\Procedure;
use NaviBrain\Storage\TokenMemoryDaemon;
use RuntimeException;

class Composition extends Component
{
    /**
     * @param list<int> $procedureIds
     * @return array<string, mixed>
     */
    public function compose(string $name, string $description, array $procedureIds, string $authority): array {
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
        if (count($steps) > ProceduralMemory::MAX_COMPOSED_STEPS) {
            throw new InvalidArgumentException(sprintf( 'A composed procedure may contain at most %d flattened steps.', ProceduralMemory::MAX_COMPOSED_STEPS ));
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
        $memory = new Memory([
            'tier' => 'procedural',
            'content' => $content,
            'confidence' => $confidence,
            'status' => 'active',
            'created_at' => $compositionTime,
            'updated_at' => $compositionTime,
            'operation_key' => TokenMemoryDaemon::operationKey('procedure-compose', $key),
        ], true, true);
        $memory->save();
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

    public function validateComposition(Procedure $procedure): void {
        foreach ((array) $procedure->steps as $step) {
            if (!is_array($step) || !isset(ProceduralMemory::ADAPTERS[(string) ($step['action_kind'] ?? '')])) {
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

    public function validateRuntimeInput(Procedure $procedure, array $arguments): void {
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

    /**
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    public function unionInputSchemas(array $steps): array {
        $properties = [];
        foreach ($steps as $step) {
            foreach ((array) ($step['arguments'] ?? []) as $name => $value) {
                $type = $this->typeOf($value);
                if (isset($properties[$name]) && ($properties[$name]['type'] ?? null) !== $type) {
                    throw new InvalidArgumentException(sprintf( 'Cannot compose procedures whose argument %s has conflicting types.', (string) $name ));
                }
                $properties[(string) $name] = ['type' => $type];
            }
        }
        ksort($properties);
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    /**
     * @param list<int> $componentIds
     * @return array<string, mixed>
     */
    public function emitComposed(Procedure $procedure, Memory $memory, array $componentIds): array {
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
}
