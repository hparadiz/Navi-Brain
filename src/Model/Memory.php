<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Mapping\Column;
use NaviBrain\Storage\TokenMemoryDaemon;
use RuntimeException;

final class Memory extends ActiveRecord
{
    public static $tableName = 'memories';
    public static $primaryKey = 'id';
    private static int $externalTransactionDepth = 0;
    private ?string $operationKey = null;

    public static $indexes = [
        'memories_tier_status' => ['fields' => ['tier', 'status']],
        'memories_updated_at' => ['fields' => ['updated_at']],
        'memories_source_event' => ['fields' => ['source_event_id']],
    ];

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

    public static function enterExternalTransaction(): void
    {
        self::$externalTransactionDepth++;
    }

    public static function leaveExternalTransaction(): void
    {
        if (self::$externalTransactionDepth < 1) {
            throw new RuntimeException('Unbalanced token-memory transaction fence.');
        }
        self::$externalTransactionDepth--;
    }

    /**
     * @param array<string, mixed> $where
     * @param array<string, mixed> $options
     * @return list<self>
     */
    public static function getAllByWhere(array $where, array $options = []): array
    {
        return array_map(
            static fn (array $record): self => new self($record, false, false),
            TokenMemoryDaemon::list($where, $options, true)
        );
    }

    /**
     * Counter-neutral candidate or integrity scan.
     *
     * @param array<string, mixed> $where
     * @param array<string, mixed> $options
     * @return list<self>
     */
    public static function inspectAllByWhere(array $where, array $options = []): array
    {
        return self::hydrate(TokenMemoryDaemon::list($where, $options, false));
    }

    /** @param list<int> $ids
     *  @return list<self>
     */
    public static function getManyByID(array $ids, bool $counted = true): array
    {
        return self::hydrate(TokenMemoryDaemon::batch($ids, $counted));
    }

    /** @param list<self> $records */
    public static function observeRecords(array $records): void
    {
        TokenMemoryDaemon::observe(array_map(
            static fn (self $memory): int => (int) $memory->id,
            $records
        ));
    }

    /** @return list<self> */
    public static function rankCandidates(
        string $cue,
        int $limit,
        ?string $tier = null,
        ?string $status = null
    ): array {
        return self::hydrate(TokenMemoryDaemon::rank($cue, $limit, $tier, $status));
    }

    /** @return list<self> */
    public static function inspectPage(
        int $afterId,
        int $limit,
        ?string $tier = null,
        ?string $status = null
    ): array {
        return self::hydrate(TokenMemoryDaemon::page(
            $afterId,
            $limit,
            $tier,
            $status,
            false
        ));
    }

    public static function inspectCount(
        ?string $tier = null,
        ?string $status = null,
        int $afterId = 0
    ): int {
        return TokenMemoryDaemon::countRecords($tier, $status, $afterId);
    }

    public static function inspectProvenance(
        string $kind,
        int $sourceId,
        ?string $tier = null,
        ?string $status = null
    ): ?self {
        $record = TokenMemoryDaemon::provenance(
            $kind,
            $sourceId,
            $tier,
            $status
        );
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
        if (self::$externalTransactionDepth !== 0) {
            throw new RuntimeException(
                'Token-memory writes cannot occur inside a SQLite transaction.'
            );
        }

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
            throw new RuntimeException(
                'Memory content, tier, and creation time are immutable; create a replacement memory.'
            );
        }

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
            $persisted = TokenMemoryDaemon::create($record, $this->operationKey);
            $id = (int) ($persisted['id'] ?? 0);
            $this->setFields($persisted);
            $this->finalizeInsert($id, true);
        } else {
            $record['id'] = (int) $this->getPrimaryKeyValue();
            $persisted = TokenMemoryDaemon::update($record, $this->operationKey);
            $this->setFields($persisted);
            $this->finalizeUpdate();
        }
        $this->finalizeSave();
        $this->operationKey = null;
    }

    /** @param array<string, mixed> $values */
    public static function replaceRecord(
        self $previous,
        array $values,
        string $retiredStatus,
        string $operationKey
    ): self {
        if (self::$externalTransactionDepth !== 0) {
            throw new RuntimeException(
                'Token-memory writes cannot occur inside a SQLite transaction.'
            );
        }
        if (!in_array($retiredStatus, ['superseded', 'expired', 'quarantined'], true)) {
            throw new RuntimeException('Invalid replacement retirement status.');
        }
        $now = time();
        $values['id'] = null;
        $values['supersedes_id'] = (int) $previous->id;
        $values['created_at'] ??= $now;
        $values['updated_at'] ??= $now;
        $values['status'] ??= 'active';
        $retiredAt = $values['updated_at'];
        $replacement = new self($values, true, true);
        $newRecord = $replacement->preparePersistedRecordValues();

        $previous->setFields(['status' => $retiredStatus, 'updated_at' => $retiredAt]);
        $oldRecord = $previous->preparePersistedRecordValues();
        $receipt = TokenMemoryDaemon::replace($newRecord, $oldRecord, $operationKey);

        $replacement->setFields($receipt['new']);
        $replacement->finalizeInsert((int) $receipt['new']['id'], true);
        $replacement->finalizeSave();
        $previous->setFields($receipt['old']);
        $previous->finalizeUpdate();
        $previous->finalizeSave();
        return $replacement;
    }

    /** @param list<array<string, mixed>> $records
     *  @return list<self>
     */
    private static function hydrate(array $records): array
    {
        return array_map(
            static fn (array $record): self => new self($record, false, false),
            $records
        );
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

    // Null preserves the unknown namespace of records written before typed lineage.
    #[Column(type: 'enum', values: ['event', 'sense_event'], notnull: false)]
    protected ?string $source_event_kind = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $source_memory_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $supersedes_id = null;

    #[Column(type: 'timestamp', notnull: false)]
    protected $expires_at;
}
