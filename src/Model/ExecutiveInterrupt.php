<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class ExecutiveInterrupt extends ActiveRecord
{
    public static $tableName = 'executive_interrupts';
    public static $primaryKey = 'id';

    public static $indexes = [
        'executive_interrupts_status' => ['fields' => ['status']],
        'executive_interrupts_severity' => ['fields' => ['severity']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'clob')]
    protected string $reason;

    #[Column(type: 'enum', values: ['critical', 'high', 'normal'])]
    protected string $severity;

    #[Column(type: 'enum', values: ['pending', 'acknowledged', 'resolved'])]
    protected string $status = 'pending';

    #[Column(type: 'string', length: 128, notnull: false)]
    protected ?string $source = null;

    #[Column(type: 'integer', unsigned: true)]
    protected int $fencing_token = 0;

    #[Column(type: 'string', length: 128, notnull: false)]
    protected ?string $acknowledged_by = null;

    #[Column(type: 'timestamp', notnull: false)]
    protected $acknowledged_at;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $resolution_run_id = null;
}
