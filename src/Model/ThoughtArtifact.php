<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class ThoughtArtifact extends ActiveRecord
{
    use Getters;

    public static $tableName = 'thought_artifacts';
    public static $primaryKey = 'id';

    public static $indexes = [
        'thought_artifacts_run' => ['fields' => ['run_id']],
        'thought_artifacts_status' => ['fields' => ['status']],
        'thought_artifacts_hash' => ['fields' => ['content_hash'], 'unique' => true],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $run_id = null;

    #[Column(type: 'string', length: 96)]
    protected string $kind;

    #[Column(type: 'clob')]
    protected string $content;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $confidence;

    #[Column(type: 'enum', values: ['observed', 'inferred', 'counterfactual', 'daydream', 'worker'])]
    protected string $provenance;

    #[Column(type: 'serialized')]
    protected array $source_ids = [];

    #[Column(type: 'enum', values: ['proposed', 'accepted', 'rejected', 'expired'])]
    protected string $status = 'proposed';

    #[Column(type: 'string', length: 64)]
    protected string $content_hash;
}
