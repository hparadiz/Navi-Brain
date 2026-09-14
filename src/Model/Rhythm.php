<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class Rhythm extends ActiveRecord
{
    public static $tableName = 'rhythms';
    public static $primaryKey = 'id';

    public static $indexes = [
        'rhythms_key' => ['fields' => ['rhythm_key'], 'unique' => true],
        'rhythms_due' => ['fields' => ['status', 'next_due_at']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 48)]
    protected string $rhythm_key;

    #[Column(type: 'integer', unsigned: true)]
    protected int $interval_seconds;

    #[Column(type: 'enum', values: ['low', 'high'])]
    protected string $cognitive_layer;

    #[Column(type: 'timestamp')]
    protected $next_due_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_started_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_completed_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $sequence = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $fencing_token = 0;

    #[Column(type: 'string', length: 160, notnull: false)]
    protected ?string $lease_owner = null;

    #[Column(type: 'timestamp', notnull: false)]
    protected $lease_expires_at;

    #[Column(type: 'enum', values: ['idle', 'running', 'failed', 'paused'])]
    protected string $status = 'idle';

    #[Column(type: 'clob', notnull: false)]
    protected ?string $last_error = null;
}
