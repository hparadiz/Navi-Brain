<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class ProcedureInvalidationClaim extends ActiveRecord
{
    public static $tableName = 'procedure_invalidation_claims';
    public static $primaryKey = 'id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true, autoincrement: true)]
    protected ?int $id = null;

    #[Column(type: 'integer')]
    protected int $procedure_id;

    #[Column(type: 'integer')]
    protected int $memory_id;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $action_id = null;

    #[Column(type: 'clob')]
    protected string $reason;
}
