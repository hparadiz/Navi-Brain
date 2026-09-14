<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class ProcedureCompileGuard extends ActiveRecord
{
    public static $tableName = 'procedure_compile_guards';
    public static $primaryKey = 'id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true, autoincrement: true)]
    protected ?int $id = null;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $shape_key = null;

    #[Column(type: 'integer')]
    protected int $current_generation = 0;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $pending_generation = null;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $pending_hash = null;
}
