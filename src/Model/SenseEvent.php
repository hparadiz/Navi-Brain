<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/**
 * An edge: the moment a sense noticed something change.
 *
 * This is the unit that makes perception informative. A constant reading
 * carries no information no matter how often it is sampled; a transition does.
 * Sense events are what reach attention, and their recorded downstream outcome
 * is the only training signal available without a gradient.
 */
final class SenseEvent extends ActiveRecord
{
    use Getters;

    public static $tableName = 'sense_events';
    public static $primaryKey = 'id';

    public static $indexes = [
        'sense_events_sense' => ['fields' => ['sense_key', 'observed_at']],
        'sense_events_outcome' => ['fields' => ['outcome']],
        'sense_events_unconsumed' => ['fields' => ['outcome', 'significance']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'string', length: 64)]
    protected string $sense_key;

    #[Column(type: 'string', length: 64)]
    protected string $source_key;

    #[Column(type: 'timestamp')]
    protected $observed_at;

    /** Human-readable statement of what changed. This is what attention reads. */
    #[Column(type: 'clob')]
    protected string $summary;

    #[Column(type: 'serialized')]
    protected array $before = [];

    #[Column(type: 'serialized')]
    protected array $after = [];

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $significance = 0.0;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $reading_id = null;

    /** Tuning version that produced this event, so retunes stay attributable. */
    #[Column(type: 'integer', unsigned: true)]
    protected int $tuning_version = 0;

    /**
     * pending: not yet consumed.
     * used: reached a capsule slot or a durable memory.
     * accepted: contributed to an accepted refinement or an accepted utterance.
     * ignored: attention saw it and passed over it.
     * expired: nothing consumed it before it went stale.
     */
    #[Column(type: 'enum', values: ['pending', 'used', 'accepted', 'ignored', 'expired'])]
    protected string $outcome = 'pending';

    #[Column(type: 'clob', notnull: false)]
    protected ?string $outcome_detail = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $memory_id = null;
}
