<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Core\ExecutiveCore\Executive;
use InvalidArgumentException;
use NaviBrain\Model\CapsuleSlot;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Memory;
use NaviBrain\Model\WorkingMemorySlot;
use RuntimeException;

class WorkingMemory
{
    protected static array $componentTypes = [WorkingMemory\Maintenance::class, WorkingMemory\Projection::class];
    private array $components = [];

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

    private const PRIVACY_SAFE_ROLES = [
        'safety_notice',
        'standing_constraint',
        'self_model_caveat',
    ];

    public function __construct(private readonly Executive $core)
    {
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (method_exists($this, $method)) {
            return $this->$method(...$arguments);
        }
        foreach (static::$componentTypes as $class) {
            if (method_exists($class, $method)) {
                $component = $this->components[$class] ??= new $class($this);
                return $component->$method(...$arguments);
            }
        }
        throw new \BadMethodCallException('Unknown working-memory method: ' . $method);
    }

    /**
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

    /** @return array<string, mixed> */
    public function publish(WorkingMemorySlot $observation): array
    {
        if (!$observation->validate()) {
            throw new InvalidArgumentException(implode('; ', $observation->validationErrors));
        }
        $scope = (string) $observation->scope_key;
        $role = (string) $observation->slot_role;
        $payload = $observation->getData();
        unset($payload['id'], $payload['created_at'], $payload['projection_memory_id'], $payload['projection_pending']);
        $changed = $this->upsert($scope, $role, $payload, time());
        if ($changed) {
            $this->core->emitEvent('working_memory.published', [
                'scope' => $scope,
                'slot_role' => $role,
                'record_type' => $observation->record_type,
                'record_id' => $observation->record_id,
                'expires_at' => $observation->expires_at,
            ]);
        }
        $slot = $this->find($scope, $role);
        return $slot instanceof WorkingMemorySlot ? $this->serializeSlot($slot) : [];
    }

