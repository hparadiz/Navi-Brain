<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class DecisionRecoveryScan extends ActiveRecord
{
    public static $tableName = 'decision_recovery_scan';
    public static $primaryKey = 'id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true)]
    protected int $id;

    #[Column(type: 'integer')]
    protected int $planning_after_id = 0;

    #[Column(type: 'integer')]
    protected int $integration_after_id = 0;

    #[Column(type: 'integer')]
    protected int $action_after_id = 0;

    #[Column(type: 'integer')]
    protected int $unattempted_action_after_id = 0;
}
