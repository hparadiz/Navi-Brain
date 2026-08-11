<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use InvalidArgumentException;
use NaviBrain\Model\CapsuleSlot;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\Memory;
use NaviBrain\Model\WorkingMemorySlot;

/**
 * The bounded state hub between perception, memory, reasoning, and action.
 *
 * WorkingMemorySlot is canonical mutable state. Context capsules are immutable
 * snapshots of what one operation read, while Memory(tier=working) remains a
 * compatibility projection for generic recall and inspection.
 */
final class WorkingMemory
{
    private const MARKER = '/^\[workspace scope=([^\]]+) slot=([^\]]+)\] /';
    private const LEGACY_MARKER = '/^\[workspace thread=(\d+) slot=([^\]]+)\] /';

    /** Roles allowed to cross thread boundaries through the shared workspace. */
    private const SHARED_ROLES = [
        'safety_notice',
        'active_intention',
        'standing_constraint',
        'self_model_caveat',
        'applicable_procedure',
        'last_action_outcome',
        'newest_edge',
        'heard_focus',
        'recent_thought',
        'newest_percept',
        'reasoning_result',
        'other_agent_state',
    ];

    /** Raw user/system context must not leak into the generic self-presence path. */
    private const PRIVACY_SAFE_ROLES = [
        'safety_notice',
        'standing_constraint',
        'self_model_caveat',
    ];

    public function __construct(private readonly ExecutiveCore $core)
    {
    }

    /**
     * Commit a capsule's winners as the state read by the next cycle.
     *
     * @param list<CapsuleSlot> $slots
     * @return list<array<string, mixed>>
     */
    public function refresh(CognitiveThread $thread, array $slots, ?int $now = null): array
    {
        $now ??= time();
        $threadId = (int) $thread->id;
        $scope = 'thread:' . $threadId;
        $budget = is_array($thread->budget) ? $thread->budget : [];
        $pollSeconds = max(5, (int) ($budget['poll_seconds'] ?? 300));
        $expiresAt = $now + max(300, min(86400, $pollSeconds * 3));
        $activeRoles = [];
        $changed = false;

        foreach ($slots as $slot) {
            $role = trim((string) $slot->slot_role);
            $claim = trim((string) $slot->claim);
            if ($role === '' || $claim === '') {
                continue;
            }
            $activeRoles[$role] = true;
            $payload = [
                'thread_id' => $threadId,
                'source_capsule_id' => (int) $slot->capsule_id,
                'record_type' => $slot->record_type,
                'record_id' => $slot->record_id === null ? null : (int) $slot->record_id,
                'claim' => $claim,
                'confidence' => (float) $slot->confidence,
                'score' => (float) $slot->score,
                'recorded_at' => $slot->recorded_at,
                'carryover_depth' => (int) $slot->carryover_depth,
                'reserved' => (int) $slot->reserved,
                'expires_at' => $expiresAt,
            ];
            $changed = $this->upsert($scope, $role, $payload, $now) || $changed;

            if (in_array($role, self::SHARED_ROLES, true)) {
                $changed = $this->upsertShared($role, $payload, $now) || $changed;
            }
        }

        $changed = $this->expireMissing($scope, array_keys($activeRoles), $now) || $changed;
        $this->expireStale($now);

        if ($changed) {
            $this->core->emitEvent('working_memory.changed', [
                'thread_id' => $threadId,
                'scope' => $scope,
                'slots' => array_keys($activeRoles),
                'capacity' => count($slots),
                'filled' => count($activeRoles),
                'expires_at' => $expiresAt,
            ]);
        }

        return $this->snapshot($threadId);
    }

