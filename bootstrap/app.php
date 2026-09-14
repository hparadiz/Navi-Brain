<?php

declare(strict_types=1);

use Divergence\App;
use Divergence\IO\Database\Connections;
use NaviBrain\Storage\StatePermissions;
use NaviBrain\Model\SqliteCatalog;

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
SqliteCatalog::getAllByQuery('PRAGMA journal_mode = WAL');
SqliteCatalog::getAllByQuery('PRAGMA synchronous = NORMAL');
StatePermissions::hardenDatabaseFiles($databasePath);

return $app;
