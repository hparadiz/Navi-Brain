<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class SenseEvent extends ActiveRecord
{
    public static $tableName = 'sense_events';
    public static $primaryKey = 'id';

    public static $indexes = [
        'sense_events_sense' => ['fields' => ['sense_key', 'observed_at']],
        'sense_events_outcome' => ['fields' => ['outcome']],
        'sense_events_unconsumed' => ['fields' => ['outcome', 'significance']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'string', length: 64)]
    protected string $sense_key;

    #[Column(type: 'string', length: 64)]
    protected string $source_key;

    #[Column(type: 'timestamp')]
    protected $observed_at;

    #[Column(type: 'clob')]
    protected string $summary;

    #[Column(type: 'serialized')]
    protected array $before = [];

    #[Column(type: 'serialized')]
    protected array $after = [];

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $significance = 0.0;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $reading_id = null;

    #[Column(type: 'integer', unsigned: true)]
    protected int $tuning_version = 0;

    #[Column(type: 'enum', values: ['pending', 'used', 'accepted', 'ignored', 'expired'])]
    protected string $outcome = 'pending';

    #[Column(type: 'clob', notnull: false)]
    protected ?string $outcome_detail = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $memory_id = null;
}
