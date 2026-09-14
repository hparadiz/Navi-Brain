<?php

declare(strict_types=1);

namespace NaviBrain\Support;

class Name
{
    public static function get(): string
    {
        static $name = null;
        return $name ??= (require dirname(__DIR__, 2) . '/config/app.php')['name'];
    }
}
