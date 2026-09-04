<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/** Structured execution metadata for an ActionTrace. */
final class ActionExecution extends ActiveRecord
{
    use Getters;

    public static $tableName = 'action_executions';
    public static $primaryKey = 'id';

    public static $indexes = [
        'action_executions_trace' => ['fields' => ['action_trace_id'], 'unique' => true],
        'action_executions_run_step' => ['fields' => ['procedure_run_id', 'step_index']],
        'action_executions_dispatch' => ['fields' => ['dispatch_event_id']],
        'action_executions_kind_status' => ['fields' => ['action_kind', 'status']],
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
    protected int $action_trace_id;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $procedure_id = null;

    /** Immutable token-memory generation that authorized this action. */
    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $procedure_memory_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $procedure_run_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $step_index = null;

    #[Column(type: 'string', length: 96)]
    protected string $action_kind;

    #[Column(type: 'serialized')]
    protected array $arguments = [];

    #[Column(type: 'serialized')]
    protected array $verifier = [];

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $dispatch_event_id = null;

    #[Column(type: 'serialized')]
    protected array $observed = [];

    #[Column(type: 'integer', unsigned: true)]
    protected int $verified = 0;

    #[Column(type: 'enum', values: ['pending', 'dispatching', 'waiting', 'succeeded', 'failed', 'cancelled'])]
    protected string $status = 'pending';
}
