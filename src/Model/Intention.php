<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class Intention extends ActiveRecord
{
    use Getters;

    public static $tableName = 'intentions';
    public static $primaryKey = 'id';

    public static $indexes = [
        'intentions_status' => ['fields' => ['status']],
        'intentions_authority' => ['fields' => ['authority']],
        'intentions_updated_at' => ['fields' => ['updated_at']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 255)]
    protected string $title;

    #[Column(type: 'clob')]
    protected string $reason;

    #[Column(type: 'enum', values: ['user', 'developer', 'system', 'agent'])]
    protected string $authority;

    #[Column(type: 'enum', values: ['active', 'blocked', 'completed', 'released'])]
    protected string $status = 'active';

    #[Column(type: 'clob')]
    protected string $next_action;

    #[Column(type: 'clob')]
    protected string $success_condition;

    #[Column(type: 'clob')]
    protected string $release_condition;

    #[Column(type: 'serialized')]
    protected array $dependencies = [];

    #[Column(type: 'integer', notnull: false)]
    protected ?int $parent_id = null;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $closure_note = null;
}
