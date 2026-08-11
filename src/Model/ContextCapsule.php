<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/**
 * One assembled bounded workspace for a single cognitive operation.
 *
 * The capsule is neither fresh nor a transcript: it is fixed-size and carried
 * across wakes, so a thread keeps working state between steps without unbounded
 * growth. `checksum` exists so the worker, the curator, and the outcome
 * observer can prove they read the same bytes.
 */
final class ContextCapsule extends ActiveRecord
{
    use Getters;

    public static $tableName = 'context_capsules';
    public static $primaryKey = 'id';

    public static $indexes = [
        'context_capsules_thread' => ['fields' => ['thread_id', 'created_at']],
        'context_capsules_step' => ['fields' => ['thread_step_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $thread_id;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $thread_step_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $previous_capsule_id = null;

    #[Column(type: 'string', length: 96)]
    protected string $trigger;

    /** Slot budget (nm). Config, never a constant: the optimum is task-specific. */
    #[Column(type: 'integer', unsigned: true)]
    protected int $slot_budget;

    /** How many candidates survived stage-one narrowing. */
    #[Column(type: 'integer', unsigned: true)]
    protected int $candidate_count = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $filled_slots = 0;

    #[Column(type: 'string', length: 64)]
    protected string $checksum;
}
