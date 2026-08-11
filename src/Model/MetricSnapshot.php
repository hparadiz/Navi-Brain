<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class MetricSnapshot extends ActiveRecord
{
    use Getters;

    public static $tableName = 'metric_snapshots';
    public static $primaryKey = 'id';

    public static $indexes = [
        'metric_snapshots_protocol' => ['fields' => ['protocol_version', 'scope_key', 'created_at']],
        'metric_snapshots_node' => ['fields' => ['node_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'string', length: 64)]
    protected string $protocol_version;

    #[Column(type: 'string', length: 128, notnull: false)]
    protected ?string $scope_key = null;

    #[Column(type: 'string', length: 128)]
    protected string $node_id;

    #[Column(type: 'serialized')]
    protected array $vector = [];

    #[Column(type: 'clob')]
    protected string $manifest;

    #[Column(type: 'string', length: 64)]
    protected string $checksum;
}