<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class MemoryConsolidationScan extends ActiveRecord
{
    public static $tableName = 'memory_consolidation_scan';
    public static $primaryKey = 'id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true)]
    protected int $id;

    #[Column(type: 'integer')]
    protected int $ascending_after_id = 0;

    #[Column(type: 'integer')]
    protected int $selection_turn = 0;

    #[Column(type: 'integer')]
    protected int $pending_after_id = 0;

    #[Column(type: 'integer')]
    protected int $queued_after_id = 0;

    #[Column(type: 'integer')]
    protected int $work_after_id = 0;
}
