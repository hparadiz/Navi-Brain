<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class CycleRun extends ActiveRecord
{
    use Getters;

    public static $tableName = 'cycle_runs';
    public static $primaryKey = 'id';

    public static $indexes = [
        'cycle_runs_slot' => ['fields' => ['rhythm_id', 'scheduled_for'], 'unique' => true],
        'cycle_runs_status' => ['fields' => ['status']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $completed_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $rhythm_id;

    #[Column(type: 'enum', values: ['low', 'high'])]
    protected string $cognitive_layer;

    #[Column(type: 'timestamp')]
    protected $scheduled_for;

    #[Column(type: 'string', length: 160)]
    protected string $node_id;

    #[Column(type: 'integer', unsigned: true)]
    protected int $fencing_token;

    #[Column(type: 'enum', values: ['running', 'completed', 'failed', 'cancelled'])]
    protected string $status = 'running';

    #[Column(type: 'integer', unsigned: true)]
    protected int $event_watermark = 0;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $checkpoint_id = null;

    #[Column(type: 'serialized')]
    protected array $budget = [];

    #[Column(type: 'string', length: 255, notnull: false)]
    protected ?string $model = null;

    #[Column(type: 'serialized')]
    protected array $output = [];

    #[Column(type: 'clob', notnull: false)]
    protected ?string $error = null;
}
