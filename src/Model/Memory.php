<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;
use NaviBrain\Storage\TokenMemoryDaemon;
use NaviBrain\Core\ExecutiveCore\Executive;
use NaviBrain\Support\ActivityBus;
use NaviBrain\Support\Activity;
use InvalidArgumentException;
use Throwable;
use RuntimeException;

class Memory extends ActiveRecord
{
    public static $tableName = 'memories';
    public static $primaryKey = 'id';
    private ?string $operationKey = null;
    private ?self $previousMemory = null;
    private string $retiredStatus = 'superseded';
    public ?Event $storedEvent = null;

    public function __construct($record = [], $isDirty = false, $isPhantom = null)
    {
        $isPhantom ??= $record === [];
        $operationKey = $record['operation_key'] ?? null;
        unset($record['operation_key']);
        if ($isPhantom) {
            $record['source_event_kind'] = isset($record['source_event_id'])
                ? ($record['source_event_kind'] ?? 'event') : null;

        }
        parent::__construct($record, $isDirty, $isPhantom);
        if ($operationKey !== null) {
            $this->setOperationKey($operationKey);
        }
    }

    public static function fromAction(ActionTrace $action, Event $event): self
    {
        $memoryKey = TokenMemoryDaemon::operationKey('finish-action-memory', (string) $event->id);
        $operation = MemoryStoreOperation::getByField('operation_key', $memoryKey);
        $storedMemory = null;
        if ($operation?->memory_id !== null) {
            $storedMemory = self::inspectByID((int) $operation->memory_id);
            if (!$storedMemory instanceof self) {
                throw new RuntimeException('The finished action is missing its episodic memory.');
            }
        }
        $prefix = sprintf('Action "%s" expected "%s" and observed "', $action->description, $action->expected);
        $suffix = sprintf('". Outcome: %s. Match: %s. Repair: %s', $action->status, $action->match_status, $action->repair_note);
        $createdAt = $event->created_at;
        $memoryTime = $createdAt instanceof \DateTimeInterface ? $createdAt->getTimestamp()
            : (is_numeric($createdAt) ? (int) $createdAt : (strtotime((string) $createdAt) ?: time()));
        $memory = new self([
            'operation_key' => $memoryKey,
            'tier' => 'episodic',
            'content' => $storedMemory?->content ?? $prefix . mb_substr((string) $action->observed, 0, 4000) . $suffix,
            'confidence' => 1.0,
            'status' => 'active',
            'source_event_id' => $event->id,
            'source_event_kind' => 'event',
            'created_at' => $memoryTime,
            'updated_at' => $memoryTime,
        ], true, true);
        if ($operation instanceof MemoryStoreOperation && $operation->memory_id === null
            && !hash_equals($operation->request_hash, $memory->requestHash())) {
            $memory->setField('content', $prefix . $action->observed . $suffix);
        }
        return $memory;
    }

    public static function getByID(int $id): ?self
    {
        $record = TokenMemoryDaemon::fetch($id, true);
        return $record === null ? null : new self($record, false, false);
    }

    public static function inspectByID(int $id): ?self
    {
        $record = TokenMemoryDaemon::fetch($id, false);
        return $record === null ? null : new self($record, false, false);
    }

    /**
     * @param array<string, mixed> $where
     * @param array<string, mixed> $options
     * @return list<self>
     */
    public static function getAllByWhere(array $where, array $options = []): array
    {
        return array_map(static fn (array $record): self => new self($record, false, false), TokenMemoryDaemon::list($where, $options, true));
    }

    /**
     * @param array<string, mixed> $where
     * @param array<string, mixed> $options
     * @return list<self>
     */
    public static function inspectAllByWhere(array $where, array $options = []): array
    {
        return self::hydrate(TokenMemoryDaemon::list($where, $options, false));
    }

    /**
     * @param list<int> $ids
     * @return list<self>
     */
    public static function getManyByID(array $ids, bool $counted = true): array
    {
        return self::hydrate(TokenMemoryDaemon::batch($ids, $counted));
    }

    /** @param list<self> $records */
    public static function observeRecords(array $records): void
    {
        TokenMemoryDaemon::observe(array_map( static fn (self $memory): int => (int) $memory->id, $records ));
    }

    /** @return list<self> */
    public static function rankCandidates(string $cue, int $limit, ?string $tier = null, ?string $status = null): array {
        return self::hydrate(TokenMemoryDaemon::rank($cue, $limit, $tier, $status));
    }

    /** @return list<self> */
    public static function inspectPage(int $afterId, int $limit, ?string $tier = null, ?string $status = null): array {
        return self::hydrate(TokenMemoryDaemon::page( $afterId, $limit, $tier, $status, false ));
    }

