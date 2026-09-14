<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class PerceptFrame extends ActiveRecord
{
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

    #[Column(type: 'serialized')]
    protected array $layout = [];

    #[Column(type: 'clob')]
    protected string $payload;

    #[Column(type: 'integer', unsigned: true)]
    protected int $packed_bytes = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $source_bytes = 0;

    #[Column(type: 'string', length: 64)]
    protected string $checksum;
}
