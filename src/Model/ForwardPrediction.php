<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/** A one-step sensory prediction and its eventual observed error. */
final class ForwardPrediction extends ActiveRecord
{
    use Getters;

    public static $tableName = 'forward_predictions';
    public static $primaryKey = 'id';

    public static $indexes = [
        'forward_predictions_source_status' => ['fields' => ['source_key', 'status']],
        'forward_predictions_observation' => ['fields' => ['observed_reading_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'string', length: 64)]
    protected string $source_key;

    #[Column(type: 'integer', unsigned: true)]
    protected int $based_on_reading_id;

    #[Column(type: 'timestamp')]
    protected $expected_at;

    #[Column(type: 'serialized')]
    protected array $predicted = [];

    /** Reliability of this source's recent predictions, not confidence prose. */
    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $precision = 0.1;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $observed_reading_id = null;

    #[Column(type: 'timestamp', notnull: false)]
    protected $observed_at;

    #[Column(type: 'serialized')]
    protected array $observed = [];

    #[Column(type: 'decimal', precision: 5, scale: 4, notnull: false)]
    protected ?float $prediction_error = null;

    #[Column(type: 'enum', values: ['pending', 'matched', 'violated', 'expired'])]
    protected string $status = 'pending';
}
