<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class SensorySource extends ActiveRecord
{
    public static $tableName = 'sensory_sources';
    public static $primaryKey = 'id';

    public static $indexes = [
        'sensory_sources_key' => ['fields' => ['source_key'], 'unique' => true],
        'sensory_sources_status' => ['fields' => ['status']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 64)]
    protected string $source_key;

    #[Column(type: 'clob')]
    protected string $description;

    #[Column(type: 'enum', values: ['user', 'developer'])]
    protected string $authority = 'user';

    #[Column(type: 'clob')]
    protected string $reveals;

    #[Column(type: 'enum', values: ['observe'])]
    protected string $effect_ceiling = 'observe';

    #[Column(type: 'enum', values: ['continuous', 'on_demand'])]
    protected string $acquisition = 'continuous';

    #[Column(type: 'integer', unsigned: true)]
    protected int $sample_interval_seconds = 60;

    #[Column(type: 'integer', unsigned: true)]
    protected int $reading_ttl_seconds = 3600;

    #[Column(type: 'enum', values: ['active', 'paused', 'revoked'])]
    protected string $status = 'active';

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_reading_at;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $last_error = null;
}
