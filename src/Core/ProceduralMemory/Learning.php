<?php

declare(strict_types=1);

namespace NaviBrain\Core\ProceduralMemory;

use NaviBrain\Core\ProceduralMemory;

use Divergence\IO\Database\SQLite;
use InvalidArgumentException;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Event;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\Procedure;
use NaviBrain\Model\ProcedureReplacementClaim;
use NaviBrain\Storage\TokenMemoryDaemon;
use RuntimeException;
use Throwable;

class Learning extends Component
{
    /** @return array<string, mixed>|null */
    public function observe(ActionTrace $action, Event $event, Memory $episode): ?array {
        $execution = ActionExecution::getByField('action_trace_id', (int) $action->id);
        if (!$execution instanceof ActionExecution) {
            return $this->observeLegacy($action, $event, $episode);
        }
        if ((((array) $execution->observed)['dispatch_not_attempted'] ?? false) === true) {

            return null;
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
                && $this->typedMemorySourceMatches($existing, $existingMemory, $event)
            ) {
                $existingGeneration = $this->generation(is_array($existing->evidence_action_ids) ? $existing->evidence_action_ids : []);
                if (!$this->canReplayProcedureGeneration($shape['key'], $existingGeneration)) {
                    $existingMemory = null;
                }
            } else {
                $existingMemory = null;
            }
            if ($existingMemory instanceof Memory) {
                $compiled = $this->emitTypedCompiled($event, $existing, $existingMemory, $shape['key']);
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
            $this->observeTypedFailure($shape['key'], (int) $action->id, 'A typed execution no longer satisfied its installed postcondition.');
            return null;
        }

        if (!$replayingPendingGeneration
            && $execution->procedure_run_id !== null
            && $existing instanceof Procedure
            && $existing->status === 'active'
            && (int) $execution->procedure_id === (int) $existing->id
            && (int) $execution->procedure_memory_id === (int) $existing->memory_id
        ) {
            return null;
        }

        $streak = [];
        foreach ($this->actionWindowThrough((int) $action->id) as $candidate) {
            $candidateExecution = ActionExecution::getByField('action_trace_id', (int) $candidate->id);
            if (!$candidateExecution instanceof ActionExecution
                || (((array) $candidateExecution->observed)['dispatch_not_attempted'] ?? false) === true
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
        if (count($streak) < ProceduralMemory::SUCCESS_STREAK) {
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
            if (!$installedMemory instanceof Memory
                || !$this->typedMemorySourceMatches($existing, $installedMemory, $event)
            ) {
                throw new RuntimeException('Installed pending procedure memory has no matching source.');
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
        $generationClaim = $this->claimProcedureGeneration($shape['key'], $generation, $pendingHash, $seedGeneration);
        if ($generationClaim === 'obsolete') {
            $existing = $this->findDefinition($shape['key']);
            $guard = $this->procedureGeneration($shape['key']);
            if (!$existing instanceof Procedure
                || $existing->status !== 'active'
                || $guard === null
                || $this->generation(is_array($existing->evidence_action_ids) ? $existing->evidence_action_ids : []) < $guard['current_generation']
            ) {
                return null;
            }
            $currentMemory = Memory::inspectByID((int) $existing->memory_id);
            $currentSource = Event::getByID((int) ($existing->source_event_id ?? 0));
            if (!$currentMemory instanceof Memory || !$currentSource instanceof Event
                || !$this->typedMemorySourceMatches($existing, $currentMemory, $currentSource)
            ) {
                throw new RuntimeException('Current typed procedure generation is incomplete.');
            }
            return [
                'procedure' => $existing->getData(),
                'memory' => $currentMemory->getData(),
                'event' => $this->emitTypedCompiled($currentSource, $existing, $currentMemory, $shape['key']),
                'created' => false,
                'deduplicated' => true,
                'obsolete_generation' => true,
            ];
        }
        if ($generationClaim === 'blocked') {
            throw new RuntimeException('An earlier procedure generation must be replayed before this observation can advance it.');
        }

        $existing = $this->findDefinition($shape['key']);
        $installed = $this->reconcileInstalledTypedGeneration($existing, $event, $shape['key'], $generation);
        if ($installed !== null) {
            return $installed;
        }

        if ($existing instanceof Procedure) {
            $previousMemoryId = (int) $existing->memory_id;
            $this->core->beginProcedureReplacement((int) $existing->id, $previousMemoryId, $shape['key'], $generation, $pendingHash);
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
                'source_event_kind' => 'event',
                'source_memory_id' => (int) $episode->id,
                'created_at' => $eventTime,
                'updated_at' => $eventTime,
            ], 'expired', TokenMemoryDaemon::operationKey('procedure-observe-replace', (string) $event->id . ':' . $shape['key']));
            $replacement = $this->core->finalizeProcedureReplacement(new ProcedureReplacementClaim([
                'procedure_id' => (int) $existing->id,
                'previous_memory_id' => $previousMemoryId,
                'next_memory_id' => (int) $memory->id,
                'shape_key' => $shape['key'],
                'generation' => $generation,
                'pending_hash' => $pendingHash,
            ], true, true), [
                'name' => mb_substr((string) $action->description, 0, 160),
                'description' => (string) $action->description,
                'steps' => [$step],
                'input_schema' => $this->schemaForArguments($step['arguments']),
                'effect_ceiling' => ProceduralMemory::ADAPTERS[$kind]['effect'],
                'authority' => $authority,
                'status' => 'active',
                'evidence_action_ids' => $streak,
                'success_streak' => count($streak),
                'invalidated_at' => null,
                'invalidation_reason' => null,
                'source_event_id' => (int) $event->id,
                'updated_at' => $eventTime,
                ]);
            $procedure = Procedure::getByID((int) ($replacement['procedure']['id'] ?? 0));
            if (!$procedure instanceof Procedure) {
                throw new RuntimeException('Published procedure replacement disappeared.');
            }
            $created = false;
        } else {
            /** @var Memory $memory */
            $memory = new Memory([
                'tier' => 'procedural',
                'content' => $content,
                'confidence' => $confidence,
                'status' => 'active',
                'source_event_id' => (int) $event->id,
                'source_event_kind' => 'event',
                'source_memory_id' => (int) $episode->id,
                'created_at' => $eventTime,
                'updated_at' => $eventTime,
                'operation_key' => TokenMemoryDaemon::operationKey('procedure-observe-create', (string) $event->id . ':' . $shape['key']),
            ], true, true);
            $memory->save();
            [$procedure, $created] = $this->installProcedure([
                'memory_id' => (int) $memory->id,
                'procedure_key' => $shape['key'],
                'name' => mb_substr((string) $action->description, 0, 160),
                'description' => (string) $action->description,
                'steps' => [$step],
                'input_schema' => $this->schemaForArguments($step['arguments']),
                'effect_ceiling' => ProceduralMemory::ADAPTERS[$kind]['effect'],
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

    public function observeTypedFailure(string $shapeKey, int $generation, string $reason): void {
        $existing = $this->findDefinition($shapeKey);
        $seed = $existing instanceof Procedure
            ? $this->generation(is_array($existing->evidence_action_ids) ? $existing->evidence_action_ids : [])
            : 0;
        $claim = $this->claimProcedureGeneration($shapeKey, $generation, hash('sha256', $this->encode([ 'kind' => 'typed-invalidation', 'generation' => $generation, 'reason' => $reason, ])), $seed);
        if ($claim === 'obsolete') {
            return;
        }
        if ($claim === 'blocked') {
            throw new RuntimeException('An earlier procedure observation must be replayed before this failure can advance it.');
        }

        $existing = $this->findDefinition($shapeKey);
        if ($existing instanceof Procedure) {
            $existingGeneration = $this->generation(is_array($existing->evidence_action_ids) ? $existing->evidence_action_ids : []);
            if ($existingGeneration <= $generation) {
                if ((string) $existing->status === 'invalidated') {
                    $memory = Memory::inspectByID((int) $existing->memory_id);
                    if ($memory instanceof Memory && (string) $memory->status === 'active') {
                        throw new RuntimeException('Invalidated procedure still has an active token generation.');
                    }
                } else {
                    $this->invalidateObserved($existing, $generation, $reason);
                }
            }
        }
        $this->commitProcedureGeneration($shapeKey, $generation);
    }

    public function invalidateObserved(Procedure $procedure, int $actionId, string $reason): void {
        $procedureId = (int) $procedure->id;
        $expectedMemoryId = (int) $procedure->memory_id;
        $this->core->beginProcedureInvalidation($procedureId, $expectedMemoryId, $actionId, $reason);
        $this->expireMemoryForOperation(
            Memory::inspectByID($expectedMemoryId),
            TokenMemoryDaemon::operationKey('procedure-invalidate-memory', $procedureId . ':' . $expectedMemoryId . ':' . $actionId . ':' . hash('sha256', $reason))
        );

        $this->core->finalizeProcedureInvalidation($procedureId, $expectedMemoryId, $actionId, (string) $procedure->procedure_key, $reason);
    }

    public function invalidate(Procedure $procedure, ?int $actionId, string $reason): void {
        $procedureId = (int) $procedure->id;
        $expectedMemoryId = (int) $procedure->memory_id;
        $memory = Memory::inspectByID($expectedMemoryId);
        $this->core->beginProcedureInvalidation($procedureId, $expectedMemoryId, $actionId, $reason);
        $operationKey = TokenMemoryDaemon::operationKey('procedure-invalidate-memory', $procedureId . ':' . $expectedMemoryId . ':' . (string) ($actionId ?? 0) . ':' . hash('sha256', $reason));
        $this->expireMemoryForOperation($memory, $operationKey);

        $this->core->finalizeProcedureInvalidation($procedureId, $expectedMemoryId, $actionId, (string) $procedure->procedure_key, $reason);
    }

    public function expireMemoryForOperation(?Memory $memory, string $operationKey): void {
        if (!$memory instanceof Memory) {
            return;
        }
        if ($memory->status === 'active') {

            $updatedAt = $memory->updated_at;
            $parsed = is_int($updatedAt) || (is_string($updatedAt) && is_numeric($updatedAt))
                ? (int) $updatedAt
                : (is_string($updatedAt) ? strtotime($updatedAt) : false);
            $memory->setFields([ 'status' => 'expired', 'updated_at' => ($parsed === false ? 0 : $parsed) + 1, ]);
        }
        if ($memory->status === 'expired') {

            $memory->saveWithOperation($operationKey);
        }
    }

    /** @return array{key: string} */
    public function typedShape(ActionTrace $action, ActionExecution $execution): array {
        return $this->typedShapeFromValues(
            (string) $action->description,
            (string) $action->expected,
            (string) $execution->action_kind,
            is_array($execution->arguments) ? $execution->arguments : [],
            is_array($execution->verifier) ? $execution->verifier : []
        );
    }

    /** @return array{key: string} */
    public function typedShapeFromValues(string $description, string $expected, string $actionKind, array $arguments, ?array $verifier = null): array {
        $shape = [
            'action_kind' => $actionKind,
            'description' => $this->normalize($description),
            'expected' => $this->normalize($expected),
            'argument_schema' => $this->schemaForArguments($arguments),
            'verifier' => $verifier ?? ProceduralMemory::ADAPTERS[$actionKind]['verifier'],
        ];
        return ['key' => hash('sha256', $this->encode($shape))];
    }

    public function findDefinition(string $key, bool $activeOnly = false): ?Procedure {
        $procedure = Procedure::getByField('procedure_key', $key);
        if (!$procedure instanceof Procedure || ($activeOnly && $procedure->status !== 'active')) {
            return null;
        }
        return $procedure;
    }

    /** @return array<string, mixed>|null */
    public function reconcileInstalledTypedGeneration(?Procedure $procedure, Event $sourceEvent, string $shapeKey, int $generation): ?array {
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
            || !$this->typedMemorySourceMatches($procedure, $memory, $sourceEvent)
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
     * @param array<string, mixed> $fields
     * @return array{0: Procedure, 1: bool}
     */
    public function installProcedure(array $fields): array {
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
            $procedure = new Procedure($fields, true, true);
            $procedure->save();
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
    public function procedureMatches(Procedure $procedure, array $fields): bool {
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

    public function requireProcedure(int $id): Procedure {
        $procedure = Procedure::getByID($id);
        if (!$procedure instanceof Procedure) {
            throw new RuntimeException(sprintf('Procedure %d does not exist.', $id));
        }
        return $procedure;
    }

    public function typedMemorySourceMatches(Procedure $procedure, Memory $memory, Event $event): bool {

        return (int) $procedure->memory_id === (int) $memory->id
            && (int) ($procedure->source_event_id ?? 0) === (int) $event->id
            && (int) ($memory->source_event_id ?? 0) === (int) $event->id
            && in_array($memory->source_event_kind, [null, 'event'], true);
    }

    /** @return list<ActionTrace> */
    public function actionWindowThrough(int $actionId): array {
        return ActionTrace::getAllByWhere([sprintf('id <= %s', SQLite::quote($actionId))], ['order' => ['id' => 'DESC'], 'limit' => 300]);
    }

    /** @return array<string, mixed> */
    public function emitTypedCompiled(Event $sourceEvent, Procedure $procedure, Memory $memory, string $shapeKey): array {
        $steps = is_array($procedure->steps) ? $procedure->steps : [];
        $kind = (string) ($steps[0]['action_kind'] ?? '');
        $actionIds = array_values(array_map( 'intval', is_array($procedure->evidence_action_ids) ? $procedure->evidence_action_ids : [] ));
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
}
