<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class ThreadStep extends ActiveRecord
{
    public static $tableName = 'thread_steps';
    public static $primaryKey = 'id';

    public static $indexes = [
        'thread_steps_thread' => ['fields' => ['thread_id', 'created_at']],
        'thread_steps_status' => ['fields' => ['status']],
        'thread_steps_fence' => ['fields' => ['thread_id', 'fencing_token'], 'unique' => true],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $completed_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $thread_id;

    #[Column(type: 'string', length: 96)]
    protected string $operation;

    #[Column(type: 'clob')]
    protected string $expected;

    #[Column(type: 'serialized')]
    protected array $pre_state = [];

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $worker_work_item_id = null;

    #[Column(type: 'serialized')]
    protected array $proposal = [];

    #[Column(type: 'serialized')]
    protected array $deterministic_checks = [];

    #[Column(type: 'enum', values: ['pending', 'accepted', 'rejected', 'repair'])]
    protected string $curator_verdict = 'pending';

    #[Column(type: 'serialized')]
    protected array $observed_result = [];

    #[Column(type: 'serialized')]
    protected array $post_state = [];

    #[Column(type: 'timestamp', notnull: false)]
    protected $next_wake_at;

    #[Column(type: 'enum', values: ['running', 'dispatching', 'succeeded', 'failed', 'cancelled'])]
    protected string $status = 'running';

    #[Column(type: 'integer', unsigned: true)]
    protected int $fencing_token;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $error = null;
}
