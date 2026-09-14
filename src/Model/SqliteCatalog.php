<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class SqliteCatalog extends ActiveRecord
{
    public static $tableName = 'sqlite_schema';
    public static $primaryKey = 'name';
    public static $autoCreateTables = false;

    #[Column(type: 'string', primary: true)]
    protected string $name;

    #[Column(type: 'string')]
    protected string $type;

    #[Column(type: 'string')]
    protected string $tbl_name;

    #[Column(type: 'integer')]
    protected int $rootpage;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $sql = null;
}
