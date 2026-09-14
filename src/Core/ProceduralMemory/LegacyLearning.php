<?php

declare(strict_types=1);

namespace NaviBrain\Core\ProceduralMemory;

use NaviBrain\Core\ProceduralMemory;

use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Event;
use NaviBrain\Model\Memory;
use NaviBrain\Model\MemorySource;
use NaviBrain\Storage\TokenMemoryDaemon;
use RuntimeException;

class LegacyLearning extends Component
{
    /** @return array<string, mixed>|null */
    public function observeLegacy(ActionTrace $action, Event $event, Memory $episode): ?array {
        $shape = $this->legacyShape((string) $action->description, (string) $action->expected);
        $existing = $this->findLegacy($shape['key'], false);
        $guard = $this->procedureGeneration($shape['key']);
        $replayingPendingGeneration = $guard !== null
            && $guard['pending_generation'] === (int) $action->id;
        if (!$replayingPendingGeneration
            && $existing instanceof Memory
            && $existing->status === 'active'
            && $this->legacyMemorySourceMatches($existing, $event, $shape['key'])
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
            $this->observeLegacyFailure($shape['key'], (int) $action->id, 'Legacy advisory action no longer matched its expectation.');
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
        if (count($streak) < ProceduralMemory::SUCCESS_STREAK) {
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
            && $this->legacyMemorySourceMatches($existing, $event, $shape['key'])
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
        $generationClaim = $this->claimProcedureGeneration($shape['key'], $generation, $pendingHash, $seedGeneration);
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
            $currentSource = $this->legacySourceEvent($existing, $shape['key']);
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
            throw new RuntimeException('An earlier legacy procedure generation must be replayed before this observation can advance it.');
        }

        $existing = $this->findLegacy($shape['key'], false);
        $installed = $this->reconcileInstalledLegacyGeneration($existing, $event, $shape['key'], $generation);
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
                'source_event_kind' => 'event',
                'source_memory_id' => (int) $episode->id,
                'created_at' => $eventTime,
                'updated_at' => $eventTime,
            ], 'expired', TokenMemoryDaemon::operationKey('legacy-procedure-replace', (string) $event->id . ':' . $shape['key']));
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
                'operation_key' => TokenMemoryDaemon::operationKey('legacy-procedure-create', (string) $event->id . ':' . $shape['key']),
            ], true, true);
            $memory->save();
            $created = true;
        }
        $procedureEvent = $this->emitLegacyCompiled($event, $memory, $shape['key']);
        $this->commitProcedureGeneration($shape['key'], $generation);
        return ['memory' => $memory->getData(), 'event' => $procedureEvent, 'created' => $created];
    }

    public function observeLegacyFailure(string $shapeKey, int $generation, string $reason): void {
        $existing = $this->findLegacy($shapeKey, false);
        $seed = $existing instanceof Memory
            ? $this->generation($this->legacyEvidenceIds($existing))
            : 0;
        $claim = $this->claimProcedureGeneration($shapeKey, $generation, hash('sha256', $this->encode([ 'kind' => 'legacy-invalidation', 'generation' => $generation, 'reason' => $reason, ])), $seed);
        if ($claim === 'obsolete') {
            return;
        }
        if ($claim === 'blocked') {
            throw new RuntimeException('An earlier legacy observation must be replayed before this failure can advance it.');
        }

        $existing = $this->findLegacy($shapeKey, false);
        if ($existing instanceof Memory
            && $this->generation($this->legacyEvidenceIds($existing)) <= $generation
        ) {
            $this->expireMemoryForOperation($existing, TokenMemoryDaemon::operationKey( 'legacy-procedure-invalidate', (string) $generation . ':' . $shapeKey ));
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

    /** @return array<string, mixed>|null */
    public function reconcileInstalledLegacyGeneration(?Memory $memory, Event $sourceEvent, string $shapeKey, int $generation): ?array {
        if (!$memory instanceof Memory
            || (string) $memory->status !== 'active'
            || !$this->legacyMemorySourceMatches($memory, $sourceEvent, $shapeKey)
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

    public function legacyMemorySourceMatches(Memory $memory, Event $event, string $shapeKey): bool {
        if ((int) ($memory->source_event_id ?? 0) !== (int) $event->id) {
            return false;
        }
        return $memory->source_event_kind === 'event'
            || ($memory->source_event_kind === null
                && $this->hasLegacyCompiledReceipt($memory, $shapeKey));
    }

    public function legacySourceEvent(Memory $memory, string $shapeKey): ?Event {
        if ($memory->source_event_kind === 'event') {
            $event = MemorySource::inspect($memory->getData());
            return $event instanceof Event ? $event : null;
        }
        if ($memory->source_event_kind !== null
            || !$this->hasLegacyCompiledReceipt($memory, $shapeKey)
        ) {
            return null;
        }

        return Event::getByID((int) $memory->source_event_id);
    }

    public function hasLegacyCompiledReceipt(Memory $memory, string $shapeKey): bool {
        $sourceId = (int) ($memory->source_event_id ?? 0);
        if ($sourceId < 1 || $memory->tier !== 'procedural') {
            return false;
        }

        $receipt = Event::getByField('dedupe_key', 'procedure.compiled:' . $sourceId . ':' . $shapeKey);
        if (!$receipt instanceof Event || $receipt->kind !== 'procedure.compiled') {
            return false;
        }
        $payload = is_array($receipt->payload) ? $receipt->payload : [];
        return (int) ($payload['memory_id'] ?? 0) === (int) $memory->id
            && ($payload['procedure_key'] ?? null) === $shapeKey
            && ($payload['adapter_installed'] ?? null) === false
            && ($payload['action_ids'] ?? null) === $this->legacyEvidenceIds($memory);
    }

    /** @return array{key: string, description: string, expected: string} */
    public function legacyShape(string $description, string $expected): array {
        $description = $this->normalize($description);
        $expected = $this->normalize($expected);
        return [
            'key' => hash('sha256', $description . "\n" . $expected),
            'description' => $description,
            'expected' => $expected,
        ];
    }

    public function findLegacy(string $key, bool $activeOnly): ?Memory {
        $afterId = 0;
        $match = null;
        do {
            $page = Memory::inspectPage($afterId, 256, 'procedural', $activeOnly ? 'active' : null);
            foreach ($page as $memory) {
                $afterId = max($afterId, (int) $memory->id);
                if (preg_match(ProceduralMemory::LEGACY_MARKER, (string) $memory->content, $matches) === 1
                    && hash_equals($key, (string) $matches[1])
                ) {
                    $match = $memory;
                }
            }
        } while ($page !== []);

        if ($activeOnly && $match instanceof Memory) {
            Memory::observeRecords([$match]);
        }
        return $match;
    }

    /** @return list<int> */
    public function legacyEvidenceIds(Memory $memory): array {
        if (preg_match('/^Evidence: consecutive matched action traces ([0-9, ]+)$/m', (string) $memory->content, $matches) !== 1) {
            return [];
        }
        return array_values(array_map( 'intval', preg_split('/,\s*/', trim((string) $matches[1])) ?: [] ));
    }

    /** @return array<string, mixed> */
    public function emitLegacyCompiled(Event $sourceEvent, Memory $memory, string $shapeKey): array {
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
}
