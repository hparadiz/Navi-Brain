<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class HeldValue extends ActiveRecord
{
    public static $tableName = 'held_values';
    public static $primaryKey = 'id';

    public static $indexes = [
        'held_values_key' => [
            'fields' => ['value_key'],
            'unique' => true,
        ],
        'held_values_status' => ['fields' => ['status']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 96)]
    protected string $value_key;

    #[Column(type: 'clob')]
    protected string $statement;

    #[Column(type: 'clob')]
    protected string $rationale;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $weight = 0.5;

    #[Column(type: 'enum', values: ['user', 'developer', 'system', 'agent'])]
    protected string $authority = 'agent';

    #[Column(type: 'enum', values: ['active', 'retired'])]
    protected string $status = 'active';

    #[Column(type: 'integer', unsigned: true)]
    protected int $review_interval_hours = 168;

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_reviewed_at;
}
