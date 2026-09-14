<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class ValueRevision extends ActiveRecord
{
    public static $tableName = 'value_revisions';
    public static $primaryKey = 'id';

    public static $indexes = [
        'value_revisions_value' => ['fields' => ['value_id', 'id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $value_id;

    #[Column(type: 'clob')]
    protected string $previous_statement;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $previous_weight;

    #[Column(type: 'clob')]
    protected string $new_statement;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $new_weight;

    #[Column(type: 'enum', values: ['world_evidence', 'cost_discovered', 'incoherence', 'user_directive'])]
    protected string $basis;

    #[Column(type: 'clob')]
    protected string $reason;

    #[Column(type: 'enum', values: ['user', 'developer', 'system', 'agent'])]
    protected string $authority = 'agent';

    #[Column(type: 'integer', notnull: false)]
    protected ?int $event_id = null;
}
