<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class Event extends ActiveRecord
{
    public static $tableName = 'events';
    public static $primaryKey = 'id';

    public static $indexes = [
        'events_kind' => ['fields' => ['kind']],
        'events_created_at' => ['fields' => ['created_at']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'string', length: 96)]
    protected string $kind;

    #[Column(type: 'serialized')]
    protected array $payload = [];

    #[Column(type: 'string', length: 255, notnull: false)]
    protected ?string $dedupe_key = null;
}
