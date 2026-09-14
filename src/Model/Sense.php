<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class Sense extends ActiveRecord
{
    public static $tableName = 'senses';
    public static $primaryKey = 'id';

    public static $indexes = [
        'senses_key' => ['fields' => ['sense_key'], 'unique' => true],
        'senses_source' => ['fields' => ['source_key', 'status']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 64)]
    protected string $sense_key;

    #[Column(type: 'string', length: 64)]
    protected string $source_key;

    #[Column(type: 'enum', values: ['user', 'agent'])]
    protected string $author = 'agent';

    #[Column(type: 'clob')]
    protected string $notices;

    #[Column(type: 'enum', values: ['change', 'threshold', 'absence', 'rate', 'pattern'])]
    protected string $detector;

    #[Column(type: 'serialized')]
    protected array $config = [];

    #[Column(type: 'integer', unsigned: true)]
    protected int $refractory_seconds = 60;

    #[Column(type: 'enum', values: ['active', 'paused', 'retired'])]
    protected string $status = 'active';

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_fired_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $events_emitted = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $events_useful = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $events_wasted = 0;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $precision_estimate = 0.0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $tuning_version = 0;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $last_tuning_reason = null;
}
