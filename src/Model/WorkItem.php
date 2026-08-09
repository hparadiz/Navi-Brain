<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class WorkItem extends ActiveRecord
{
    use Getters;

    public static $tableName = 'work_items';
    public static $primaryKey = 'id';

    public static $indexes = [
        'work_items_status' => ['fields' => ['status', 'created_at']],
        'work_items_idempotency' => ['fields' => ['idempotency_key'], 'unique' => true],
        'work_items_parent_run' => ['fields' => ['parent_run_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $completed_at;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $parent_run_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $parent_intention_id = null;

    #[Column(type: 'string', length: 96)]
    protected string $work_type;

    #[Column(type: 'clob')]
    protected string $prompt;

    #[Column(type: 'serialized')]
    protected array $input_refs = [];

    #[Column(type: 'serialized')]
    protected array $allowed_actions = [];

    #[Column(type: 'integer', unsigned: true)]
    protected int $token_budget;

    #[Column(type: 'integer', unsigned: true)]
    protected int $wall_budget_seconds;

    #[Column(type: 'integer', unsigned: true)]
    protected int $depth = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $max_depth = 0;

    #[Column(type: 'string', length: 96)]
    protected string $idempotency_key;

    #[Column(type: 'string', length: 160, notnull: false)]
    protected ?string $lease_owner = null;

    #[Column(type: 'timestamp', notnull: false)]
    protected $lease_expires_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $fencing_token = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $attempts = 0;

    #[Column(type: 'enum', values: ['queued', 'leased', 'completed', 'failed', 'cancelled'])]
    protected string $status = 'queued';

    #[Column(type: 'string', length: 255, notnull: false)]
    protected ?string $model = null;

    #[Column(type: 'serialized')]
    protected array $result = [];

    #[Column(type: 'clob', notnull: false)]
    protected ?string $error = null;
}
