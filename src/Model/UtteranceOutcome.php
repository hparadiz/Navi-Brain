<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class UtteranceOutcome extends ActiveRecord
{
    public static $tableName = 'utterance_outcomes';
    public static $primaryKey = 'id';

    public static $indexes = [
        'utterance_outcomes_step' => ['fields' => ['thread_step_id'], 'unique' => true],
        'utterance_outcomes_status' => ['fields' => ['status', 'spoken_at']],
        'utterance_outcomes_descriptor' => ['fields' => ['descriptor']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $thread_step_id;

    #[Column(type: 'timestamp')]
    protected $spoken_at;

    #[Column(type: 'clob')]
    protected string $utterance;

    #[Column(type: 'integer', unsigned: true)]
    protected int $window_seconds = 120;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $response_latency_seconds = null;

    #[Column(type: 'integer', unsigned: true)]
    protected int $responses_in_window = 0;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $response_text = null;

    #[Column(type: 'integer', unsigned: true)]
    protected int $reactions_in_window = 0;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $present_at_utterance = null;

    #[Column(type: 'enum', values: ['present', 'away', 'unknown'])]
    protected string $presence_state_at_utterance = 'unknown';

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $engagement = 0.0;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $observability_weight = 0.0;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $observability_basis = null;

    #[Column(type: 'string', length: 48, notnull: false)]
    protected ?string $descriptor = null;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $descriptor_confidence = 0.0;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $descriptor_reason = null;

    #[Column(type: 'timestamp', notnull: false)]
    protected $reflected_at;

    #[Column(type: 'enum', values: ['awaiting_window', 'observed', 'reflected', 'inconclusive'])]
    protected string $status = 'awaiting_window';
}
