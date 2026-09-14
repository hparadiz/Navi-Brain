<?php

declare(strict_types=1);

namespace NaviBrain\Core\DecisionStateMachine;

use NaviBrain\Core\DecisionPreparationChanged;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\SensorySource;
use NaviBrain\Support\PlainText;
use RuntimeException;

class Dependencies
{
    /**
     * @param list<array<string, mixed>> $workspace
     * @param array<int, array<string, mixed>|null> $memoryObservations
     */
    public static function prepareWorkspaceDependencies(int $cycleId, array &$workspace, array $memoryObservations = []): array
    {
        $cycle = DecisionCycle::getByID($cycleId);
        if (!$cycle instanceof DecisionCycle) {
            throw new RuntimeException('Cannot capture inputs for a missing decision cycle.');
        }
        if ($cycle->status !== 'running' || $cycle->state !== 'reason'
            || $cycle->proposal_work_item_id !== null) {
            throw new DecisionPreparationChanged('Decision cycle is not preparing its first reasoning work item.');
        }
        $observations = (array) $cycle->observations;
        $originalHash = hash('sha256', serialize($observations));
        $snapshot = $observations['input_snapshot'] ?? null;
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1) {
            throw new RuntimeException('Decision input snapshot is missing; start a fresh cycle.');
        }
        foreach ($workspace as &$slot) {
            $type = (string) ($slot['record_type'] ?? '');
            $type = match ($type) {
                'procedure' => 'memory',
                'sense_edge' => 'sense_event',
                default => $type,
            };
            if (in_array($type, ['memory', 'intention', 'sense_event'], true)) {
                $id = (int) ($slot['record_id'] ?? 0);
                if ($type === 'memory' && array_key_exists($id, $memoryObservations)) {
                    $observed = $memoryObservations[$id];
                    if ($observed !== null && (!is_array($observed)
                        || (int) ($observed['id'] ?? 0) !== $id)) {
                        throw new RuntimeException('Workspace source observation has a mismatched identity.');
                    }
                    $data = $observed === null ? null : static::dependencyData($type, $id, $observed);
                } else {
                    $data = static::dependencyData($type, $id);
                }
                if (!isset($snapshot['dependencies'][$type . ':' . $id])) {
                    $snapshot['dependencies'][$type . ':' . $id] = [
                        'type' => $type, 'id' => $id, 'hash' => hash('sha256', static::encode($data)),
                    ];
                }

                if ($data !== null) {
                    $slot['claim'] = match ($type) {
                        'memory' => mb_substr((string) $data['content'], 0, 800),
                        'sense_event' => mb_substr((string) $data['summary'], 0, 800),
                        'intention' => PlainText::render($data, 3000, 12),
                    };
                    if ($type === 'memory') {
                        $slot['confidence'] = (float) $data['confidence'];
                    }
                }
            }
        }
        unset($slot);
        $observations['input_snapshot'] = $snapshot;
        return [
            'cycle_id' => $cycleId,
            'intention_id' => (int) $cycle->intention_id,
            'thread_id' => $cycle->thread_id === null ? null : (int) $cycle->thread_id,
            'original_observations_hash' => $originalHash,
            'observations' => $observations,
        ];
    }

    /** @return list<string> */
    public static function decisionInputChanges(int $cycleId): array
    {
        $cycle = DecisionCycle::getByID($cycleId);
        if (!$cycle instanceof DecisionCycle) {
            return ['decision cycle is missing'];
        }
        $snapshot = ((array) $cycle->observations)['input_snapshot'] ?? null;
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1
            || !is_array($snapshot['dependencies'] ?? null)
            || !isset($snapshot['dependencies']['intention:' . (int) $cycle->intention_id])) {
            return ['decision input snapshot is missing or unsupported'];
        }
        $changes = [];
        foreach ($snapshot['dependencies'] as $key => $dependency) {
            if (!is_array($dependency)
                || !is_string($dependency['type'] ?? null)
                || !in_array($dependency['type'], ['intention', 'memory', 'sense_event'], true)
                || !is_int($dependency['id'] ?? null) || $dependency['id'] < 1
                || $key !== $dependency['type'] . ':' . $dependency['id']
                || !is_string($dependency['hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $dependency['hash']) !== 1) {
                $changes[] = 'decision input snapshot contains a malformed dependency';
                continue;
            }
            $current = static::dependencyData((string) $dependency['type'], (int) $dependency['id']);
            if ($current === null || !hash_equals((string) $dependency['hash'], hash('sha256', static::encode($current)))) {
                $changes[] = $key . ' changed or disappeared';
            } elseif ($dependency['type'] === 'memory' && ($current['status'] ?? null) !== 'active') {
                $changes[] = $key . ' is not active';
            } elseif ($dependency['type'] === 'memory' && !$current['expiry_valid']) {
                $changes[] = $key . ' has an invalid expiry';
            } elseif ($dependency['type'] === 'memory' && $current['expires_at'] !== null
                && $current['expires_at'] <= time()) {
                $changes[] = $key . ' has expired';
            }
        }
        $intention = Intention::getByID((int) $cycle->intention_id);
        if (!$intention instanceof Intention || $intention->status !== 'active') {
            $changes[] = 'intention is no longer active';
        }
        if ($cycle->thread_id !== null) {
            $thread = CognitiveThread::getByID((int) $cycle->thread_id);
            if (!$thread instanceof CognitiveThread || (int) $thread->parent_intention_id !== (int) $cycle->intention_id) {
                $changes[] = 'decision thread ownership changed';
            }
        }
        return $changes;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed>|null $supplied
     */
    public static function captureDependency(array &$snapshot, string $type, int $id, ?array $supplied = null): void
    {
        if ($id < 1 || isset($snapshot['dependencies'][$type . ':' . $id])) {
            return;
        }
        $data = static::dependencyData($type, $id, $supplied);
        $snapshot['dependencies'][$type . ':' . $id] = [
            'type' => $type, 'id' => $id, 'hash' => hash('sha256', static::encode($data)),
        ];
    }

    /**
     * @param array<string, mixed>|null $supplied
     * @return array<string, mixed>|null
     */
    public static function dependencyData(string $type, int $id, ?array $supplied = null): ?array
    {
        $fields = match ($type) {
            'intention' => ['title', 'reason', 'authority', 'status', 'next_action',
                'success_condition', 'release_condition', 'dependencies', 'parent_id'],
            'memory' => ['tier', 'status', 'content', 'confidence', 'source_event_id', 'source_event_kind',
                'source_memory_id', 'supersedes_id', 'expires_at'],

            'sense_event' => ['sense_key', 'source_key', 'summary', 'before', 'after', 'reading_id'],
            default => throw new RuntimeException('Unsupported decision input dependency: ' . $type),
        };
        if ($supplied === null) {
            $record = match ($type) {
                'intention' => Intention::getByID($id),
                'memory' => Memory::inspectByID($id),
                'sense_event' => SenseEvent::getByID($id),
            };
            if ($record === null) {
                return null;
            }
            $supplied = $record->getData();
        }
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $supplied[$field] ?? null;
        }
        if ($type === 'memory') {
            $expiresAt = static::timestamp($data['expires_at']);
            $data['expiry_valid'] = $data['expires_at'] === null || $expiresAt !== null;
            $data['expires_at'] = $expiresAt;
        }
        if ($type === 'sense_event') {
            $sources = SensorySource::getAllByWhere(['source_key' => (string) ($supplied['source_key'] ?? '')], ['limit' => 1]);
            $source = $sources[0] ?? null;
            $data['source_grant'] = $source instanceof SensorySource ? [
                'authority' => $source->authority, 'status' => $source->status,
                'reveals' => $source->reveals, 'effect_ceiling' => $source->effect_ceiling,
                'acquisition' => $source->acquisition,
            ] : null;
        }
        return $data;
    }

    public static function timestamp(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $parsed = strtotime($value);
        return $parsed === false ? null : $parsed;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
