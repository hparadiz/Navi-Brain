<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class Need extends ActiveRecord
{
    public static $tableName = 'needs';
    public static $primaryKey = 'id';

    public static $indexes = [
        'needs_key' => [
            'fields' => ['need_key'],
            'unique' => true,
        ],
        'needs_status' => ['fields' => ['status']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 96)]
    protected string $need_key;

    #[Column(type: 'clob')]
    protected string $description;

    #[Column(type: 'enum', values: ['user', 'developer', 'system', 'agent'])]
    protected string $authority = 'agent';

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $pressure = 0.0;

    #[Column(type: 'decimal', precision: 6, scale: 5)]
    protected float $growth_per_hour;

    #[Column(type: 'decimal', precision: 4, scale: 3)]
    protected float $trigger_threshold;

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_satisfied_at;

    #[Column(type: 'enum', values: ['active', 'paused'])]
    protected string $status = 'active';

    public function validate($deep = true)
    {
        parent::validate($deep);
        foreach (['need_key', 'description'] as $field) {
            if (trim((string) $this->getValue($field)) === '') {
                $this->addValidationError($field, str_replace('_', ' ', $field) . ' cannot be empty.');
            }
        }
        foreach (['pressure', 'growth_per_hour', 'trigger_threshold'] as $field) {
            $value = (float) $this->getValue($field);
            if (!is_finite($value) || $value < 0.0 || $value > 1.0) {
                $this->addValidationError($field, str_replace('_', ' ', $field) . ' must be between 0 and 1.');
            }
        }
        if (!in_array($this->status, ['active', 'paused'], true)) {
            $this->addValidationError('status', 'Need status must be active or paused.');
        }
        if (!in_array($this->authority, ['user', 'developer', 'system', 'agent'], true)) {
            $this->addValidationError('authority', 'Need authority must be user, developer, system or agent.');
        }
        return $this->isValid;
    }

}
