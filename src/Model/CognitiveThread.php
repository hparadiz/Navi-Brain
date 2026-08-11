<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class CognitiveThread extends ActiveRecord
{
    use Getters;

    public static $tableName = 'cognitive_threads';
    public static $primaryKey = 'id';

    public static $indexes = [
        'cognitive_threads_key' => ['fields' => ['thread_key'], 'unique' => true],
        'cognitive_threads_status_wake' => ['fields' => ['status', 'wake_at']],
        'cognitive_threads_intention' => ['fields' => ['parent_intention_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $parent_intention_id;

    #[Column(type: 'string', length: 96)]
    protected string $thread_key;

    #[Column(type: 'enum', values: ['user', 'developer', 'system', 'agent'])]
    protected string $authority;

    #[Column(type: 'enum', values: ['observe', 'think', 'prepare', 'act'])]
    protected string $effect_ceiling;

    #[Column(type: 'clob')]
    protected string $concern;

    #[Column(type: 'clob')]
    protected string $current_belief;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $uncertainty = 1.0;

    #[Column(type: 'serialized')]
    protected array $support_refs = [];

    #[Column(type: 'clob')]
    protected string $desired_outcome;

    #[Column(type: 'string', length: 64)]
    protected string $phase;

    #[Column(type: 'string', length: 96)]
    protected string $next_operation;

    #[Column(type: 'clob')]
    protected string $expected_postcondition;

    #[Column(type: 'timestamp', notnull: false)]
    protected $wake_at;

    #[Column(type: 'serialized')]
    protected array $budget = [];

    #[Column(type: 'serialized')]
    protected array $spent = [];

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $progress = 0.0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $stagnation_count = 0;

    #[Column(type: 'clob')]
    protected string $success_condition;

    #[Column(type: 'clob')]
    protected string $release_condition;

    #[Column(type: 'enum', values: ['candidate', 'active', 'waiting', 'blocked', 'complete', 'released'])]
    protected string $status = 'candidate';

    #[Column(type: 'integer', unsigned: true)]
    protected int $version = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $fencing_token = 0;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $last_observation = null;
}