    /**
     * @param array{claim:string, confidence:float} $plan
     * @param array{claim:string, confidence:float} $basis
     */
    public function publishDecisionReasoning(int $cycleId, array $plan, array $basis): void
    {
        $payloads = ['current_plan' => $plan, 'decision_basis' => $basis];
        foreach ($payloads as $payload) {
            if (!is_string($payload['claim'] ?? null) || !is_numeric($payload['confidence'] ?? null)
                || !is_finite((float) $payload['confidence'])
                || $payload['confidence'] < 0.0 || $payload['confidence'] > 1.0) {
                throw new InvalidArgumentException('Invalid decision reasoning slot payload.');
            }
        }
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $pending = [];
            $published = [];
            $cycle = DecisionCycle::getByID($cycleId);
            if (!$cycle instanceof DecisionCycle || $cycle->status !== 'running' || $cycle->state !== 'reason') {
                throw new RuntimeException('Decision is no longer awaiting reasoning publication.');
            }
            $threadId = $cycle->thread_id === null ? null : (int) $cycle->thread_id;
            $scope = $threadId === null ? 'shared' : 'thread:' . $threadId;

            foreach ($payloads as $role => $payload) {
                $slot = $this->find($scope, $role);
                if ($slot instanceof WorkingMemorySlot && $this->projectionState($slot)['pending'] !== null) {
                    $pending[] = $slot;
                }
            }
            if ($pending === []) {
                $changes = $this->core->decisionStateMachine()->decisionInputChanges($cycleId);
                if ($changes !== []) {
                    throw new RuntimeException('Stale decision input; start a fresh cycle: ' . implode('; ', $changes));
                }
                $now = time();
                foreach ($payloads as $role => $payload) {
                    $claim = trim($payload['claim']);
                    if ($claim === '') {
                        continue;
                    }
                    $fields = [
                        'scope_key' => $scope, 'slot_role' => $role, 'status' => 'active',
                        'updated_at' => $now, 'thread_id' => $threadId, 'source_capsule_id' => null,
                        'record_type' => 'decision_cycle', 'record_id' => $cycleId,
                        'claim' => mb_substr($claim, 0, 800), 'confidence' => (float) $payload['confidence'],
                        'score' => (float) $payload['confidence'], 'recorded_at' => $now,
                        'carryover_depth' => 0, 'reserved' => 0, 'expires_at' => $now + 900,
                    ];
                    $slot = $this->find($scope, $role);
                    if ($slot instanceof WorkingMemorySlot) {
                        $slot->setFields($fields);
                        $slot->save();
                    } else {
                        $slot = new WorkingMemorySlot($fields, true, true);
                        $slot->save();
                    }
                    $this->installProjectionIntent($slot);
                    $published[] = $slot;
                }
            }
            foreach ($pending as $slot) {
                $this->replayProjection($slot);
            }
            if ($pending !== []) {
                continue;
            }
            foreach ($published as $slot) {
                try {
                    $this->replayProjection($slot);
                } catch (\Throwable $throwable) {

                    error_log('Decision workspace projection remains pending: ' . $throwable->getMessage());
                }
            }
            return;
        }
        throw new RuntimeException('Decision workspace projection remained busy after bounded retries.');
    }

    /** @return array<string, array<string, mixed>> */
    public function incumbents(int $threadId): array
    {
        $this->expireStale();
        $byRole = [];
        $memoryObservations = [];
        foreach ($this->activeInScope('thread:' . $threadId) as $slot) {
            $current = $this->serializeCurrentSlot($slot, $memoryObservations);
            if ($current !== null) {
                $byRole[(string) $slot->slot_role] = $current;
            }
        }
        return $byRole;
    }

    /**
     * @param array<int, array<string, mixed>|null>|null $memoryObservations
     * @return list<array<string, mixed>>
     */
    public function snapshot(?int $threadId = null, bool $privacySafe = false, ?array &$memoryObservations = null): array
    {

        $memoryObservations = [];
        $this->expireStale();
        $byRole = [];
        foreach ($this->activeInScope('shared') as $slot) {
            if ($privacySafe && !in_array($slot->slot_role, self::PRIVACY_SAFE_ROLES, true)) {
                continue;
            }

            if ($privacySafe && in_array($slot->record_type, ['memory', 'procedure'], true)) {
                continue;
            }
            $current = $this->serializeCurrentSlot($slot, $memoryObservations);
            if ($current !== null) {
                $byRole[(string) $slot->slot_role] = $current;
            }
        }
        if ($threadId !== null && !$privacySafe) {
            foreach ($this->activeInScope('thread:' . $threadId) as $slot) {
                $current = $this->serializeCurrentSlot($slot, $memoryObservations);
                if ($current !== null) {
                    $byRole[(string) $slot->slot_role] = $current;
                }
            }
        }
        ksort($byRole);
        return array_values($byRole);
    }

    /**
     * @param array<string, mixed> $inputRefs
     * @param array<int, array<string, mixed>|null>|null $memoryObservations
     * @return list<array<string, mixed>>
     */
    public function contextForWork(?int $parentIntentionId, array $inputRefs, ?array &$memoryObservations = null): array
    {
        $memoryObservations = [];
        $scope = (string) ($inputRefs['context_scope'] ?? '');
        if ($scope === 'no_workspace') {
            return [];
        }
        $privacySafe = $scope === 'privacy_safe_generic_self_presence_capsule';
        $threadId = isset($inputRefs['thread_id']) ? (int) $inputRefs['thread_id'] : null;
        if ($threadId === null && $parentIntentionId !== null) {
            foreach (CognitiveThread::getAllByWhere(['parent_intention_id' => $parentIntentionId], ['order' => ['updated_at' => 'DESC'], 'limit' => 1]) as $thread) {
                $threadId = (int) $thread->id;
            }
        }
        $snapshot = $this->snapshot($threadId, $privacySafe, $memoryObservations);
        if ($this->core->otherModel()->isAblated()) {
            $snapshot = array_values(array_filter( $snapshot, static fn (array $slot): bool => ($slot['slot_role'] ?? null) !== 'other_agent_state' ));
        }
        return $snapshot;
    }

    public function expireStale(?int $now = null): int
    {
        $now ??= time();
        $expired = 0;
        foreach (WorkingMemorySlot::getAll() as $slot) {
            if ($this->projectionState($slot)['pending'] !== null) {
                $this->replayProjection($slot);
            }
            $slot = WorkingMemorySlot::getByID((int) $slot->id);
            if (!$slot instanceof WorkingMemorySlot) {
                throw new RuntimeException('Working-memory slot disappeared during recovery.');
            }
            if ($slot->status !== 'active') {
                $this->synchronizeProjection($slot);
                continue;
            }
            $expiresAt = $this->timestamp($slot->expires_at);
            if ($expiresAt === null || $expiresAt > $now) {
                $this->synchronizeProjection($slot);
                continue;
            }
            $this->persistSlot((string) $slot->scope_key, (string) $slot->slot_role, ['status' => 'expired', 'updated_at' => $now]);
            $expired++;
        }

        foreach (Memory::inspectAllByWhere(['tier' => 'working', 'status' => 'active'], ['order' => ['updated_at' => 'ASC'], 'limit' => 100]) as $memory) {
            $identity = $this->identity((string) $memory->content);
            $expiresAt = $this->timestamp($memory->expires_at);
            if ($identity !== null && $expiresAt !== null && $expiresAt <= $now) {
                $lock = $this->acquireProjectionLock($identity['scope'], $identity['slot'], true);
                try {

                    if ($this->find($identity['scope'], $identity['slot']) instanceof WorkingMemorySlot) {
                        continue;
                    }
                    $fresh = Memory::inspectByID((int) $memory->id);
                    $expiry = $fresh instanceof Memory ? $this->timestamp($fresh->expires_at) : null;
                    if (!$fresh instanceof Memory || $fresh->status !== 'active'
                        || $expiry === null || $expiry > $now) {
                        continue;
                    }
                    $fresh->setFields(['status' => 'expired', 'updated_at' => $now]);
                    $fresh->setOperationKey($this->projectionOperationKey( 'expire-legacy', $identity['scope'], $identity['slot'], ['id' => (int) $fresh->id, 'status' => 'expired'] ));
                    $fresh->save();
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }
        return $expired;
    }

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
        $this->persistSlot($scope, $role, ['status' => 'expired', 'updated_at' => $now]);
        $this->core->emitEvent('working_memory.expired', [ 'scope' => $scope, 'slot_role' => $role, 'reason' => 'source_inactive', ]);
        return true;
    }

    /** @param array<string, mixed> $payload */
    private function upsert(string $scope, string $role, array $payload, int $now): bool
    {
        $slot = $this->find($scope, $role);
        $fields = array_merge($payload, [ 'scope_key' => $scope, 'slot_role' => $role, 'status' => 'active', 'updated_at' => $now, ]);
        $changed = !$slot instanceof WorkingMemorySlot
            || (string) $slot->claim !== (string) $payload['claim']
            || (string) $slot->record_type !== (string) ($payload['record_type'] ?? '')
            || (int) ($slot->record_id ?? 0) !== (int) ($payload['record_id'] ?? 0)
            || (float) $slot->confidence !== (float) $payload['confidence']
            || $slot->status !== 'active';
        $this->persistSlot($scope, $role, $fields);
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
            $this->persistSlot($scope, (string) $slot->slot_role, ['status' => 'expired', 'updated_at' => $now]);
            $changed = true;
        }
        return $changed;
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
        return WorkingMemorySlot::getAllByWhere(['scope_key' => $scope, 'status' => 'active'], ['order' => ['slot_role' => 'ASC']]);
    }

    /**
     * @param array<int, array<string, mixed>|null> $memoryObservations
     * @return array<string, mixed>|null
     */
    private function serializeCurrentSlot(WorkingMemorySlot $slot, array &$memoryObservations): ?array
    {
        $row = $this->serializeSlot($slot);
        if (!in_array($slot->record_type, ['memory', 'procedure'], true)) {
            return $row;
        }
        $id = (int) ($slot->record_id ?? 0);
        if (!array_key_exists($id, $memoryObservations)) {
            $memory = $id < 1 ? null : Memory::inspectByID($id);
            $memoryObservations[$id] = $memory instanceof Memory ? $memory->getData() : null;
        }
        $record = $memoryObservations[$id];
        if (!$this->eligibleBackingRecord((string) $slot->record_type, $record, time())) {
            return null;
        }
        $row['claim'] = mb_substr((string) $record['content'], 0, 800);
        $row['confidence'] = round((float) $record['confidence'], 4);
        $row['recorded_at'] = $record['updated_at'];
        return $row;
    }

    /** @param array<string, mixed>|null $record */
    private function eligibleBackingRecord(string $type, ?array $record, int $now): bool
    {
        $tiers = match ($type) {
            'procedure' => ['procedural'],
            'memory' => ['episodic', 'semantic', 'procedural'],
            default => [],
        };
        if ($record === null || ($record['status'] ?? null) !== 'active'
            || !in_array($record['tier'] ?? null, $tiers, true)) {
            return false;
        }
        $expiry = $record['expires_at'] ?? null;
        return $expiry === null || ($this->timestamp($expiry) ?? 0) > $now;
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
