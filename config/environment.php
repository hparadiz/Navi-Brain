<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/.env';
$local = is_file($path) ? (parse_ini_file($path, false, INI_SCANNER_RAW) ?: []) : [];

return array_replace($local, getenv());
