<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class WorkingMemorySlot extends ActiveRecord
{
    public static $tableName = 'working_memory_slots';
    public static $primaryKey = 'id';

    public static $indexes = [
        'working_memory_scope_role' => ['fields' => ['scope_key', 'slot_role'], 'unique' => true],
        'working_memory_thread' => ['fields' => ['thread_id', 'updated_at']],
        'working_memory_expiry' => ['fields' => ['status', 'expires_at']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 96)]
    protected string $scope_key = 'shared';

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $thread_id = null;

    #[Column(type: 'string', length: 64)]
    protected string $slot_role;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $source_capsule_id = null;

    #[Column(type: 'string', length: 64, notnull: false)]
    protected ?string $record_type = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $record_id = null;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $claim = null;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $confidence = 0.0;

    #[Column(type: 'decimal', precision: 9, scale: 4)]
    protected float $score = 0.0;

    #[Column(type: 'timestamp', notnull: false)]
    protected $recorded_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $carryover_depth = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $reserved = 0;

    #[Column(type: 'enum', values: ['active', 'expired'])]
    protected string $status = 'active';

    #[Column(type: 'timestamp')]
    protected $expires_at;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $projection_memory_id = null;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $projection_pending = null;

    public function __construct($record = [], $isDirty = false, $isPhantom = null)
    {
        if ($isPhantom ?? $record === []) {
            $now = time();
            $record += [
                'recorded_at' => $now,
                'expires_at' => $now + 900,
                'score' => max(0.0, min(1.0, (float) ($record['confidence'] ?? 0.0))),
            ];
            $expiresAt = $record['expires_at'];
            if (is_string($expiresAt) && !is_numeric($expiresAt)) {
                $expiresAt = strtotime($expiresAt);
            }
            $record['expires_at'] = $now + max(30, min(86400, (int) $expiresAt - $now));
        }
        parent::__construct($record, $isDirty, $isPhantom);
    }

    public function validate($deep = true)
    {
        $this->setFields([
            'claim' => mb_substr(trim((string) $this->claim), 0, 800),
            'confidence' => max(0.0, min(1.0, (float) $this->confidence)),
            'record_type' => trim((string) $this->record_type) ?: null,
        ]);
        parent::validate($deep);
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) $this->slot_role) !== 1) {
            $this->addValidationError('slot_role', 'Working-memory role must be a lowercase identifier.');
        }
        if ($this->scope_key !== 'shared' && preg_match('/^thread:\d+$/', (string) $this->scope_key) !== 1) {
            $this->addValidationError('scope_key', 'Working-memory scope must be shared or thread:<id>.');
        }
        if (trim((string) $this->claim) === '') {
            $this->addValidationError('claim', 'Working-memory claim cannot be empty.');
        }
        return $this->isValid;
    }
}