    /**
     * Put an observation or bounded reasoning result directly into the hub.
     *
     * @return array<string, mixed>
     */
    public function publish(
        string $role,
        string $claim,
        string $recordType,
        ?int $recordId,
        float $confidence,
        int $ttlSeconds = 900,
        string $scope = 'shared',
        ?int $threadId = null,
        bool $reserved = false,
        ?int $now = null
    ): array {
        $now ??= time();
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $role) !== 1) {
            throw new InvalidArgumentException('working-memory role must be a lowercase identifier.');
        }
        if ($scope !== 'shared' && preg_match('/^thread:\d+$/', $scope) !== 1) {
            throw new InvalidArgumentException('working-memory scope must be shared or thread:<id>.');
        }
        $claim = trim($claim);
        if ($claim === '') {
            throw new InvalidArgumentException('working-memory claim cannot be empty.');
        }
        $confidence = max(0.0, min(1.0, $confidence));
        $ttlSeconds = max(30, min(86400, $ttlSeconds));
        $payload = [
            'thread_id' => $threadId,
            'source_capsule_id' => null,
            'record_type' => trim($recordType) === '' ? null : trim($recordType),
            'record_id' => $recordId,
            'claim' => mb_substr($claim, 0, 800),
            'confidence' => $confidence,
            'score' => $confidence,
            'recorded_at' => $now,
            'carryover_depth' => 0,
            'reserved' => $reserved ? 1 : 0,
            'expires_at' => $now + $ttlSeconds,
        ];
        $changed = $this->upsert($scope, $role, $payload, $now);
        if ($changed) {
            $this->core->emitEvent('working_memory.published', [
                'scope' => $scope,
                'slot_role' => $role,
                'record_type' => $recordType,
                'record_id' => $recordId,
                'expires_at' => $payload['expires_at'],
            ]);
        }
        $slot = $this->find($scope, $role);
        return $slot instanceof WorkingMemorySlot ? $this->serializeSlot($slot) : [];
    }

    /**
     * Current state used as incumbents in the next capsule competition.
     *
     * @return array<string, array<string, mixed>>
     */
    public function incumbents(int $threadId): array
    {
        $this->expireStale();
        $byRole = [];
        foreach ($this->activeInScope('thread:' . $threadId) as $slot) {
            if (in_array($slot->record_type, ['memory', 'procedure'], true)) {
                $memory = $slot->record_id === null ? null : Memory::getByID((int) $slot->record_id);
                if (!$memory instanceof Memory || $memory->status !== 'active' || $memory->tier === 'working') {
                    continue;
                }
            }
            $byRole[(string) $slot->slot_role] = $this->serializeSlot($slot);
        }
        return $byRole;
    }

    /**
     * Uniform prompt payload. Thread state overrides a shared role of the same
     * name, keeping the result bounded to one occupant per role.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshot(?int $threadId = null, bool $privacySafe = false): array
    {
        $this->expireStale();
        $byRole = [];
        foreach ($this->activeInScope('shared') as $slot) {
            if ($privacySafe && !in_array($slot->slot_role, self::PRIVACY_SAFE_ROLES, true)) {
                continue;
            }
            $byRole[(string) $slot->slot_role] = $this->serializeSlot($slot);
        }
        if ($threadId !== null && !$privacySafe) {
            foreach ($this->activeInScope('thread:' . $threadId) as $slot) {
                $byRole[(string) $slot->slot_role] = $this->serializeSlot($slot);
            }
        }
        ksort($byRole);
        return array_values($byRole);
    }

    /** @param array<string, mixed> $inputRefs
     *  @return list<array<string, mixed>>
     */
    public function contextForWork(?int $parentIntentionId, array $inputRefs): array
    {
        $scope = (string) ($inputRefs['context_scope'] ?? '');
        if ($scope === 'no_workspace') {
            return [];
        }
        $privacySafe = $scope === 'privacy_safe_generic_self_presence_capsule';
        $threadId = isset($inputRefs['thread_id']) ? (int) $inputRefs['thread_id'] : null;
        if ($threadId === null && $parentIntentionId !== null) {
            foreach (CognitiveThread::getAllByWhere(
                ['parent_intention_id' => $parentIntentionId],
                ['order' => ['updated_at' => 'DESC'], 'limit' => 1]
            ) as $thread) {
                $threadId = (int) $thread->id;
            }
        }
        $snapshot = $this->snapshot($threadId, $privacySafe);
        if ($this->core->otherModel()->isAblated()) {
            $snapshot = array_values(array_filter(
                $snapshot,
                static fn (array $slot): bool => ($slot['slot_role'] ?? null) !== 'other_agent_state'
            ));
        }
        return $snapshot;
    }

    public function expireStale(?int $now = null): int
    {
        $now ??= time();
        $expired = 0;
        foreach (WorkingMemorySlot::getAllByWhere(['status' => 'active']) as $slot) {
            $expiresAt = $this->timestamp($slot->expires_at);
            if ($expiresAt === null || $expiresAt > $now) {
                continue;
            }
            $slot->setFields(['status' => 'expired', 'updated_at' => $now]);
            $slot->save();
            $this->expireProjection((string) $slot->scope_key, (string) $slot->slot_role, $now);
            $expired++;
        }
        // Retire legacy projections that predate the canonical table.
        foreach (Memory::getAllByWhere(['tier' => 'working', 'status' => 'active']) as $memory) {
            $identity = $this->identity((string) $memory->content);
            $expiresAt = $this->timestamp($memory->expires_at);
            if ($identity !== null && $expiresAt !== null && $expiresAt <= $now) {
                $memory->setFields(['status' => 'expired', 'updated_at' => $now]);
                $memory->save();
            }
        }
        return $expired;
    }

    /** Expire one projection immediately when its authority or evidence ends. */
    public function expireRole(string $role, string $scope = 'shared', ?int $now = null): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $role) !== 1) {
            throw new InvalidArgumentException('working-memory role must be a lowercase identifier.');
        }
        if ($scope !== 'shared' && preg_match('/^thread:\d+$/', $scope) !== 1) {
            throw new InvalidArgumentException('working-memory scope must be shared or thread:<id>.');
        }
        $slot = $this->find($scope, $role);
        if (!$slot instanceof WorkingMemorySlot || $slot->status !== 'active') {
            return false;
        }
        $now ??= time();
        $slot->setFields(['status' => 'expired', 'updated_at' => $now]);
        $slot->save();
        $this->expireProjection($scope, $role, $now);
        $this->core->emitEvent('working_memory.expired', [
            'scope' => $scope,
            'slot_role' => $role,
            'reason' => 'source_inactive',
        ]);
        return true;
    }

    /** @param array<string, mixed> $payload */
    private function upsert(string $scope, string $role, array $payload, int $now): bool
    {
        $slot = $this->find($scope, $role);
        $fields = array_merge($payload, [
            'scope_key' => $scope,
            'slot_role' => $role,
            'status' => 'active',
            'updated_at' => $now,
        ]);
        $changed = !$slot instanceof WorkingMemorySlot
            || (string) $slot->claim !== (string) $payload['claim']
            || (string) $slot->record_type !== (string) ($payload['record_type'] ?? '')
            || (int) ($slot->record_id ?? 0) !== (int) ($payload['record_id'] ?? 0)
            || (float) $slot->confidence !== (float) $payload['confidence']
            || $slot->status !== 'active';
        if ($slot instanceof WorkingMemorySlot) {
            $slot->setFields($fields);
            $slot->save();
        } else {
            /** @var WorkingMemorySlot $slot */
            $slot = $this->core->insertRecord(WorkingMemorySlot::class, $fields);
        }
        $this->project($slot, $now);
        return $changed;
    }

    /** @param array<string, mixed> $payload */
    private function upsertShared(string $role, array $payload, int $now): bool
    {
        $current = $this->find('shared', $role);
        if ($current instanceof WorkingMemorySlot && $current->status === 'active') {
            $currentAt = $this->timestamp($current->recorded_at) ?? 0;
            $candidateAt = $this->timestamp($payload['recorded_at'] ?? null) ?? 0;
            $wins = (int) $payload['reserved'] === 1
                || $candidateAt > $currentAt
                || ($candidateAt === $currentAt && (float) $payload['score'] >= (float) $current->score);
            if (!$wins) {
                return false;
            }
        }
        $payload['thread_id'] = null;
        return $this->upsert('shared', $role, $payload, $now);
    }

    /** @param list<string> $activeRoles */
    private function expireMissing(string $scope, array $activeRoles, int $now): bool
    {
        $active = array_fill_keys($activeRoles, true);
        $changed = false;
        foreach ($this->activeInScope($scope) as $slot) {
            if (isset($active[(string) $slot->slot_role])) {
                continue;
            }
            $slot->setFields(['status' => 'expired', 'updated_at' => $now]);
            $slot->save();
            $this->expireProjection($scope, (string) $slot->slot_role, $now);
            $changed = true;
        }
        return $changed;
    }

    private function project(WorkingMemorySlot $slot, int $now): void
    {
        $scope = (string) $slot->scope_key;
        $role = (string) $slot->slot_role;
        $memory = $this->findProjection($scope, $role);
        $content = sprintf('[workspace scope=%s slot=%s] %s', $scope, $role, (string) $slot->claim);
        $fields = [
            'tier' => 'working',
            'content' => $content,
            'confidence' => (float) $slot->confidence,
            'status' => 'active',
            'expires_at' => $slot->expires_at,
            'updated_at' => $now,
        ];
        if ($memory instanceof Memory) {
            $memory->setFields($fields);
            $memory->save();
            return;
        }
        $this->core->insertRecord(Memory::class, $fields);
    }

    private function expireProjection(string $scope, string $role, int $now): void
    {
        $memory = $this->findProjection($scope, $role);
        if ($memory instanceof Memory) {
            $memory->setFields(['status' => 'expired', 'updated_at' => $now]);
            $memory->save();
        }
    }

    private function find(string $scope, string $role): ?WorkingMemorySlot
    {
        foreach (WorkingMemorySlot::getAllByWhere(['scope_key' => $scope, 'slot_role' => $role], ['limit' => 1]) as $slot) {
            return $slot;
        }
        return null;
    }

    /** @return list<WorkingMemorySlot> */
    private function activeInScope(string $scope): array
    {
        return WorkingMemorySlot::getAllByWhere(
            ['scope_key' => $scope, 'status' => 'active'],
            ['order' => ['slot_role' => 'ASC']]
        );
    }

    private function findProjection(string $scope, string $role): ?Memory
    {
        // Reuse the same compatibility row after expiry. Otherwise a slot that
        // wakes, expires, and wakes again slowly turns the working tier into a
        // transcript even though WorkingMemorySlot itself is correctly unique.
        foreach (Memory::getAllByWhere(['tier' => 'working']) as $memory) {
            $identity = $this->identity((string) $memory->content);
            if ($identity !== null && $identity['scope'] === $scope && $identity['slot'] === $role) {
                return $memory;
            }
        }
        return null;
    }

    /** @return array{scope: string, slot: string}|null */
    private function identity(string $content): ?array
    {
        if (preg_match(self::MARKER, $content, $matches) === 1) {
            return ['scope' => (string) $matches[1], 'slot' => (string) $matches[2]];
        }
        if (preg_match(self::LEGACY_MARKER, $content, $matches) === 1) {
            return ['scope' => 'thread:' . (int) $matches[1], 'slot' => (string) $matches[2]];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function serializeSlot(WorkingMemorySlot $slot): array
    {
        return [
            'scope' => (string) $slot->scope_key,
            'slot_role' => (string) $slot->slot_role,
            'record_type' => $slot->record_type,
            'record_id' => $slot->record_id === null ? null : (int) $slot->record_id,
            'claim' => $slot->claim,
            'confidence' => round((float) $slot->confidence, 4),
            'score' => round((float) $slot->score, 4),
            'recorded_at' => $slot->recorded_at,
            'carryover_depth' => (int) $slot->carryover_depth,
            'reserved' => (int) $slot->reserved === 1,
            'expires_at' => $slot->expires_at,
        ];
    }

    private function timestamp(mixed $value): ?int
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
}
