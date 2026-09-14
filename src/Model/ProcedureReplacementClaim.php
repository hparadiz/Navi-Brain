<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class ProcedureReplacementClaim extends ActiveRecord
{
    public static $tableName = 'procedure_replacement_claims';
    public static $primaryKey = 'id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true, autoincrement: true)]
    protected ?int $id = null;

    #[Column(type: 'integer')]
    protected int $procedure_id;

    #[Column(type: 'integer')]
    protected int $previous_memory_id;

    #[Column(type: 'clob')]
    protected string $shape_key;

    #[Column(type: 'integer')]
    protected int $generation;

    #[Column(type: 'clob')]
    protected string $pending_hash;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $next_memory_id = null;
}
