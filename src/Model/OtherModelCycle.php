<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class OtherModelCycle extends ActiveRecord
{
    public static $tableName = 'other_model_cycles';
    public static $primaryKey = 'id';

    public static $indexes = [
        'other_model_cycles_idempotency' => ['fields' => ['idempotency_key'], 'unique' => true],
        'other_model_cycles_status' => ['fields' => ['status', 'updated_at']],
        'other_model_cycles_trigger' => ['fields' => ['trigger_kind', 'trigger_source', 'trigger_id']],
        'other_model_cycles_parser' => ['fields' => ['parser_work_item_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $completed_at;

    #[Column(type: 'enum', values: ['sense_reading', 'utterance_outcome', 'parser_result', 'correction'])]
    protected string $trigger_kind;

    #[Column(type: 'string', length: 64)]
    protected string $trigger_source;

    #[Column(type: 'integer', unsigned: true)]
    protected int $trigger_id;

    #[Column(type: 'string', length: 160)]
    protected string $idempotency_key;

    #[Column(type: 'enum', values: ['observe', 'establish_observability', 'resolve_prior_predictions', 'update_subintentional_model', 'infer_minimal_model', 'validate_forward_backward', 'select_or_abstain', 'publish_current_state', 'seal_next_predictions', 'complete', 'failed'])]
    protected string $state = 'observe';

    #[Column(type: 'enum', values: ['running', 'waiting', 'completed', 'abstained', 'failed'])]
    protected string $status = 'running';

    #[Column(type: 'serialized')]
    protected array $observation = [];

    #[Column(type: 'serialized')]
    protected array $observability = [];

    #[Column(type: 'serialized')]
    protected array $baseline = [];

    #[Column(type: 'serialized')]
    protected array $hypothesis_ids = [];

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $selected_hypothesis_id = null;

    #[Column(type: 'serialized')]
    protected array $published_state = [];

    #[Column(type: 'integer', unsigned: true)]
    protected int $model_depth = 1;

    #[Column(type: 'decimal', precision: 7, scale: 6)]
    protected float $entropy = 0.0;

    #[Column(type: 'decimal', precision: 7, scale: 6)]
    protected float $surprise = 0.0;

    #[Column(type: 'string', length: 160, notnull: false)]
    protected ?string $abstention_reason = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $parser_work_item_id = null;

    #[Column(type: 'string', length: 160, notnull: false)]
    protected ?string $model_id = null;

    #[Column(type: 'integer', unsigned: true)]
    protected int $model_calls = 0;

    #[Column(type: 'serialized')]
    protected array $stage_timings = [];

    #[Column(type: 'clob', notnull: false)]
    protected ?string $error = null;
}
