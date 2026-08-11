<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/** One resumable execution of a single or composed procedure. */
final class ProcedureRun extends ActiveRecord
{
    use Getters;

    public static $tableName = 'procedure_runs';
    public static $primaryKey = 'id';

    public static $indexes = [
        'procedure_runs_procedure' => ['fields' => ['procedure_id', 'created_at']],
        'procedure_runs_status' => ['fields' => ['status', 'updated_at']],
        'procedure_runs_intention' => ['fields' => ['intention_id']],
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
    protected int $procedure_id;

    #[Column(type: 'integer', unsigned: true)]
    protected int $intention_id;

    #[Column(type: 'serialized')]
    protected array $arguments = [];

    #[Column(type: 'integer', unsigned: true)]
    protected int $current_step = 0;

    #[Column(type: 'serialized')]
    protected array $results = [];

    #[Column(type: 'serialized')]
    protected array $action_trace_ids = [];

    #[Column(type: 'enum', values: ['running', 'waiting', 'succeeded', 'failed', 'cancelled', 'rejected'])]
    protected string $status = 'running';

    #[Column(type: 'clob', notnull: false)]
    protected ?string $error = null;
}
