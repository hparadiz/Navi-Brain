<?php

declare(strict_types=1);

namespace NaviBrain\Storage;

use Divergence\IO\Database\Connections;
use Divergence\IO\Database\Writer\SQLite as SQLiteWriter;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Appraisal;
use NaviBrain\Model\Checkpoint;
use NaviBrain\Model\CycleRun;
use NaviBrain\Model\Event;
use NaviBrain\Model\ExecutiveInterrupt;
use NaviBrain\Model\ModelEndpoint;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\Need;
use NaviBrain\Model\Rhythm;
use NaviBrain\Model\SelfModelFact;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Model\WorkItem;

final class Schema
{
    public const VERSION = 4;

    /** @var list<class-string> */
    private const MODELS = [
        Event::class,
        Intention::class,
        ActionTrace::class,
        Memory::class,
        Need::class,
        SelfModelFact::class,
        Appraisal::class,
        Checkpoint::class,
        Rhythm::class,
        CycleRun::class,
        ThoughtArtifact::class,
        WorkItem::class,
        ModelEndpoint::class,
        ExecutiveInterrupt::class,
    ];

    /** @return array{version: int, tables: list<string>} */
    public function ensure(): array
    {
        $connection = Connections::getConnection();
        $tables = array_map(
            static fn (string $modelClass): string => $modelClass::$tableName,
            self::MODELS
        );
        $versionStatement = $connection->query('PRAGMA user_version');
        $installedVersion = (int) ($versionStatement?->fetchColumn() ?: 0);
        $versionStatement?->closeCursor();
        if ($installedVersion === self::VERSION) {
            return ['version' => self::VERSION, 'tables' => $tables];
        }

        foreach (self::MODELS as $modelClass) {
            $sql = SQLiteWriter::getCreateTable($modelClass);
            foreach (preg_split('/;\s*/', $sql) ?: [] as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $connection->exec($statement);
                }
            }
        }

        $connection->exec('PRAGMA user_version = ' . self::VERSION);

        return [
            'version' => self::VERSION,
            'tables' => $tables,
        ];
    }
}
