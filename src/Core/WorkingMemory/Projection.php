<?php

declare(strict_types=1);

namespace NaviBrain\Core\WorkingMemory;

use RuntimeException;
use NaviBrain\Model\Memory;
use NaviBrain\Model\WorkingMemorySlot;
use NaviBrain\Storage\TokenMemoryDaemon;

class Projection extends Component
{
    private const MARKER = '/^\[workspace scope=([^\]]+) slot=([^\]]+)\] /';
    private const LEGACY_MARKER = '/^\[workspace thread=(\d+) slot=([^\]]+)\] /';

    /** @param array<string, mixed> $fields */
    public function persistSlot(string $scope, string $role, array $fields): WorkingMemorySlot
    {
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $slot = $this->find($scope, $role);
            if ($slot instanceof WorkingMemorySlot
                && $this->projectionState($slot)['pending'] !== null
            ) {
                $this->replayProjection($slot);
                continue;
            }

            if ($slot instanceof WorkingMemorySlot) {
                $slot->setFields($fields);
            } else {
                $slot = new WorkingMemorySlot($fields, true, true);
            }
            $slot->save();
            $this->installProjectionIntent($slot);
            $this->replayProjection($slot);
            return $slot;
        }
        throw new RuntimeException('Working-memory projection remained busy after bounded retries.');
    }

    public function installProjectionIntent(WorkingMemorySlot $slot): void
    {
        $pending = json_encode([ 'kind' => 'sync', 'digest' => $this->projectionDigest($slot), ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $statementRecord = WorkingMemorySlot::getByWhere(['id' => (int) $slot->id, 'projection_pending' => null]);
        if ($statementRecord instanceof WorkingMemorySlot) {
            $statementRecord->setFields([ 'projection_pending' => $pending ]);
            $statementRecord->save();
        }
        if (!$statementRecord instanceof WorkingMemorySlot) {
            throw new RuntimeException('Working-memory projection intent changed concurrently.');
        }
    }

    public function synchronizeProjection(WorkingMemorySlot $slot, bool $allowLegacyScan = true): ?Memory
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

        $fresh = WorkingMemorySlot::getByID((int) $slot->id);
        if (!$fresh instanceof WorkingMemorySlot) {
            throw new RuntimeException('Working-memory slot disappeared during synchronization.');
        }
        if ($this->projectionState($fresh)['pending'] === null) {
            $this->installProjectionIntent($fresh);
        }
        return $this->replayProjection($fresh, $allowLegacyScan);
    }

    public function findProjection(WorkingMemorySlot $slot, string $scope, string $role, bool $allowLegacyScan = true): ?Memory
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
    public function acquireProjectionLock(string $scope, string $role, bool $wait)
    {
        $directory = dirname(__DIR__, 3) . '/var/working-memory-locks';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create working-memory lock directory.');
        }
        clearstatcache(true, $directory);
        $directoryState = @lstat($directory);
        if (!is_array($directoryState) || ($directoryState['mode'] & 0170000) !== 0040000
            || ($directoryState['mode'] & 0077) !== 0) {
            throw new RuntimeException('Working-memory lock directory must be a private real directory.');
        }

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

    public function replayProjection(WorkingMemorySlot $slot, bool $allowLegacyScan = true): ?Memory
    {
        $scope = (string) $slot->scope_key;
        $role = (string) $slot->slot_role;
        $lock = $this->acquireProjectionLock($scope, $role, $allowLegacyScan);
        try {

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

    public function replayProjectionLocked(WorkingMemorySlot $slot, bool $allowLegacyScan, int $remainingReplays = 16): ?Memory
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
                return $this->upgradeLegacyProjectionIntent((int) $slot->id, (string) $state['encoded'], $allowLegacyScan, $remainingReplays - 1);
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
        $memory = $this->findProjection($fresh, (string) $fresh->scope_key, (string) $fresh->slot_role, $allowLegacyScan);
        if ($expectedPointer === null && $memory instanceof Memory) {

            $statementRecord = WorkingMemorySlot::getByWhere(['id' => (int) $fresh->id, 'projection_memory_id' => null, 'projection_pending' => $state['encoded']]);
            if ($statementRecord instanceof WorkingMemorySlot) {
                $statementRecord->setFields([ 'projection_memory_id' => (int) $memory->id ]);
                $statementRecord->save();
            }
            if (!$statementRecord instanceof WorkingMemorySlot) {
                return $this->replayProjectionLocked($fresh, $allowLegacyScan, $remainingReplays - 1);
            }
            $expectedPointer = (int) $memory->id;
        }

        $result = $this->applyProjection($fresh, $memory, (string) $pending['digest']);
        $newPointer = $result instanceof Memory ? (int) $result->id : $expectedPointer;
        if ($this->completeProjection((int) $fresh->id, $state['encoded'], $expectedPointer, $newPointer)) {
            return $result;
        }
        return $this->replayProjectionLocked($fresh, $allowLegacyScan, $remainingReplays - 1);
    }

    public function upgradeLegacyProjectionIntent(int $slotId, string $encoded, bool $allowLegacyScan = true, int $remainingReplays = 16): ?Memory
    {
        $fresh = WorkingMemorySlot::getByID($slotId);
        if (!$fresh instanceof WorkingMemorySlot) {
            throw new RuntimeException('Working-memory slot disappeared during journal upgrade.');
        }
        $minimal = json_encode([ 'kind' => 'sync', 'digest' => $this->projectionDigest($fresh), ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $statementRecord = WorkingMemorySlot::getByWhere(['id' => $slotId, 'projection_pending' => $encoded]);
        if ($statementRecord instanceof WorkingMemorySlot) {
            $statementRecord->setFields([ 'projection_pending' => $minimal ]);
            $statementRecord->save();
        }
        $changed = $statementRecord instanceof WorkingMemorySlot;
        if (!$changed) {
            $fresh = WorkingMemorySlot::getByID($slotId);
            if (!$fresh instanceof WorkingMemorySlot) {
                throw new RuntimeException('Working-memory slot disappeared during journal retry.');
            }
        }
        return $this->replayProjectionLocked($fresh, $allowLegacyScan, $remainingReplays);
    }

    public function applyProjection(WorkingMemorySlot $slot, ?Memory $memory, string $digest): ?Memory
    {
        $scope = (string) $slot->scope_key;
        $role = (string) $slot->slot_role;
        $fields = $this->desiredProjectionFields($slot);
        $pointer = $memory instanceof Memory ? (int) $memory->id : null;
        if ($slot->status !== 'active') {
            if ($memory instanceof Memory && $memory->status !== 'expired') {
                $memory->setFields(['status' => 'expired', 'updated_at' => time()]);
                $memory->setOperationKey($this->projectionOperationKey( 'expire', $scope, $role, ['digest' => $digest, 'id' => $pointer] ));
                $memory->save();
            }
            return $memory;
        }
        if (!$memory instanceof Memory) {
            $fields['created_at'] = time();
            $fields['operation_key'] = $this->projectionOperationKey('create', $scope, $role, ['digest' => $digest]);
            /** @var Memory $created */
            $created = new Memory($fields, true, true);
            $created->save();
            return $created;
        }
        if ((string) $memory->content !== (string) $fields['content']
            || $memory->status !== 'active'
        ) {
            $fields['created_at'] = time();
            return Memory::replaceRecord($memory, $fields, 'expired', $this->projectionOperationKey( 'replace', $scope, $role, ['digest' => $digest, 'id' => $pointer] ));
        }
        if (!$this->projectionMatches($slot, $memory)) {
            $memory->setFields([ 'confidence' => $fields['confidence'], 'status' => 'active', 'expires_at' => $fields['expires_at'], 'updated_at' => time(), ]);
            $memory->setOperationKey($this->projectionOperationKey( 'update', $scope, $role, ['digest' => $digest, 'id' => $pointer] ));
            $memory->save();
        }
        return $memory;
    }

    /** @return array<string, mixed> */
    public function desiredProjectionFields(WorkingMemorySlot $slot): array
    {
        return [
            'tier' => 'working',
            'content' => sprintf('[workspace scope=%s slot=%s] %s', (string) $slot->scope_key, (string) $slot->slot_role, (string) $slot->claim),
            'confidence' => (float) $slot->confidence,
            'status' => (string) $slot->status,
            'expires_at' => $slot->expires_at,
            'updated_at' => time(),
        ];
    }

    public function projectionMatches(WorkingMemorySlot $slot, ?Memory $memory): bool
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
    public function projectionState(WorkingMemorySlot $slot): array
    {
        $fresh = WorkingMemorySlot::getByID((int) $slot->id);
        if (!$fresh instanceof WorkingMemorySlot) {
            throw new RuntimeException('Working-memory slot disappeared during projection.');
        }
        $pending = $fresh->projection_pending;
        return [
            'memory_id' => $fresh->projection_memory_id === null
                ? null
                : (int) $fresh->projection_memory_id,
            'pending' => is_string($pending) && $pending !== ''
                ? json_decode($pending, true, flags: JSON_THROW_ON_ERROR)
                : null,
            'encoded' => is_string($pending) && $pending !== '' ? $pending : null,
        ];
    }

    public function completeProjection(int $slotId, ?string $pending, ?int $expectedPointer, ?int $newPointer): bool
    {
        if ($pending === null) {
            return false;
        }
        $slot = WorkingMemorySlot::getByWhere([ 'id' => $slotId, 'projection_pending' => $pending, 'projection_memory_id' => $expectedPointer, ]);
        if (!$slot instanceof WorkingMemorySlot) {
            return false;
        }
        $slot->setFields([ 'projection_memory_id' => $newPointer, 'projection_pending' => null, ]);
        $slot->save();
        return true;
    }

    public function projectionDigest(WorkingMemorySlot $slot): string
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
    public function projectionOperationKey(string $operation, string $scope, string $role, array $state): string
    {
        unset($state['updated_at'], $state['created_at']);
        return TokenMemoryDaemon::operationKey('working-projection-' . $operation, $scope . "\n" . $role . "\n" . json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{scope: string, slot: string}|null */
    public function identity(string $content): ?array
    {
        if (preg_match(self::MARKER, $content, $matches) === 1) {
            return ['scope' => (string) $matches[1], 'slot' => (string) $matches[2]];
        }
        if (preg_match(self::LEGACY_MARKER, $content, $matches) === 1) {
            return ['scope' => 'thread:' . (int) $matches[1], 'slot' => (string) $matches[2]];
        }
        return null;
    }
}
