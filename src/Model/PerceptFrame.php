<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/**
 * A window of perception, bit-packed.
 *
 * Raw readings are how a sense compares one sample to the last; they are not
 * how perception should be kept. Once a window has passed, the samples in it
 * are re-encoded as a fixed-width bit stream and the rows are dropped. A
 * presence sample is one bit of real information carried by roughly two hundred
 * bytes of row, and that ratio is what makes an always-on sensorium expensive.
 *
 * The layout travels with the frame, so the stream stays decodable without
 * reference to the code that wrote it. Density is the goal; opacity is not.
 * Text fields do not survive this transition: after the live window, hearing is
 * retained as structure and timing only, never as content.
 */
final class PerceptFrame extends ActiveRecord
{
    use Getters;

    public static $tableName = 'percept_frames';
    public static $primaryKey = 'id';

    public static $indexes = [
        'percept_frames_source' => ['fields' => ['source_key', 'window_start']],
        'percept_frames_codec' => ['fields' => ['codec_version']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'string', length: 64)]
    protected string $source_key;

    #[Column(type: 'integer', unsigned: true)]
    protected int $codec_version;

    #[Column(type: 'timestamp')]
    protected $window_start;

    #[Column(type: 'timestamp')]
    protected $window_end;

    #[Column(type: 'integer', unsigned: true)]
    protected int $sample_count = 0;

    /** Self-describing field layout: name, type, and bit width per field. */
    #[Column(type: 'serialized')]
    protected array $layout = [];

    /** The packed bit stream, base64 encoded for transport through SQLite text. */
    #[Column(type: 'clob')]
    protected string $payload;

    #[Column(type: 'integer', unsigned: true)]
    protected int $packed_bytes = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $source_bytes = 0;

    #[Column(type: 'string', length: 64)]
    protected string $checksum;
}
