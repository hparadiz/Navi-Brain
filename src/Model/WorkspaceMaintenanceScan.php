<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class WorkspaceMaintenanceScan extends ActiveRecord
{
    public static $tableName = 'workspace_maintenance_scan';
    public static $primaryKey = 'id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true)]
    protected int $id;

    #[Column(type: 'integer')]
    protected int $priority_after_id = 0;

    #[Column(type: 'integer')]
    protected int $audit_after_id = 0;

    #[Column(type: 'integer')]
    protected int $audit_next = 0;
}
