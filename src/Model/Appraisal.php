<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class Appraisal extends ActiveRecord
{
    use Getters;

    public static $tableName = 'appraisals';
    public static $primaryKey = 'id';

    public static $indexes = [
        'appraisals_intention' => ['fields' => ['intention_id']],
        'appraisals_event' => ['fields' => ['event_id']],
        'appraisals_attention_score' => ['fields' => ['attention_score']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $event_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $intention_id = null;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $relevance;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $urgency;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $controllability;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $uncertainty;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $commitment_impact;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $attention_score;
}
