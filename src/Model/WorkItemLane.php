<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class WorkItemLane extends ActiveRecord
{
    public static $tableName = 'work_item_lanes';
    public static $primaryKey = 'work_id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true)]
    protected int $work_id;

    #[Column(type: 'clob')]
    protected string $lane;
}
