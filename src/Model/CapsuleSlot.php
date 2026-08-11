<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/**
 * One named slot of a bounded workspace.
 *
 * Each slot pulls its own best candidate rather than the system computing a
 * global relevance ordering, and the current occupant competes for its own slot
 * so retention has to be beaten rather than merely not-overwritten. Every
 * occupant serializes to the same typed shape regardless of which subsystem
 * produced it, so consumers can read across sources.
 */
final class CapsuleSlot extends ActiveRecord
{
    use Getters;

    public static $tableName = 'capsule_slots';
    public static $primaryKey = 'id';

    public static $indexes = [
        'capsule_slots_capsule' => ['fields' => ['capsule_id', 'slot_role'], 'unique' => true],
        'capsule_slots_record' => ['fields' => ['record_type', 'record_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $capsule_id;

    #[Column(type: 'string', length: 64)]
    protected string $slot_role;

    #[Column(type: 'string', length: 64, notnull: false)]
    protected ?string $record_type = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $record_id = null;

    /** The resolved bounded content the worker actually reads. */
    #[Column(type: 'clob', notnull: false)]
    protected ?string $claim = null;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $confidence = 0.0;

    #[Column(type: 'decimal', precision: 9, scale: 4)]
    protected float $score = 0.0;

    #[Column(type: 'timestamp', notnull: false)]
    protected $recorded_at;

    /**
     * Discrete stand-in for the gated update. A partly-open input gate has no
     * arithmetic analogue over records, but the structure survives as a typed
     * transition with a recorded reason.
     */
    #[Column(type: 'enum', values: ['fill', 'keep', 'replace', 'merge', 'empty'])]
    protected string $transition = 'fill';

    #[Column(type: 'clob', notnull: false)]
    protected ?string $transition_reason = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $carried_from_capsule_id = null;

    /** Consecutive capsules this occupant has survived. A stagnation signal. */
    #[Column(type: 'integer', unsigned: true)]
    protected int $carryover_depth = 0;

    /** Reserved slots carry safety content and are exempt from competition. */
    #[Column(type: 'integer', unsigned: true)]
    protected int $reserved = 0;
}
