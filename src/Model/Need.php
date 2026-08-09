<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class Need extends ActiveRecord
{
    use Getters;

    public static $tableName = 'needs';
    public static $primaryKey = 'id';

    public static $indexes = [
        'needs_key' => [
            'fields' => ['need_key'],
            'unique' => true,
        ],
        'needs_status' => ['fields' => ['status']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 96)]
    protected string $need_key;

    #[Column(type: 'clob')]
    protected string $description;

    #[Column(type: 'enum', values: ['user', 'developer', 'system', 'agent'])]
    protected string $authority;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $pressure = 0.0;

    #[Column(type: 'decimal', precision: 6, scale: 5)]
    protected float $growth_per_hour;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $trigger_threshold;

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_satisfied_at;

    #[Column(type: 'enum', values: ['active', 'paused'])]
    protected string $status = 'active';
}
