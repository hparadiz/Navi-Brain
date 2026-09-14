<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class DecisionPlanningClaim extends ActiveRecord
{
    public static $tableName = 'decision_planning_claims';
    public static $primaryKey = 'cycle_id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true)]
    protected int $cycle_id;

    #[Column(type: 'clob')]
    protected string $owner;

    #[Column(type: 'integer')]
    protected int $claimed_at;

    #[Column(type: 'integer')]
    protected int $settled = 0;
}
