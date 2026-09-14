<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class ValueAppraisal extends ActiveRecord
{
    public static $tableName = 'value_appraisals';
    public static $primaryKey = 'id';

    public static $indexes = [
        'value_appraisals_value' => ['fields' => ['value_id', 'id']],
        'value_appraisals_intention' => ['fields' => ['generated_intention_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $value_id;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $event_id = null;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $intention_id = null;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $alignment;

    #[Column(type: 'clob')]
    protected string $evidence;

    #[Column(type: 'enum', values: ['user', 'self', 'outcome'])]
    protected string $source;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $generated_intention_id = null;
}