    public static function inspectCount(?string $tier = null, ?string $status = null, int $afterId = 0): int {
        return TokenMemoryDaemon::countRecords($tier, $status, $afterId);
    }

    public static function inspectProvenance(string $kind, int $sourceId, ?string $tier = null, ?string $status = null): ?self {
        $record = TokenMemoryDaemon::provenance($kind, $sourceId, $tier, $status);
        return $record === null ? null : new self($record, false, false);
    }

    /** @return list<self> */
    public static function getAll(array $options = []): array
    {
        return self::getAllByWhere([], $options);
    }

    public function setOperationKey(string $operationKey): self
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Memory operation key must be a SHA-256 hex digest.');
        }
        $this->operationKey = $operationKey;
        return $this;
    }

    public function saveWithOperation(string $operationKey): void
    {
        $this->setOperationKey($operationKey);
        $this->save();
    }

    public function save($deep = true): void
    {

        $new = $this->getPrimaryKeyValue() === null;
        if (!$new && !$this->isDirty && $this->operationKey === null) {
            return;
        }
        if ($this->operationKey === null) {
            throw new RuntimeException('Token-memory writes require a caller-owned operation key.');
        }
        if (!$new && ($this->isFieldDirty('content')
            || $this->isFieldDirty('tier')
            || $this->isFieldDirty('created_at'))
        ) {
            throw new RuntimeException('Memory content, tier, and creation time are immutable; create a replacement memory.');
        }

        $operation = $new ? $this->prepareNewMemory() : null;
        $now = time();
        if ($new && ($this->created_at === null || $this->created_at === 'CURRENT_TIMESTAMP')) {
            $this->setField('created_at', $now);
        }
        if ($this->updated_at === null || $this->updated_at === 'CURRENT_TIMESTAMP') {
            $this->setField('updated_at', $now);
        }
        if ($this->status === null) {
            $this->setField('status', 'active');
        }
        $record = $this->preparePersistedRecordValues();
        if (!isset($record['tier'], $record['content'], $record['confidence'], $record['status'])) {
            throw new RuntimeException('Memory is missing required token-native fields.');
        }

        if ($new) {
            if ($this->previousMemory instanceof self) {
                $this->previousMemory->setFields([ 'status' => $this->retiredStatus, 'updated_at' => $this->updated_at, ]);
                $receipt = TokenMemoryDaemon::replace($record, $this->previousMemory->preparePersistedRecordValues(), $this->operationKey);
                $persisted = $receipt['new'];
                $this->previousMemory->setFields($receipt['old']);
                $this->previousMemory->finalizeUpdate();
                $this->previousMemory->finalizeSave();
            } else {
                $persisted = TokenMemoryDaemon::create($record, $this->operationKey);
            }
            $this->setFields($persisted);
            $this->finalizeInsert((int) $persisted['id'], true);
        } else {
            $record['id'] = (int) $this->getPrimaryKeyValue();
            $persisted = TokenMemoryDaemon::update($record, $this->operationKey);
            $this->setFields($persisted);
            $this->finalizeUpdate();
        }
        $this->finalizeSave();
        if ($operation instanceof MemoryStoreOperation) {
            $this->recordStoredMemory($operation);
        }
        $this->operationKey = null;
    }

    /** @param array<string, mixed> $values */
    public static function replaceRecord(self $previous, array $values, string $retiredStatus, string $operationKey): self
    {
        if (!in_array($retiredStatus, ['superseded', 'expired', 'quarantined'], true)) {
            throw new RuntimeException('Invalid replacement retirement status.');
        }
        unset($values['id']);
        $values['supersedes_id'] = (int) $previous->id;
        $values['operation_key'] = $operationKey;
        $replacement = new self($values, true, true);
        $replacement->previousMemory = $previous;
        $replacement->retiredStatus = $retiredStatus;
        $replacement->save();
        return $replacement;
    }

    private function requestHash(): string
    {
        $request = [
            'tier' => $this->tier, 'content' => $this->content, 'confidence' => $this->confidence,
            'source_event_id' => $this->source_event_id, 'source_memory_id' => $this->source_memory_id,
            'supersedes_id' => $this->supersedes_id, 'expires_at' => $this->_record['expires_at'] ?? null,
        ];
        if ($this->source_event_kind !== null) {
            $request['source_event_kind'] = $this->source_event_kind;
        }
        ksort($request);
        return hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function prepareNewMemory(): MemoryStoreOperation
    {
        if (!in_array($this->tier, ['working', 'episodic', 'semantic', 'procedural'], true)
            || trim($this->content) === '' || $this->confidence < 0 || $this->confidence > 1) {
            throw new InvalidArgumentException('Memory requires a valid tier, content, and confidence between zero and one.');
        }
        if ($this->tier === 'semantic' && $this->source_event_id === null && $this->source_memory_id === null) {
            throw new InvalidArgumentException('Semantic memories require a source event or source memory.');
        }
        if ($this->source_event_id !== null && MemorySource::inspect([ 'source_event_id' => $this->source_event_id, 'source_event_kind' => $this->source_event_kind, ]) === null) {
            throw new InvalidArgumentException('Memory source does not exist in its declared namespace.');
        }
        if ($this->source_memory_id !== null && !self::inspectByID((int) $this->source_memory_id)) {
            throw new InvalidArgumentException('Memory source does not exist.');
        }
        if ($this->supersedes_id !== null && !$this->previousMemory instanceof self) {
            $this->previousMemory = self::inspectByID((int) $this->supersedes_id);
            if (!$this->previousMemory instanceof self) {
                throw new InvalidArgumentException('Superseded memory does not exist.');
            }
        }
        $requestHash = $this->requestHash();
        $operation = MemoryStoreOperation::getByField('operation_key', $this->operationKey);
        if ($operation instanceof MemoryStoreOperation) {
            if (!hash_equals($operation->request_hash, $requestHash)) {
                throw new RuntimeException('Memory idempotency key was reused with different input.');
            }
        } else {
            $operation = new MemoryStoreOperation([
                'operation_key' => $this->operationKey, 'request_hash' => $requestHash,
                'requested_at' => is_int($this->created_at) ? $this->created_at : time(),
                'memory_id' => null, 'event_id' => null,
            ], true, true);
            $operation->save();
        }
        $this->setFields(['created_at' => (int) $operation->requested_at, 'updated_at' => (int) $operation->requested_at]);
        return $operation;
    }

    private function recordStoredMemory(MemoryStoreOperation $operation): void
    {
        if ($operation->memory_id !== null && (int) $operation->memory_id !== (int) $this->id) {
            throw new RuntimeException('Memory store operation resolved to a different memory.');
        }
        $payload = [
            'memory_id' => (int) $this->id, 'tier' => $this->tier,
            'source_event_id' => $this->source_event_id, 'source_memory_id' => $this->source_memory_id,
            'supersedes_id' => $this->supersedes_id,
        ];
        if ($this->source_event_kind !== null) {
            $payload['source_event_kind'] = $this->source_event_kind;
        }
        $key = 'memory.stored:' . $this->id;
        $event = Event::getByField('dedupe_key', $key);
        if (!$event instanceof Event) {
            $event = new Event(['kind' => 'memory.stored', 'payload' => $payload, 'dedupe_key' => $key], true, true);
            $event->save();
            try {
                $activity = new \NaviBrain\Support\Activity();
                $activity->source = 'core';
                $activity->phase = 'event';
                $activity->operation = 'memory.stored';
                $activity->context = $payload;
                (new ActivityBus())->publish($activity);
            } catch (Throwable) {
            }
        }
        if ($event->kind !== 'memory.stored' || (array) $event->payload !== $payload) {
            throw new RuntimeException('Stored memory event has conflicting data.');
        }
        $this->storedEvent = $event;
        $operation->memory_id = (int) $this->id;
        $operation->event_id = (int) $event->id;
        $operation->save();
        if ($this->tier === 'semantic' && $this->source_memory_id !== null) {
            $ledger = MemoryConsolidationEpisode::getByField('episode_id', $this->source_memory_id);
            if ($ledger instanceof MemoryConsolidationEpisode) {
                $ledger->setFields([
                    'work_item_id' => null, 'semantic_memory_id' => (int) $this->id, 'status' => 'consolidated',
                    'reason' => 'active_semantic_provenance_committed',
                    'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION, 'updated_at' => time(),
                ]);
                $ledger->save();
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<self>
     */
    private static function hydrate(array $records): array
    {
        return array_map(static fn (array $record): self => new self($record, false, false), $records);
    }

    public function destroy(): bool
    {
        throw new RuntimeException('Token memories are append-preserved and cannot be deleted.');
    }

    public static function delete($id): bool
    {
        throw new RuntimeException('Token memories are append-preserved and cannot be deleted.');
    }

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'enum', values: ['working', 'episodic', 'semantic', 'procedural'])]
    protected string $tier;

    #[Column(type: 'clob')]
    protected string $content;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $confidence;

    #[Column(type: 'enum', values: ['active', 'superseded', 'expired', 'quarantined'])]
    protected string $status = 'active';

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $source_event_id = null;

    #[Column(type: 'enum', values: ['event', 'sense_event'], notnull: false)]
    protected ?string $source_event_kind = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $source_memory_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $supersedes_id = null;

    #[Column(type: 'timestamp', notnull: false)]
    protected $expires_at;
}
