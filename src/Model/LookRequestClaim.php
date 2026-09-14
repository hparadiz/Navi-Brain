<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class LookRequestClaim extends ActiveRecord
{
    public static $tableName = 'look_request_claims';
    public static $primaryKey = 'event_id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true)]
    protected int $event_id;

    #[Column(type: 'clob')]
    protected string $owner;

    #[Column(type: 'clob')]
    protected string $status;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $outcome_hash = null;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $outcome_data = null;
}
