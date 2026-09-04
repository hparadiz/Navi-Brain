<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/** A validated executable procedure, distinct from its human-readable memory. */
final class Procedure extends ActiveRecord
{
    use Getters;

    public static $tableName = 'procedures';
    public static $primaryKey = 'id';

    public static $indexes = [
        'procedures_key' => ['fields' => ['procedure_key'], 'unique' => true],
        'procedures_memory' => ['fields' => ['memory_id'], 'unique' => true],
        'procedures_status' => ['fields' => ['status', 'updated_at']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $memory_id;

    #[Column(type: 'string', length: 64)]
    protected string $procedure_key;

    #[Column(type: 'string', length: 160)]
    protected string $name;

    #[Column(type: 'clob')]
    protected string $description;

    /** Ordered, typed adapter calls. */
    #[Column(type: 'serialized')]
    protected array $steps = [];

    /** JSON-schema-shaped description of accepted runtime arguments. */
    #[Column(type: 'serialized')]
    protected array $input_schema = [];

    #[Column(type: 'enum', values: ['observe', 'think', 'prepare', 'act'])]
    protected string $effect_ceiling = 'think';

    #[Column(type: 'enum', values: ['user', 'developer', 'system', 'agent'])]
    protected string $authority = 'agent';

    #[Column(type: 'enum', values: ['active', 'retiring', 'invalidated'])]
    protected string $status = 'active';

    #[Column(type: 'serialized')]
    protected array $evidence_action_ids = [];

    #[Column(type: 'integer', unsigned: true)]
    protected int $success_streak = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $failure_count = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $execution_count = 0;

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_executed_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $invalidated_at;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $invalidation_reason = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $source_event_id = null;
}
