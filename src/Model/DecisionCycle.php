<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class DecisionCycle extends ActiveRecord
{
    public static $tableName = 'decision_cycles';
    public static $primaryKey = 'id';

    public static $indexes = [
        'decision_cycles_status' => ['fields' => ['status', 'updated_at']],
        'decision_cycles_intention' => ['fields' => ['intention_id', 'created_at']],
        'decision_cycles_model' => ['fields' => ['model_id', 'created_at']],
        'decision_cycles_work' => ['fields' => ['proposal_work_item_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $completed_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $intention_id;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $thread_id = null;

    #[Column(type: 'string', length: 160)]
    protected string $trigger;

    #[Column(type: 'string', length: 160, notnull: false)]
    protected ?string $model_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $proposal_work_item_id = null;

    #[Column(type: 'enum', values: ['observe', 'retrieve', 'reason', 'propose', 'evaluate', 'select', 'execute', 'verify', 'adapt', 'complete', 'impasse', 'failed'])]
    protected string $state = 'observe';

    #[Column(type: 'enum', values: ['running', 'waiting', 'completed', 'impasse', 'failed', 'cancelled'])]
    protected string $status = 'running';

    #[Column(type: 'string', length: 64)]
    protected string $working_memory_checksum;

    #[Column(type: 'serialized')]
    protected array $observations = [];

    #[Column(type: 'serialized')]
    protected array $retrieval = [];

    #[Column(type: 'serialized')]
    protected array $reasoning = [];

    #[Column(type: 'serialized')]
    protected array $evaluation = [];

    #[Column(type: 'serialized')]
    protected array $selection = [];

    #[Column(type: 'serialized')]
    protected array $execution = [];

    #[Column(type: 'serialized')]
    protected array $verification = [];

    #[Column(type: 'serialized')]
    protected array $adaptation = [];

    #[Column(type: 'serialized')]
    protected array $stage_timings = [];

    #[Column(type: 'clob', notnull: false)]
    protected ?string $error = null;
}
