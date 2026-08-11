<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/**
 * The live, mutable workspace state read by the next decision cycle.
 *
 * ContextCapsule and CapsuleSlot are immutable audit snapshots. This table is
 * the bounded hub between those snapshots: one current occupant per scope and
 * role, updated in place and expired rather than accumulated as a transcript.
 */
final class WorkingMemorySlot extends ActiveRecord
{
    use Getters;

    public static $tableName = 'working_memory_slots';
    public static $primaryKey = 'id';

    public static $indexes = [
        'working_memory_scope_role' => ['fields' => ['scope_key', 'slot_role'], 'unique' => true],
        'working_memory_thread' => ['fields' => ['thread_id', 'updated_at']],
        'working_memory_expiry' => ['fields' => ['status', 'expires_at']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 96)]
    protected string $scope_key;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $thread_id = null;

    #[Column(type: 'string', length: 64)]
    protected string $slot_role;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $source_capsule_id = null;

    #[Column(type: 'string', length: 64, notnull: false)]
    protected ?string $record_type = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $record_id = null;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $claim = null;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $confidence = 0.0;

    #[Column(type: 'decimal', precision: 9, scale: 4)]
    protected float $score = 0.0;

    #[Column(type: 'timestamp', notnull: false)]
    protected $recorded_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $carryover_depth = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $reserved = 0;

    #[Column(type: 'enum', values: ['active', 'expired'])]
    protected string $status = 'active';

    #[Column(type: 'timestamp')]
    protected $expires_at;
}
