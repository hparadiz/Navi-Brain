<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord as DivergenceActiveRecord;

abstract class ActiveRecord extends DivergenceActiveRecord
{
    private static array $modelDefaults = [];

    public static function __callStatic(string $name, array $arguments)
    {
        return static::Factory()->$name(...$arguments);
    }

    public static function getRootClassName(): string
    {
        return static::class;
    }

    public function __construct($record = [], $isDirty = false, $isPhantom = null)
    {
        $new = $isPhantom ?? ($record === []);
        if ($new && static::fieldExists('created_at') && !array_key_exists('created_at', $record)) {
            $record['created_at'] = time();
        }
        if ($new) {
            if (!isset(static::$modelDefaults[static::class])) {
                static::$modelDefaults[static::class] = array_filter(
                    (new \ReflectionClass(static::class))->getDefaultProperties(),
                    static fn (mixed $value, string $field): bool => $value !== null && static::fieldExists($field),
                    ARRAY_FILTER_USE_BOTH
                );
            }
            $record += static::$modelDefaults[static::class];
        }
        parent::__construct($record, $isDirty, $new);
    }

    public function getValue($name)
    {
        if (!static::fieldExists($name) && array_key_exists($name, $this->_record)) {
            return $this->_record[$name];
        }
        return parent::getValue($name);
    }
}
