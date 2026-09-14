<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class SenseReading extends ActiveRecord
{
    public static $tableName = 'sense_readings';
    public static $primaryKey = 'id';

    public static $indexes = [
        'sense_readings_source' => ['fields' => ['source_key', 'observed_at']],
        'sense_readings_expiry' => ['fields' => ['expires_at']],
        'sense_readings_seq' => ['fields' => ['source_key', 'sequence']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'string', length: 64)]
    protected string $source_key;

    #[Column(type: 'timestamp')]
    protected $observed_at;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $sequence = null;

    #[Column(type: 'serialized')]
    protected array $payload = [];

    #[Column(type: 'string', length: 64)]
    protected string $digest;

    #[Column(type: 'timestamp')]
    protected $expires_at;
}
