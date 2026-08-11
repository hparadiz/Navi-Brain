<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/** One expiring, depth-one hypothesis whose value is earned by prediction. */
final class OtherModelHypothesis extends ActiveRecord
{
    use Getters;

    public static $tableName = 'other_model_hypotheses';
    public static $primaryKey = 'id';

    public static $indexes = [
        'other_model_hypotheses_live' => ['fields' => ['actor', 'status', 'expires_at']],
        'other_model_hypotheses_cycle' => ['fields' => ['origin_cycle_id']],
        'other_model_hypotheses_kind' => ['fields' => ['kind', 'validation', 'posterior']],
        'other_model_hypotheses_provenance' => ['fields' => ['provenance_kind', 'provenance_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $origin_cycle_id;

    #[Column(type: 'string', length: 32)]
    protected string $actor = 'primary_user';

    #[Column(type: 'integer', unsigned: true)]
    protected int $recursion_order = 1;

    #[Column(type: 'enum', values: ['attention', 'goal', 'belief', 'plan', 'error', 'constraint'])]
    protected string $kind;

    #[Column(type: 'clob')]
    protected string $proposition;

    #[Column(type: 'serialized')]
    protected array $structured = [];

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $prior = 0.5;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $posterior = 0.5;

    #[Column(type: 'enum', values: ['reported', 'authorized_observation', 'inferred'])]
    protected string $knowledge_access;

    #[Column(type: 'enum', values: ['stated', 'inferred'])]
    protected string $representation;

    #[Column(type: 'enum', values: ['sense_reading', 'utterance_outcome', 'direct_statement', 'worker_proposal', 'deterministic_inference'])]
    protected string $provenance_kind;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $provenance_id = null;

    #[Column(type: 'serialized')]
    protected array $evidence = [];

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $forward_score = 0.0;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $backward_score = 0.0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $predictions_resolved = 0;

    #[Column(type: 'enum', values: ['pending', 'usable', 'invalid'])]
    protected string $validation = 'pending';

    #[Column(type: 'enum', values: ['active', 'rejected', 'expired', 'corrected'])]
    protected string $status = 'active';

    #[Column(type: 'timestamp')]
    protected $valid_from;

    #[Column(type: 'timestamp')]
    protected $expires_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $corrected_at;
}
