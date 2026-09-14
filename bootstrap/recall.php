<?php

declare(strict_types=1);

// The recall transport needs only project classes and the native token daemon.
// Executive commands load Composer and the application bootstrap on demand.
umask(0077);
spl_autoload_register(static function (string $class): void {
    $prefix = 'NaviBrain\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . '/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
