<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/**
 * A standing commitment conduct is measured against.
 *
 * Values are the reference signal in a two-loop system: the fast loop scores
 * conduct against them, the slow loop revises them. The slow loop is rate
 * limited on purpose. A reference that moves as fast as the thing it regulates
 * has a degenerate solution: lower every value until nothing ever falls short.
 */
final class HeldValue extends ActiveRecord
{
    use Getters;

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

    /** What the value actually commits to, in conduct terms. */
    #[Column(type: 'clob')]
    protected string $statement;

    /** Why it is held. Revising a value means answering this, not restating it. */
    #[Column(type: 'clob')]
    protected string $rationale;

    /** Salience. Biases which value a shortfall is read against first. */
    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $weight = 0.5;

    #[Column(type: 'enum', values: ['user', 'developer', 'system', 'agent'])]
    protected string $authority;

    #[Column(type: 'enum', values: ['active', 'retired'])]
    protected string $status = 'active';

    /** Slow-loop cadence. Values are reconsidered offline, never mid-task. */
    #[Column(type: 'integer', unsigned: true)]
    protected int $review_interval_hours = 168;

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_reviewed_at;
}
