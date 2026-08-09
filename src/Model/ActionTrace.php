<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class ActionTrace extends ActiveRecord
{
    use Getters;

    public static $tableName = 'action_traces';
    public static $primaryKey = 'id';

    public static $indexes = [
        'action_traces_intention' => ['fields' => ['intention_id']],
        'action_traces_status' => ['fields' => ['status']],
        'action_traces_match_status' => ['fields' => ['match_status']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $completed_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $intention_id;

    #[Column(type: 'clob')]
    protected string $description;

    #[Column(type: 'clob')]
    protected string $expected;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $observed = null;

    #[Column(type: 'enum', values: ['pending', 'succeeded', 'failed', 'cancelled'])]
    protected string $status = 'pending';

    #[Column(type: 'enum', values: ['pending', 'matched', 'mismatched'])]
    protected string $match_status = 'pending';

    #[Column(type: 'clob', notnull: false)]
    protected ?string $repair_note = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $start_event_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $completion_event_id = null;
}
