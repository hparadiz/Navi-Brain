<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class MemoryConsolidationEpisode extends ActiveRecord
{
    public static $tableName = 'memory_consolidation_episodes';
    public static $primaryKey = 'id';

    public static $indexes = [
        'memory_consolidation_episode' => ['fields' => ['episode_id'], 'unique' => true],
        'memory_consolidation_status' => ['fields' => ['status', 'episode_id']],
        'memory_consolidation_work' => ['fields' => ['work_item_id']],
        'memory_consolidation_semantic' => ['fields' => ['semantic_memory_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $episode_id;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $work_item_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $semantic_memory_id = null;

    #[Column(type: 'enum', values: ['pending', 'queued', 'consolidated', 'rejected', 'excluded'])]
    protected string $status = 'pending';

    #[Column(type: 'integer', unsigned: true)]
    protected int $attempts = 0;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $reason = null;

    #[Column(type: 'integer', unsigned: true)]
    protected int $validator_version = 4;
}
