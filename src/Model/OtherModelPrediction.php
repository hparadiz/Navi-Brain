<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/** A sealed categorical forecast over one authorized observable feature. */
final class OtherModelPrediction extends ActiveRecord
{
    use Getters;

    public static $tableName = 'other_model_predictions';
    public static $primaryKey = 'id';

    public static $indexes = [
        'other_model_predictions_pending' => ['fields' => ['source_key', 'feature_key', 'status', 'deadline']],
        'other_model_predictions_cycle' => ['fields' => ['cycle_id', 'model_kind']],
        'other_model_predictions_hypothesis' => ['fields' => ['hypothesis_id', 'status']],
        'other_model_predictions_observed' => ['fields' => ['observed_cycle_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $cycle_id;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $hypothesis_id = null;

    #[Column(type: 'enum', values: ['baseline', 'hypothesis'])]
    protected string $model_kind;

    #[Column(type: 'string', length: 64)]
    protected string $source_key;

    #[Column(type: 'string', length: 64)]
    protected string $feature_key;

    #[Column(type: 'enum', values: ['equals'])]
    protected string $operator = 'equals';

    #[Column(type: 'string', length: 96)]
    protected string $expected_value;

    #[Column(type: 'serialized')]
    protected array $distribution = [];

    #[Column(type: 'serialized')]
    protected array $baseline_distribution = [];

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $probability = 0.0;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $specificity = 1.0;

    #[Column(type: 'timestamp')]
    protected $sealed_at;

    #[Column(type: 'timestamp')]
    protected $deadline;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $observed_cycle_id = null;

    #[Column(type: 'string', length: 96, notnull: false)]
    protected ?string $outcome_value = null;

    #[Column(type: 'timestamp', notnull: false)]
    protected $observed_at;

    #[Column(type: 'decimal', precision: 8, scale: 6, notnull: false)]
    protected ?float $brier_score = null;

    #[Column(type: 'decimal', precision: 10, scale: 6, notnull: false)]
    protected ?float $log_loss = null;

    #[Column(type: 'enum', values: ['pending', 'matched', 'violated', 'expired', 'unresolvable'])]
    protected string $status = 'pending';
}
