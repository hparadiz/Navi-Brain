<?php

declare(strict_types=1);

$defaultPath = dirname(__DIR__) . '/var/navi-brain.sqlite';
$configuredPath = getenv('NAVI_BRAIN_DB');

return [
    'sqlite' => [
        'path' => $configuredPath !== false && $configuredPath !== ''
            ? $configuredPath
            : $defaultPath,
        'foreign_keys' => true,
        'busy_timeout' => 5000,
    ],
];
