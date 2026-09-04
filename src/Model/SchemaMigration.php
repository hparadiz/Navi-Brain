<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

final class SchemaMigration extends ActiveRecord
{
    use Getters;

    public static $tableName = 'schema_migrations';
    public static $primaryKey = 'version';
    public static $autoCreateTables = false;

    public static $indexes = [
        'schema_migrations_name' => ['fields' => ['name'], 'unique' => true],
    ];

    #[Column(type: 'integer', primary: true, unsigned: true)]
    protected int $version;

    #[Column(type: 'string', length: 160)]
    protected string $name;

    #[Column(type: 'string', length: 64)]
    protected string $checksum;

    #[Column(type: 'integer', unsigned: true)]
    protected int $applied_at;
}
