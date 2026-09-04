<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class SelfModelFact extends ActiveRecord
{
    use Getters;

    private const MODEL_CONTEXT_PRIVATE_PREFIXES = [
        'appearance.',
    ];

    public static $tableName = 'self_model_facts';
    public static $primaryKey = 'id';

    public static $indexes = [
        'self_model_facts_key' => [
            'fields' => ['fact_key'],
            'unique' => true,
        ],
    ];

    public static function isModelContextVisible(string $factKey): bool
    {
        foreach (self::MODEL_CONTEXT_PRIVATE_PREFIXES as $prefix) {
            if (str_starts_with($factKey, $prefix)) {
                return false;
            }
        }
        return true;
    }

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 160)]
    protected string $fact_key;

    #[Column(type: 'clob')]
    protected string $fact_value;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $confidence;

    #[Column(type: 'integer', unsigned: true)]
    protected int $evidence_event_id;
}
