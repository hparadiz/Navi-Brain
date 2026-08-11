<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/** One proposed action and the deterministic evidence used to value it. */
final class DecisionCandidate extends ActiveRecord
{
    use Getters;

    public static $tableName = 'decision_candidates';
    public static $primaryKey = 'id';

    public static $indexes = [
        'decision_candidates_cycle_rank' => ['fields' => ['decision_cycle_id', 'rank'], 'unique' => true],
        'decision_candidates_status' => ['fields' => ['status']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $decision_cycle_id;

    #[Column(type: 'integer', unsigned: true)]
    protected int $rank;

    #[Column(type: 'string', length: 96)]
    protected string $action_kind;

    #[Column(type: 'clob')]
    protected string $description;

    #[Column(type: 'clob')]
    protected string $expected;

    #[Column(type: 'serialized')]
    protected array $arguments = [];

    #[Column(type: 'clob')]
    protected string $rationale;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $proposed_confidence = 0.0;

    #[Column(type: 'decimal', precision: 9, scale: 4)]
    protected float $score = 0.0;

    #[Column(type: 'serialized')]
    protected array $evaluation = [];

    #[Column(type: 'enum', values: ['proposed', 'rejected', 'selected'])]
    protected string $status = 'proposed';
}
