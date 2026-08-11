<?php

declare(strict_types=1);

use Divergence\App;
use Divergence\IO\Database\Connections;
use NaviBrain\Storage\StatePermissions;

require dirname(__DIR__) . '/vendor/autoload.php';

umask(0077);

$databasePath = getenv('NAVI_BRAIN_DB');
if ($databasePath === false || $databasePath === '') {
    $databasePath = dirname(__DIR__) . '/var/navi-brain.sqlite';
}

$projectRoot = dirname(__DIR__);
StatePermissions::prepareDatabaseDirectory($databasePath, $projectRoot);

$app = new App($projectRoot);
Connections::setConnection('sqlite');
$connection = Connections::getConnection();
$connection->exec('PRAGMA busy_timeout = 5000');
$connection->exec('PRAGMA foreign_keys = ON');
$connection->exec('PRAGMA journal_mode = WAL');
$connection->exec('PRAGMA synchronous = NORMAL');
StatePermissions::hardenDatabaseFiles($databasePath);

return $app;
