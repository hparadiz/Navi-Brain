<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/**
 * A change to a value, with the basis that licensed it. The slow loop.
 *
 * Note the basis enum: there is deliberately no "kept falling short" option.
 * Failing repeatedly is evidence about conduct, not about the value. A value
 * revises when the world says something new about it - it turned out to cost
 * something unknown, it contradicts another held value, or the user redirects
 * it. Otherwise lowering the bar is just rationalisation with an audit trail.
 */
final class ValueRevision extends ActiveRecord
{
    use Getters;

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
    protected string $authority;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $event_id = null;
}
