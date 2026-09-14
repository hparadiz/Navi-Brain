<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class MemoryStoreOperation extends ActiveRecord
{
    public static $tableName = 'memory_store_operations';
    public static $primaryKey = 'id';
    public static $autoCreateTables = false;

    #[Column(type: 'integer', primary: true, autoincrement: true)]
    protected ?int $id = null;

    #[Column(type: 'clob', notnull: false)]
    protected ?string $operation_key = null;

    #[Column(type: 'clob')]
    protected string $request_hash;

    #[Column(type: 'integer')]
    protected int $requested_at;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $memory_id = null;

    #[Column(type: 'integer', notnull: false)]
    protected ?int $event_id = null;
}
