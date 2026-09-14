<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class ModelEndpoint extends ActiveRecord
{
    public static $tableName = 'model_endpoints';
    public static $primaryKey = 'id';

    public static $indexes = [
        'model_endpoints_model' => ['fields' => ['model_id'], 'unique' => true],
        'model_endpoints_status' => ['fields' => ['status', 'cooldown_until']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 255)]
    protected string $model_id;

    #[Column(type: 'string', length: 96)]
    protected string $provider;

    #[Column(type: 'enum', values: ['discovered', 'available', 'degraded', 'unavailable'])]
    protected string $status = 'discovered';

    #[Column(type: 'timestamp')]
    protected $last_discovered_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_probed_at;

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_success_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $consecutive_failures = 0;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $latency_ms = null;

    #[Column(type: 'timestamp', notnull: false)]
    protected $cooldown_until;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $last_error = null;
}
