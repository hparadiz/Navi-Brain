<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/**
 * A derived detector over an authorized source.
 *
 * Senses are the part Navi may invent freely. A sense adds no access: it says
 * what counts as a change worth noticing in a stream Navi is already permitted
 * to perceive. Its thresholds are tuned from what its events actually caused
 * downstream, so a sense that keeps crying wolf loses precision and pauses
 * itself rather than training the system to ignore it.
 */
final class Sense extends ActiveRecord
{
    use Getters;

    public static $tableName = 'senses';
    public static $primaryKey = 'id';

    public static $indexes = [
        'senses_key' => ['fields' => ['sense_key'], 'unique' => true],
        'senses_source' => ['fields' => ['source_key', 'status']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 64)]
    protected string $sense_key;

    /** Must name an active sensory_source. Enforced before any sense runs. */
    #[Column(type: 'string', length: 64)]
    protected string $source_key;

    /** 'agent' senses are self-authored; they still inherit the source's ceiling. */
    #[Column(type: 'enum', values: ['user', 'agent'])]
    protected string $author = 'agent';

    #[Column(type: 'clob')]
    protected string $notices;

    /**
     * change: any difference in the watched field.
     * threshold: numeric field crossing a bound.
     * absence: the source going quiet for longer than a window.
     * rate: more than N readings in a window.
     * pattern: a regular expression matching a text field.
     */
    #[Column(type: 'enum', values: ['change', 'threshold', 'absence', 'rate', 'pattern'])]
    protected string $detector;

    /** Detector parameters. These are what tuning adjusts. */
    #[Column(type: 'serialized')]
    protected array $config = [];

    /** Suppress repeat firing for this long after an event. */
    #[Column(type: 'integer', unsigned: true)]
    protected int $refractory_seconds = 60;

    #[Column(type: 'enum', values: ['active', 'paused', 'retired'])]
    protected string $status = 'active';

    #[Column(type: 'timestamp', notnull: false)]
    protected $last_fired_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $events_emitted = 0;

    /** Events that reached a capsule slot or an accepted downstream outcome. */
    #[Column(type: 'integer', unsigned: true)]
    protected int $events_useful = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $events_wasted = 0;

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $precision_estimate = 0.0;

    /** Incremented on every retune, so a sense's history stays auditable. */
    #[Column(type: 'integer', unsigned: true)]
    protected int $tuning_version = 0;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $last_tuning_reason = null;
}
