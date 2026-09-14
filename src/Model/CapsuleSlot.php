<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class CapsuleSlot extends ActiveRecord
{
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

    #[Column(type: 'clob', notnull: false)]
    protected ?string $claim = null;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $confidence = 0.0;

    #[Column(type: 'decimal', precision: 9, scale: 4)]
    protected float $score = 0.0;

    #[Column(type: 'timestamp', notnull: false)]
    protected $recorded_at;

    #[Column(type: 'enum', values: ['fill', 'keep', 'replace', 'merge', 'empty'])]
    protected string $transition = 'fill';

    #[Column(type: 'clob', notnull: false)]
    protected ?string $transition_reason = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $carried_from_capsule_id = null;

    #[Column(type: 'integer', unsigned: true)]
    protected int $carryover_depth = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $reserved = 0;
}
