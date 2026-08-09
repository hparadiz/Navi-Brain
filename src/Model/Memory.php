<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class Memory extends ActiveRecord
{
    use Getters;

    public static $tableName = 'memories';
    public static $primaryKey = 'id';

    public static $indexes = [
        'memories_tier_status' => ['fields' => ['tier', 'status']],
        'memories_updated_at' => ['fields' => ['updated_at']],
        'memories_source_event' => ['fields' => ['source_event_id']],
    ];

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

    #[Column(type: 'enum', values: ['active', 'superseded', 'expired'])]
    protected string $status = 'active';

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $source_event_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $source_memory_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $supersedes_id = null;

    #[Column(type: 'timestamp', notnull: false)]
    protected $expires_at;
}
