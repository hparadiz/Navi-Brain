<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class Checkpoint extends ActiveRecord
{
    use Getters;

    public static $tableName = 'checkpoints';
    public static $primaryKey = 'id';

    public static $indexes = [
        'checkpoints_created_at' => ['fields' => ['created_at']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'string', length: 96)]
    protected string $reason;

    #[Column(type: 'serialized')]
    protected array $snapshot = [];
}
