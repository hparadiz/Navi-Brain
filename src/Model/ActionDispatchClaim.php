<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class ActionDispatchClaim extends ActiveRecord
{
    public static $tableName = 'action_dispatch_claims';
    public static $primaryKey = 'action_trace_id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true)]
    protected int $action_trace_id;

    #[Column(type: 'clob')]
    protected string $owner;

    #[Column(type: 'integer')]
    protected int $claimed_at;

    #[Column(type: 'clob')]
    protected string $status = 'legacy';

    #[Column(type: 'clob', notnull: false)]
    protected ?string $outcome_hash = null;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $outcome_data = null;
}
