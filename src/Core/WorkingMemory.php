<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use Divergence\IO\Database\Connections;
use InvalidArgumentException;
use NaviBrain\Model\CapsuleSlot;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Memory;
use NaviBrain\Model\WorkingMemorySlot;
use NaviBrain\Storage\TokenMemoryDaemon;
use PDO;
use RuntimeException;

/**
 * The bounded state hub between perception, memory, reasoning, and action.
 *
 * WorkingMemorySlot is canonical mutable state. Context capsules are immutable
 * snapshots of what one operation read, while Memory(tier=working) remains a
 * compatibility projection for generic recall and inspection.
 */
final class WorkingMemory
{
    private int $maintenanceCursor = 0;
    private int $maintenancePriorityCursor = 0;
    private int $maintenanceAuditCursor = 0;
    private bool $maintenanceAuditNext = false;

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
     * Commit both reasoning roles and projection intents under the same SQLite
     * freshness fence. Native projection writes happen only after that commit.
     *
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
        $connection = Connections::getConnection();
        if ($connection->inTransaction()) {
            throw new RuntimeException('Decision publication cannot run inside an application transaction.');
        }
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $pending = [];
            $published = [];
            $connection->exec('BEGIN IMMEDIATE');
            try {
                $cycle = DecisionCycle::getByID($cycleId);
                if (!$cycle instanceof DecisionCycle || $cycle->status !== 'running' || $cycle->state !== 'reason') {
                    throw new RuntimeException('Decision is no longer awaiting reasoning publication.');
                }
                $threadId = $cycle->thread_id === null ? null : (int) $cycle->thread_id;
                $scope = $threadId === null ? 'shared' : 'thread:' . $threadId;
                // A writer cannot replace a canonical row until its prior
                // projection intent is replayed. Check both before either write.
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
                            $slot = $this->core->insertRecord(WorkingMemorySlot::class, $fields);
                        }
                        $this->installProjectionIntent($slot);
                        $published[] = $slot;
                    }
                }
                $connection->commit();
            } catch (\Throwable $throwable) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $throwable;
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
                    // Canonical publication already committed. Leave its durable
                    // intent for normal recovery; do not falsely reject it.
                    error_log('Decision workspace projection remains pending: ' . $throwable->getMessage());
                }
            }
            return;
        }
        throw new RuntimeException('Decision workspace projection remained busy after bounded retries.');
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
     * Uniform prompt payload. Thread state overrides a shared role of the same
     * name, keeping the result bounded to one occupant per role. The optional
     * observation output retains full source records separately from slot text
     * for queue-time fingerprints; it must not be sent to the model.
     *
     * @param array<int, array<string, mixed>|null>|null $memoryObservations
     * @return list<array<string, mixed>>
     */
    public function snapshot(
        ?int $threadId = null,
        bool $privacySafe = false,
        ?array &$memoryObservations = null
    ): array
    {
        // Invocation-local observation only; never retain across decision gates.
        $memoryObservations = [];
        $this->expireStale();
        $byRole = [];
        foreach ($this->activeInScope('shared') as $slot) {
            if ($privacySafe && !in_array($slot->slot_role, self::PRIVACY_SAFE_ROLES, true)) {
                continue;
            }
            // A permitted role can contain a redacted summary of private
            // evidence; source hydration must not undo that redaction.
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

    /** @param array<string, mixed> $inputRefs
     *  @param array<int, array<string, mixed>|null>|null $memoryObservations
     *  @return list<array<string, mixed>>
     */
    public function contextForWork(
        ?int $parentIntentionId,
        array $inputRefs,
        ?array &$memoryObservations = null
    ): array
    {
        $memoryObservations = [];
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
        $snapshot = $this->snapshot($threadId, $privacySafe, $memoryObservations);
        if ($this->core->otherModel()->isAblated()) {
            $snapshot = array_values(array_filter(
                $snapshot,
                static fn (array $slot): bool => ($slot['slot_role'] ?? null) !== 'other_agent_state'
            ));
        }
        return $snapshot;
    }

    /**
     * Bounded local upkeep. Pointerless legacy projections need an unbounded
     * adoption scan in findProjection, so leave them to explicit/foreground
     * recovery rather than hiding that work in this batch.
     *
     * @return array<string, mixed>
     */
    public function maintainBatch(int $limit = 32): array
    {
        if ($limit < 1 || $limit > 128) {
            throw new InvalidArgumentException('Workspace maintenance limit must be between 1 and 128.');
        }
        $connection = Connections::getConnection();
        if ($connection->inTransaction()) {
            throw new RuntimeException('Workspace maintenance requires its own transaction boundaries.');
        }
        $slots = $this->selectMaintenanceBatch($limit);
        $result = ['visited' => 0, 'replayed' => 0, 'expired' => 0, 'refreshed' => 0,
            'unchanged' => 0, 'raced' => 0, 'deferred_legacy' => 0, 'errors' => []];
        foreach ($slots as $slot) {
            $id = (int) $slot->id;
            $this->maintenanceCursor = $id;
            $result['visited']++;
            try {
                $state = $this->projectionState($slot);
                if ($state['memory_id'] === null) {
                    $result['deferred_legacy']++;
                    continue;
                }
                if ($state['pending'] !== null) {
                    $this->replayProjection($slot, false);
                    $result['replayed']++;
                }
                $slot = WorkingMemorySlot::getByID($id);
                if (!$slot instanceof WorkingMemorySlot) {
                    throw new RuntimeException('Workspace maintenance source slot disappeared.');
                }
                $fields = [];
                $now = time();
                $change = 'unchanged';
                if ($slot->status === 'active') {
                    $expiry = $slot->expires_at;
                    if ($expiry !== null && (($this->timestamp($expiry) ?? 0) <= $now)) {
                        $fields = ['status' => 'expired'];
                    } elseif (in_array($slot->record_type, ['memory', 'procedure'], true)) {
                        $sourceId = (int) ($slot->record_id ?? 0);
                        $source = $sourceId < 1 ? null : Memory::inspectByID($sourceId);
                        if (!$this->eligibleBackingRecord((string) $slot->record_type,
                            $source instanceof Memory ? $source->getData() : null, $now)) {
                            $fields = ['status' => 'expired'];
                        } else {
                            $claim = mb_substr((string) $source->content, 0, 800);
                            $confidence = round((float) $source->confidence, 4);
                            if ((string) $slot->claim !== $claim || (float) $slot->confidence !== $confidence) {
                                $fields = ['claim' => $claim, 'confidence' => $confidence];
                            }
                        }
                        unset($source);
                    }
                    if ($fields !== []) {
                        $change = isset($fields['status']) ? 'expired' : 'refreshed';
                        $fields['updated_at'] = $now;
                    }
                }
                if ($fields !== []) {
                    $fresh = $this->maintainSlotIfUnchanged($slot, $fields);
                    if ($fresh === null) {
                        $result['raced']++;
                        continue;
                    }
                    $slot = $fresh;
                }
                // Canonical mutation, when needed, has committed its journal.
                // Synchronization keeps native writes outside SQLite.
                $this->synchronizeProjection($slot, false);
                $result[$change]++;
            } catch (\Throwable $error) {
                $result['errors'][] = ['slot_id' => $id, 'error' => $error->getMessage()];
            }
        }
        // Legacy field remains the last actually visited ID, not either
        // queue's high-water mark. The independent cursors are explicit below.
        $result['after_id'] = $this->maintenanceCursor;
        $result['priority_after_id'] = $this->maintenancePriorityCursor;
        $result['audit_after_id'] = $this->maintenanceAuditCursor;
        return $result;
    }

    /**
     * Selection progress survives --once and process replacement. This short
     * transaction contains only SQLite reads/writes; projection/source work
     * starts after commit and keeps its independent identity checks.
     *
     * @return list<WorkingMemorySlot>
     */
    private function selectMaintenanceBatch(int $limit): array
    {
        $connection = Connections::getConnection();
        $connection->exec('BEGIN IMMEDIATE');
        try {
            $query = $connection->query(
                'SELECT priority_after_id,audit_after_id,audit_next FROM workspace_maintenance_scan WHERE id = 1'
            );
            $state = $query->fetch(PDO::FETCH_ASSOC);
            $query->closeCursor();
            if (!is_array($state)) {
                throw new RuntimeException('Workspace maintenance cursor is missing.');
            }
            $this->maintenancePriorityCursor = (int) $state['priority_after_id'];
            $this->maintenanceAuditCursor = (int) $state['audit_after_id'];
            $this->maintenanceAuditNext = (int) $state['audit_next'] === 1;
            if ($limit === 1) {
                $priority = !$this->maintenanceAuditNext;
                $this->maintenanceAuditNext = !$this->maintenanceAuditNext;
                $slots = $this->maintenancePage($priority, 1);
                if ($slots === []) {
                    $slots = $this->maintenancePage(!$priority, 1);
                }
            } else {
                // Preserve active/journal priority and a retired-history audit
                // allowance. Both predicates observe the same SQL snapshot.
                $slots = $this->maintenancePage(true, $limit - 1);
                $audit = $this->maintenancePage(false, $limit - count($slots));
                $slots = array_merge($slots, $audit);
            }
            $save = $connection->prepare(
                'UPDATE workspace_maintenance_scan SET priority_after_id = :priority,
                 audit_after_id = :audit, audit_next = :next WHERE id = 1'
            );
            $save->execute(['priority' => $this->maintenancePriorityCursor,
                'audit' => $this->maintenanceAuditCursor, 'next' => $this->maintenanceAuditNext ? 1 : 0]);
            if ($save->rowCount() !== 1) {
                throw new RuntimeException('Workspace maintenance cursor could not advance.');
            }
            $connection->commit();
            return $slots;
        } catch (\Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<WorkingMemorySlot> */
    private function maintenancePage(bool $priority, int $limit): array
    {
        $cursor = $priority ? $this->maintenancePriorityCursor : $this->maintenanceAuditCursor;
        // Keep these predicates identical to the Schema23 partial indexes.
        // Inactive rows with pending journals remain in the priority queue.
        $predicate = $priority ? "(status = 'active' OR projection_pending IS NOT NULL)"
            : "(status <> 'active' AND projection_pending IS NULL)";
        $read = static fn (int $after): array => WorkingMemorySlot::getAllByWhere(
            ['id > ' . $after, $predicate], ['order' => ['id' => 'ASC'], 'limit' => $limit]
        );
        $rows = $read($cursor);
        if ($rows === [] && $cursor > 0) {
            $cursor = 0;
            $rows = $read(0);
        }
        if ($rows !== []) {
            $cursor = (int) $rows[array_key_last($rows)]->id;
        }
        if ($priority) {
            $this->maintenancePriorityCursor = $cursor;
        } else {
            $this->maintenanceAuditCursor = $cursor;
        }
        return $rows;
    }

    /** @param array<string, mixed> $fields */
    private function maintainSlotIfUnchanged(WorkingMemorySlot $observed, array $fields): ?WorkingMemorySlot
    {
        $connection = Connections::getConnection();
        $expected = serialize($observed->getData());
        $connection->exec('BEGIN IMMEDIATE');
        try {
            $current = WorkingMemorySlot::getByID((int) $observed->id);
            $state = $current instanceof WorkingMemorySlot ? $this->projectionState($current) : null;
            if (!$current instanceof WorkingMemorySlot
                || serialize($current->getData()) !== $expected
                || $state['pending'] !== null || $state['memory_id'] === null) {
                $connection->commit();
                return null;
            }
            $current->setFields($fields);
            $current->save();
            $this->installProjectionIntent($current);
            $connection->commit();
            return $current;
        } catch (\Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
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
            $this->persistSlot(
                (string) $slot->scope_key,
                (string) $slot->slot_role,
                ['status' => 'expired', 'updated_at' => $now]
            );
            $expired++;
        }
        // Retire legacy projections that predate the canonical table.
        foreach (Memory::inspectAllByWhere(
            ['tier' => 'working', 'status' => 'active'],
            ['order' => ['updated_at' => 'ASC'], 'limit' => 100]
        ) as $memory) {
            $identity = $this->identity((string) $memory->content);
            $expiresAt = $this->timestamp($memory->expires_at);
            if ($identity !== null && $expiresAt !== null && $expiresAt <= $now) {
                $lock = $this->acquireProjectionLock($identity['scope'], $identity['slot'], true);
                try {
                    // A canonical slot owns its projection lifecycle. Recheck
                    // after ownership so adoption or a TTL refresh cannot race
                    // this legacy-only retirement.
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
                    $fresh->saveWithOperation($this->projectionOperationKey(
                        'expire-legacy', $identity['scope'], $identity['slot'],
                        ['id' => (int) $fresh->id, 'status' => 'expired']
                    ));
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
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
        $this->persistSlot($scope, $role, ['status' => 'expired', 'updated_at' => $now]);
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
            $this->persistSlot(
                $scope,
                (string) $slot->slot_role,
                ['status' => 'expired', 'updated_at' => $now]
            );
            $changed = true;
        }
        return $changed;
    }

    /**
     * Canonical slot mutation and the minimal projection intent commit in one
     * SQLite writer transaction. The token operation always runs after commit.
     * A concurrent writer must finish the existing intent before changing the
     * canonical slot, so the digest always reconstructs one exact projection.
     *
     * @param array<string, mixed> $fields
     */
    private function persistSlot(string $scope, string $role, array $fields): WorkingMemorySlot
    {
        $connection = Connections::getConnection();
        if ($connection->inTransaction()) {
            throw new RuntimeException(
                'Working-memory projection cannot mutate inside an application transaction.'
            );
        }

        for ($attempt = 0; $attempt < 16; $attempt++) {
            $slot = $this->find($scope, $role);
            if ($slot instanceof WorkingMemorySlot
                && $this->projectionState($slot)['pending'] !== null
            ) {
                $this->replayProjection($slot);
                continue;
            }

            $replay = null;
            $connection->exec('BEGIN IMMEDIATE');
            try {
                $slot = $this->find($scope, $role);
                if ($slot instanceof WorkingMemorySlot
                    && $this->projectionState($slot)['pending'] !== null
                ) {
                    $replay = $slot;
                    $connection->commit();
                } else {
                    if ($slot instanceof WorkingMemorySlot) {
                        $slot->setFields($fields);
                        $slot->save();
                    } else {
                        /** @var WorkingMemorySlot $slot */
                        $slot = $this->core->insertRecord(WorkingMemorySlot::class, $fields);
                    }
                    $this->installProjectionIntent($slot);
                    $connection->commit();
                }
            } catch (\Throwable $throwable) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $throwable;
            }

            if ($replay instanceof WorkingMemorySlot) {
                $this->replayProjection($replay);
                continue;
            }
            if (!$slot instanceof WorkingMemorySlot) {
                throw new RuntimeException('Working-memory slot mutation returned no slot.');
            }
            $this->replayProjection($slot);
            return $slot;
        }
        throw new RuntimeException('Working-memory projection remained busy after bounded retries.');
    }

    private function installProjectionIntent(WorkingMemorySlot $slot): void
    {
        $pending = json_encode([
            'kind' => 'sync',
            'digest' => $this->projectionDigest($slot),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $statement = Connections::getConnection()->prepare(
            'UPDATE working_memory_slots
             SET projection_pending = :pending
             WHERE id = :id AND projection_pending IS NULL'
        );
        $statement->execute(['pending' => $pending, 'id' => (int) $slot->id]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Working-memory projection intent changed concurrently.');
        }
    }

    private function synchronizeProjection(WorkingMemorySlot $slot, bool $allowLegacyScan = true): ?Memory
    {
        $state = $this->projectionState($slot);
        if ($state['pending'] !== null) {
            return $this->replayProjection($slot, $allowLegacyScan);
        }
        $memory = $state['memory_id'] === null
            ? null
            : $this->findProjection($slot, (string) $slot->scope_key, (string) $slot->slot_role, $allowLegacyScan);
        if ($this->projectionMatches($slot, $memory)) {
            return $memory;
        }

        $connection = Connections::getConnection();
        if ($connection->inTransaction()) {
            throw new RuntimeException(
                'Working-memory projection cannot synchronize inside an application transaction.'
            );
        }
        $connection->exec('BEGIN IMMEDIATE');
        try {
            $fresh = WorkingMemorySlot::getByID((int) $slot->id);
            if (!$fresh instanceof WorkingMemorySlot) {
                throw new RuntimeException('Working-memory slot disappeared during synchronization.');
            }
            if ($this->projectionState($fresh)['pending'] === null) {
                $this->installProjectionIntent($fresh);
            }
            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
        return $this->replayProjection($fresh, $allowLegacyScan);
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

    private function findProjection(WorkingMemorySlot $slot, string $scope, string $role, bool $allowLegacyScan = true): ?Memory
    {
        $pointer = $this->projectionState($slot)['memory_id'];
        if ($pointer !== null) {
            $memory = Memory::inspectByID($pointer);
            $identity = $memory instanceof Memory ? $this->identity((string) $memory->content) : null;
            if (!$memory instanceof Memory
                || $identity === null
                || $identity['scope'] !== $scope
                || $identity['slot'] !== $role
            ) {
                throw new RuntimeException('Working-memory projection pointer is invalid.');
            }
            return $memory;
        }

        if (!$allowLegacyScan) {
            throw new RuntimeException('Pointerless projection requires explicit legacy recovery.');
        }

        // Content is immutable in the token store. Only an active projection
        // may receive a metadata refresh; a changed or reactivated slot gets a
        // new record and leaves the old one as history.
        $found = null;
        $afterId = 0;
        do {
            $page = Memory::inspectPage($afterId, 100, 'working', 'active');
            foreach ($page as $memory) {
                $afterId = max($afterId, (int) $memory->id);
                $identity = $this->identity((string) $memory->content);
                if ($identity !== null && $identity['scope'] === $scope && $identity['slot'] === $role) {
                    if ($found instanceof Memory && (int) $found->id !== (int) $memory->id) {
                        throw new RuntimeException('Working-memory projection identity is duplicated.');
                    }
                    $found = $memory;
                }
            }
        } while ($page !== []);
        return $found;
    }

    /** @return resource */
    private function acquireProjectionLock(string $scope, string $role, bool $wait)
    {
        if (Connections::getConnection()->inTransaction()) {
            throw new RuntimeException('Projection ownership requires no active application transaction.');
        }
        $directory = dirname(__DIR__, 2) . '/var/working-memory-locks';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create working-memory lock directory.');
        }
        clearstatcache(true, $directory);
        $directoryState = @lstat($directory);
        if (!is_array($directoryState) || ($directoryState['mode'] & 0170000) !== 0040000
            || ($directoryState['mode'] & 0077) !== 0) {
            throw new RuntimeException('Working-memory lock directory must be a private real directory.');
        }
        // Never unlink lock files: another process can still own that inode.
        $path = $directory . '/' . hash('sha256', $scope . "\0" . $role) . '.lock';
        clearstatcache(true, $path);
        $pathState = @lstat($path);
        if (is_array($pathState) && ($pathState['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('Working-memory lock must be a regular file.');
        }
        $lock = @fopen($path, 'c');
        if (!is_resource($lock)) {
            throw new RuntimeException('Unable to open working-memory projection lock.');
        }
        try {
            $opened = fstat($lock);
            clearstatcache(true, $path);
            $pathState = @lstat($path);
            if (!is_array($opened) || !is_array($pathState)
                || ($opened['mode'] & 0170000) !== 0100000
                || ($pathState['mode'] & 0170000) !== 0100000
                || $opened['dev'] !== $pathState['dev'] || $opened['ino'] !== $pathState['ino']
                || !@chmod($path, 0600)) {
                throw new RuntimeException('Working-memory projection lock identity is invalid.');
            }
            $deadline = hrtime(true) + 5000000000;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (!$wait || hrtime(true) >= $deadline) {
                    throw new RuntimeException('Working-memory projection is busy; retry its durable journal.');
                }
                usleep(10000);
            }
            return $lock;
        } catch (\Throwable $error) {
            fclose($lock);
            throw $error;
        }
    }

    private function replayProjection(WorkingMemorySlot $slot, bool $allowLegacyScan = true): ?Memory
    {
        $scope = (string) $slot->scope_key;
        $role = (string) $slot->slot_role;
        $lock = $this->acquireProjectionLock($scope, $role, $allowLegacyScan);
        try {
            // A second replayer cannot clear this intent while its native
            // write is in flight. Canonical writers already wait for pending
            // intents, so ownership spans the entire cross-store publication.
            $fresh = WorkingMemorySlot::getByID((int) $slot->id);
            if (!$fresh instanceof WorkingMemorySlot || $fresh->scope_key !== $scope || $fresh->slot_role !== $role) {
                throw new RuntimeException('Working-memory slot identity changed before projection ownership.');
            }
            return $this->replayProjectionLocked($fresh, $allowLegacyScan);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Called only while this scope/role's projection lock is held. */
    private function replayProjectionLocked(WorkingMemorySlot $slot, bool $allowLegacyScan, int $remainingReplays = 16): ?Memory
    {
        if ($remainingReplays < 1) {
            throw new RuntimeException('Bounded projection replay lost repeated concurrent races.');
        }
        $state = $this->projectionState($slot);
        $pending = $state['pending'];
        if ($pending === null) {
            return $state['memory_id'] === null
                ? null
                : Memory::inspectByID($state['memory_id']);
        }
        if (($pending['kind'] ?? null) !== 'sync') {
            if (in_array($pending['kind'] ?? null, ['create', 'update', 'replace', 'expire'], true)
                && is_string($pending['operation_key'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/', (string) $pending['operation_key']) === 1
            ) {
                return $this->upgradeLegacyProjectionIntent(
                    (int) $slot->id,
                    (string) $state['encoded'], $allowLegacyScan, $remainingReplays - 1
                );
            }
            throw new RuntimeException('Working-memory projection journal is malformed.');
        }
        if (!is_string($pending['digest'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', (string) $pending['digest']) !== 1
        ) {
            throw new RuntimeException('Working-memory projection journal is malformed.');
        }
        $fresh = WorkingMemorySlot::getByID((int) $slot->id);
        if (!$fresh instanceof WorkingMemorySlot
            || !hash_equals((string) $pending['digest'], $this->projectionDigest($fresh))
        ) {
            throw new RuntimeException('Working-memory projection journal does not match canonical state.');
        }
        $expectedPointer = $state['memory_id'];
        $memory = $this->findProjection(
            $fresh,
            (string) $fresh->scope_key,
            (string) $fresh->slot_role, $allowLegacyScan
        );
        if ($expectedPointer === null && $memory instanceof Memory) {
            $statement = Connections::getConnection()->prepare(
                'UPDATE working_memory_slots SET projection_memory_id = :memory_id
                 WHERE id = :id AND projection_memory_id IS NULL
                   AND projection_pending = :pending'
            );
            $statement->execute([
                'memory_id' => (int) $memory->id,
                'id' => (int) $fresh->id,
                'pending' => $state['encoded'],
            ]);
            if ($statement->rowCount() !== 1) {
                return $this->replayProjectionLocked($fresh, $allowLegacyScan, $remainingReplays - 1);
            }
            $expectedPointer = (int) $memory->id;
        }

        $result = $this->applyProjection($fresh, $memory, (string) $pending['digest']);
        $newPointer = $result instanceof Memory ? (int) $result->id : $expectedPointer;
        if ($this->completeProjection(
            (int) $fresh->id,
            $state['encoded'],
            $expectedPointer,
            $newPointer
        )) {
            return $result;
        }
        return $this->replayProjectionLocked($fresh, $allowLegacyScan, $remainingReplays - 1);
    }

    /**
     * Version 16 briefly wrote the projected memory payload into SQLite. A
     * surviving crash journal is converted by exact CAS before it is replayed;
     * current code never creates that second copy of memory content.
     */
    private function upgradeLegacyProjectionIntent(int $slotId, string $encoded, bool $allowLegacyScan = true, int $remainingReplays = 16): ?Memory
    {
        $connection = Connections::getConnection();
        if ($connection->inTransaction()) {
            throw new RuntimeException(
                'Working-memory legacy projection cannot recover inside an application transaction.'
            );
        }
        $connection->exec('BEGIN IMMEDIATE');
        try {
            $fresh = WorkingMemorySlot::getByID($slotId);
            if (!$fresh instanceof WorkingMemorySlot) {
                throw new RuntimeException('Working-memory slot disappeared during journal upgrade.');
            }
            $minimal = json_encode([
                'kind' => 'sync',
                'digest' => $this->projectionDigest($fresh),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $statement = $connection->prepare(
                'UPDATE working_memory_slots SET projection_pending = :minimal
                 WHERE id = :id AND projection_pending = :encoded'
            );
            $statement->execute([
                'minimal' => $minimal,
                'id' => $slotId,
                'encoded' => $encoded,
            ]);
            $changed = $statement->rowCount() === 1;
            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
        if (!$changed) {
            $fresh = WorkingMemorySlot::getByID($slotId);
            if (!$fresh instanceof WorkingMemorySlot) {
                throw new RuntimeException('Working-memory slot disappeared during journal retry.');
            }
        }
        return $this->replayProjectionLocked($fresh, $allowLegacyScan, $remainingReplays);
    }

    private function applyProjection(
        WorkingMemorySlot $slot,
        ?Memory $memory,
        string $digest
    ): ?Memory {
        $scope = (string) $slot->scope_key;
        $role = (string) $slot->slot_role;
        $fields = $this->desiredProjectionFields($slot);
        $pointer = $memory instanceof Memory ? (int) $memory->id : null;
        if ($slot->status !== 'active') {
            if ($memory instanceof Memory && $memory->status !== 'expired') {
                $memory->setFields(['status' => 'expired', 'updated_at' => time()]);
                $memory->saveWithOperation($this->projectionOperationKey(
                    'expire', $scope, $role, ['digest' => $digest, 'id' => $pointer]
                ));
            }
            return $memory;
        }
        if (!$memory instanceof Memory) {
            $fields['created_at'] = time();
            $fields['operation_key'] = $this->projectionOperationKey(
                'create', $scope, $role, ['digest' => $digest]
            );
            /** @var Memory $created */
            $created = $this->core->insertRecord(Memory::class, $fields);
            return $created;
        }
        if ((string) $memory->content !== (string) $fields['content']
            || $memory->status !== 'active'
        ) {
            $fields['created_at'] = time();
            return Memory::replaceRecord(
                $memory,
                $fields,
                'expired',
                $this->projectionOperationKey(
                    'replace', $scope, $role, ['digest' => $digest, 'id' => $pointer]
                )
            );
        }
        if (!$this->projectionMatches($slot, $memory)) {
            $memory->setFields([
                'confidence' => $fields['confidence'],
                'status' => 'active',
                'expires_at' => $fields['expires_at'],
                'updated_at' => time(),
            ]);
            $memory->saveWithOperation($this->projectionOperationKey(
                'update', $scope, $role, ['digest' => $digest, 'id' => $pointer]
            ));
        }
        return $memory;
    }

    /** @return array<string, mixed> */
    private function desiredProjectionFields(WorkingMemorySlot $slot): array
    {
        return [
            'tier' => 'working',
            'content' => sprintf(
                '[workspace scope=%s slot=%s] %s',
                (string) $slot->scope_key,
                (string) $slot->slot_role,
                (string) $slot->claim
            ),
            'confidence' => (float) $slot->confidence,
            'status' => (string) $slot->status,
            'expires_at' => $slot->expires_at,
            'updated_at' => time(),
        ];
    }

    private function projectionMatches(WorkingMemorySlot $slot, ?Memory $memory): bool
    {
        if (!$memory instanceof Memory) {
            return $slot->status !== 'active';
        }
        $fields = $this->desiredProjectionFields($slot);
        return (string) $memory->content === (string) $fields['content']
            && (float) $memory->confidence === (float) $fields['confidence']
            && (string) $memory->status === (string) $fields['status']
            && $this->timestamp($memory->expires_at) === $this->timestamp($fields['expires_at']);
    }

    /** @return array{memory_id: ?int, pending: ?array<string, mixed>, encoded: ?string} */
    private function projectionState(WorkingMemorySlot $slot): array
    {
        $statement = Connections::getConnection()->prepare(
            'SELECT projection_memory_id, projection_pending
             FROM working_memory_slots WHERE id = :id'
        );
        $statement->execute(['id' => (int) $slot->id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (!is_array($row)) {
            throw new RuntimeException('Working-memory slot disappeared during projection.');
        }
        $pending = $row['projection_pending'];
        return [
            'memory_id' => $row['projection_memory_id'] === null
                ? null
                : (int) $row['projection_memory_id'],
            'pending' => is_string($pending) && $pending !== ''
                ? json_decode($pending, true, flags: JSON_THROW_ON_ERROR)
                : null,
            'encoded' => is_string($pending) && $pending !== '' ? $pending : null,
        ];
    }

    private function completeProjection(
        int $slotId,
        ?string $pending,
        ?int $expectedPointer,
        ?int $newPointer
    ): bool {
        if ($pending === null) {
            return false;
        }
        $sql = 'UPDATE working_memory_slots
                SET projection_memory_id = :new_pointer, projection_pending = NULL
                WHERE id = :id AND projection_pending = :pending AND ';
        $sql .= $expectedPointer === null
            ? 'projection_memory_id IS NULL'
            : 'projection_memory_id = :expected_pointer';
        $statement = Connections::getConnection()->prepare($sql);
        $parameters = [
            'new_pointer' => $newPointer,
            'id' => $slotId,
            'pending' => $pending,
        ];
        if ($expectedPointer !== null) {
            $parameters['expected_pointer'] = $expectedPointer;
        }
        $statement->execute($parameters);
        return $statement->rowCount() === 1;
    }

    private function projectionDigest(WorkingMemorySlot $slot): string
    {
        return hash('sha256', json_encode([
            'scope' => (string) $slot->scope_key,
            'role' => (string) $slot->slot_role,
            'claim' => (string) $slot->claim,
            'confidence' => (float) $slot->confidence,
            'status' => (string) $slot->status,
            'expires_at' => $this->timestamp($slot->expires_at),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $state */
    private function projectionOperationKey(string $operation, string $scope, string $role, array $state): string
    {
        unset($state['updated_at'], $state['created_at']);
        return TokenMemoryDaemon::operationKey(
            'working-projection-' . $operation,
            $scope . "\n" . $role . "\n" . json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
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

    /**
     * A slot caches presentation, not source authority. Hydrate memory-backed
     * claims with counter-neutral reads so corrections propagate immediately;
     * an inactive, expired, or missing source cannot retain an incumbent vote.
     *
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

    /**
     * Both reference kinds address native Memory IDs, never SQL Procedure IDs.
     * `memory` is the generic nonworking namespace used by decision retrieval;
     * `procedure` promises specifically a native procedural record.
     *
     * @param array<string, mixed>|null $record
     */
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
