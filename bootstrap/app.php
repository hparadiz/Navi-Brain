<?php

declare(strict_types=1);

use Divergence\App;
use Divergence\IO\Database\Connections;

require dirname(__DIR__) . '/vendor/autoload.php';

$databasePath = getenv('NAVI_BRAIN_DB');
if ($databasePath === false || $databasePath === '') {
    $databasePath = dirname(__DIR__) . '/var/navi-brain.sqlite';
}

$databaseDirectory = dirname($databasePath);
if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0775, true) && !is_dir($databaseDirectory)) {
    throw new RuntimeException(sprintf('Unable to create database directory: %s', $databaseDirectory));
}

$app = new App(dirname(__DIR__));
Connections::setConnection('sqlite');
$connection = Connections::getConnection();
$connection->exec('PRAGMA busy_timeout = 5000');
$connection->exec('PRAGMA foreign_keys = ON');
$connection->exec('PRAGMA journal_mode = WAL');
$connection->exec('PRAGMA synchronous = NORMAL');

return $app;
