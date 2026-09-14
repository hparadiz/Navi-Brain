<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class DecisionAsyncClaim extends ActiveRecord
{
    public static $tableName = 'decision_async_claims';
    public static $primaryKey = 'action_id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true)]
    protected int $action_id;

    #[Column(type: 'integer')]
    protected int $decision_cycle_id;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $request_event_id = null;

    #[Column(type: 'clob')]
    protected string $owner;

    #[Column(type: 'clob')]
    protected string $status;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $outcome_hash = null;
}
