<?php

declare(strict_types=1);

namespace NaviBrain\Storage;

use Divergence\IO\Database\Connections;
use Divergence\IO\Database\Writer\SQLite as SQLiteWriter;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Appraisal;
use NaviBrain\Model\CapsuleSlot;
use NaviBrain\Model\Checkpoint;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\ContextCapsule;
use NaviBrain\Model\CycleRun;
use NaviBrain\Model\DecisionCandidate;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Event;
use NaviBrain\Model\ExecutiveInterrupt;
use NaviBrain\Model\ForwardPrediction;
use NaviBrain\Model\ModelEndpoint;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\MetricSnapshot;
use NaviBrain\Model\Need;
use NaviBrain\Model\OtherAgentFrameFact;
use NaviBrain\Model\OtherModelCycle;
use NaviBrain\Model\OtherModelHypothesis;
use NaviBrain\Model\OtherModelPrediction;
use NaviBrain\Model\PerceptFrame;
use NaviBrain\Model\Procedure;
use NaviBrain\Model\ProcedureRun;
use NaviBrain\Model\Rhythm;
use NaviBrain\Model\SelfModelFact;
use NaviBrain\Model\Sense;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\SensorySource;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Model\UtteranceOutcome;
use NaviBrain\Model\WorkItem;
use NaviBrain\Model\WorkingMemorySlot;
use RuntimeException;
use Throwable;

final class Schema
{
    public const VERSION = 14;
    private const BASELINE_VERSION = 4;

    /** @var array<int, array{name: string, statements: list<string>}> */
    private const STATIC_MIGRATIONS = [
        5 => [
            'name' => 'create_schema_migration_ledger',
            'statements' => [
                'CREATE TABLE schema_migrations (
                    version INTEGER PRIMARY KEY,
                    name TEXT NOT NULL UNIQUE,
                    checksum TEXT NOT NULL,
                    applied_at INTEGER NOT NULL
                )',
            ],
        ],
    ];

    /** @var list<class-string> */
    private const BASELINE_MODELS = [
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

    /** @var list<class-string> */
    private const MODELS = [
        ...self::BASELINE_MODELS,
        CognitiveThread::class,
        ThreadStep::class,
        MetricSnapshot::class,
        ContextCapsule::class,
        CapsuleSlot::class,
        SensorySource::class,
        Sense::class,
        SenseReading::class,
        SenseEvent::class,
        UtteranceOutcome::class,
        PerceptFrame::class,
        ForwardPrediction::class,
        WorkingMemorySlot::class,
        Procedure::class,
        ProcedureRun::class,
        ActionExecution::class,
        DecisionCycle::class,
        DecisionCandidate::class,
        OtherAgentFrameFact::class,
        OtherModelHypothesis::class,
        OtherModelPrediction::class,
        OtherModelCycle::class,
    ];

    /** @return array{version: int, tables: list<string>} */
    public function ensure(): array
    {
        $connection = Connections::getConnection();
        $tables = array_map(
            static fn (string $modelClass): string => $modelClass::$tableName,
            self::MODELS
        );
        $tables[] = 'schema_migrations';
        $migrations = $this->migrations();
        $installedVersion = $this->installedVersion();
        if ($installedVersion > self::VERSION) {
            throw new RuntimeException(sprintf(
                'Database schema version %d is newer than supported version %d.',
                $installedVersion,
                self::VERSION
            ));
        }

        if ($installedVersion === 0) {
            $this->installBaseline();
            $installedVersion = self::BASELINE_VERSION;
        } elseif ($installedVersion < self::BASELINE_VERSION) {
            throw new RuntimeException(sprintf(
                'Database schema version %d predates the explicit migration baseline; restore a version %d backup before upgrading.',
                $installedVersion,
                self::BASELINE_VERSION
            ));
        }

        for ($version = $installedVersion + 1; $version <= self::VERSION; $version++) {
            $migration = $migrations[$version] ?? null;
            if ($migration === null) {
                throw new RuntimeException(sprintf('Missing schema migration %d.', $version));
            }
            $this->applyMigration($version, $migration);
        }

        foreach ($migrations as $version => $migration) {
            if ($version <= self::VERSION) {
                $this->verifyMigration($version, $migration);
            }
        }

        return [
            'version' => self::VERSION,
            'tables' => $tables,
        ];
    }

    private function installedVersion(): int
    {
        $connection = Connections::getConnection();
        $statement = $connection->query('PRAGMA user_version');
        $version = (int) ($statement?->fetchColumn() ?: 0);
        $statement?->closeCursor();
        return $version;
    }

    private function installBaseline(): void
    {
        $connection = Connections::getConnection();
        $statement = $connection->query(
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        );
        $existingTables = (int) ($statement?->fetchColumn() ?: 0);
        $statement?->closeCursor();
        if ($existingTables !== 0) {
            throw new RuntimeException(
                'Refusing to assign a baseline version to a non-empty unversioned database.'
            );
        }

        $connection->beginTransaction();
        try {
            foreach (self::BASELINE_MODELS as $modelClass) {
                $sql = SQLiteWriter::getCreateTable($modelClass);
                foreach (preg_split('/;\s*/', $sql) ?: [] as $sqlStatement) {
                    $sqlStatement = trim($sqlStatement);
                    if ($sqlStatement !== '') {
                        $connection->exec($sqlStatement);
                    }
                }
            }
            $connection->exec('PRAGMA user_version = ' . self::BASELINE_VERSION);
            $connection->commit();
        } catch (Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
    }

    /** @return array<int, array{name: string, statements: list<string>}> */
    private function migrations(): array
    {
        $migrations = self::STATIC_MIGRATIONS;
        $migrations[6] = [
            'name' => 'create_cognitive_threads_and_steps',
            'statements' => array_merge(
                $this->createStatements(CognitiveThread::class),
                $this->createStatements(ThreadStep::class)
            ),
        ];
        $migrations[7] = [
            'name' => 'create_metric_snapshots',
            'statements' => $this->createStatements(MetricSnapshot::class),
        ];
        $migrations[8] = [
            'name' => 'create_context_capsules_and_slots',
            'statements' => array_merge(
                $this->createStatements(ContextCapsule::class),
                $this->createStatements(CapsuleSlot::class)
            ),
        ];
        $migrations[9] = [
            'name' => 'create_sensory_sources_senses_readings_events',
            'statements' => array_merge(
                $this->createStatements(SensorySource::class),
                $this->createStatements(Sense::class),
                $this->createStatements(SenseReading::class),
                $this->createStatements(SenseEvent::class)
            ),
        ];
        $migrations[10] = [
            'name' => 'create_utterance_outcomes',
            // Pinned because version 14 adds fields to the live model. Historical
            // migrations are immutable and their installed checksums must remain
            // valid while the current record shape moves forward.
            'statements' => $this->utteranceOutcomeV10Statements(),
        ];
        $migrations[11] = [
            'name' => 'create_percept_frames',
            'statements' => $this->createStatements(PerceptFrame::class),
        ];
        $migrations[12] = [
            'name' => 'create_forward_predictions',
            'statements' => $this->createStatements(ForwardPrediction::class),
        ];
        $migrations[13] = [
            'name' => 'create_working_memory_and_procedure_runtime',
            'statements' => array_merge(
                $this->createStatements(WorkingMemorySlot::class),
                $this->createStatements(Procedure::class),
                $this->createStatements(ProcedureRun::class),
                $this->createStatements(ActionExecution::class),
                $this->createStatements(DecisionCycle::class),
                $this->createStatements(DecisionCandidate::class)
            ),
        ];
        $migrations[14] = [
            'name' => 'create_other_model_and_weight_social_evidence',
            'statements' => array_merge(
                [
                    'ALTER TABLE utterance_outcomes ADD COLUMN observability_weight REAL NOT NULL DEFAULT 0.0',
                    'ALTER TABLE utterance_outcomes ADD COLUMN observability_basis TEXT NULL DEFAULT NULL',
                    "ALTER TABLE utterance_outcomes ADD COLUMN presence_state_at_utterance TEXT NOT NULL DEFAULT 'unknown'",
                ],
                $this->createStatements(OtherAgentFrameFact::class),
                $this->createStatements(OtherModelHypothesis::class),
                $this->createStatements(OtherModelPrediction::class),
                $this->createStatements(OtherModelCycle::class),
                [
                    "CREATE TRIGGER other_agent_frame_depth_insert
                     BEFORE INSERT ON other_agent_frame_facts
                     WHEN NEW.actor <> 'primary_user' OR NEW.recursion_order <> 1
                     BEGIN SELECT RAISE(ABORT, 'other-agent frame facts require actor=primary_user and recursion_order=1'); END",
                    "CREATE TRIGGER other_agent_frame_depth_update
                     BEFORE UPDATE OF actor, recursion_order ON other_agent_frame_facts
                     WHEN NEW.actor <> 'primary_user' OR NEW.recursion_order <> 1
                     BEGIN SELECT RAISE(ABORT, 'other-agent frame facts require actor=primary_user and recursion_order=1'); END",
                    "CREATE TRIGGER other_model_hypothesis_depth_insert
                     BEFORE INSERT ON other_model_hypotheses
                     WHEN NEW.actor <> 'primary_user' OR NEW.recursion_order <> 1
                     BEGIN SELECT RAISE(ABORT, 'other-model hypotheses require actor=primary_user and recursion_order=1'); END",
                    "CREATE TRIGGER other_model_hypothesis_depth_update
                     BEFORE UPDATE OF actor, recursion_order ON other_model_hypotheses
                     WHEN NEW.actor <> 'primary_user' OR NEW.recursion_order <> 1
                     BEGIN SELECT RAISE(ABORT, 'other-model hypotheses require actor=primary_user and recursion_order=1'); END",
                    "CREATE TRIGGER other_model_hypothesis_provenance_insert
                     BEFORE INSERT ON other_model_hypotheses
                     WHEN (NEW.provenance_kind IN ('direct_statement', 'worker_proposal')
                           AND (NEW.knowledge_access <> 'reported' OR NEW.representation <> 'stated'))
                       OR (NEW.provenance_kind IN ('sense_reading', 'utterance_outcome')
                           AND (NEW.knowledge_access <> 'authorized_observation' OR NEW.representation <> 'inferred'))
                       OR (NEW.provenance_kind = 'deterministic_inference'
                           AND (NEW.knowledge_access <> 'inferred' OR NEW.representation <> 'inferred'))
                     BEGIN SELECT RAISE(ABORT, 'other-model hypothesis access and representation must match code-derived provenance'); END",
                    "CREATE TRIGGER other_model_hypothesis_provenance_update
                     BEFORE UPDATE OF provenance_kind, knowledge_access, representation ON other_model_hypotheses
                     WHEN (NEW.provenance_kind IN ('direct_statement', 'worker_proposal')
                           AND (NEW.knowledge_access <> 'reported' OR NEW.representation <> 'stated'))
                       OR (NEW.provenance_kind IN ('sense_reading', 'utterance_outcome')
                           AND (NEW.knowledge_access <> 'authorized_observation' OR NEW.representation <> 'inferred'))
                       OR (NEW.provenance_kind = 'deterministic_inference'
                           AND (NEW.knowledge_access <> 'inferred' OR NEW.representation <> 'inferred'))
                     BEGIN SELECT RAISE(ABORT, 'other-model hypothesis access and representation must match code-derived provenance'); END",
                    "CREATE TRIGGER other_agent_frame_provenance_insert
                     BEFORE INSERT ON other_agent_frame_facts
                     WHEN (NEW.provenance_kind = 'user_correction'
                           AND (NEW.knowledge_access <> 'reported' OR NEW.representation <> 'stated'))
                       OR (NEW.provenance_kind IN ('sense_reading', 'utterance_outcome')
                           AND (NEW.knowledge_access <> 'authorized_observation' OR NEW.representation <> 'inferred'))
                       OR (NEW.provenance_kind = 'deterministic_inference'
                           AND (NEW.knowledge_access <> 'inferred' OR NEW.representation <> 'inferred'))
                     BEGIN SELECT RAISE(ABORT, 'other-agent frame access and representation must match code-derived provenance'); END",
                    "CREATE TRIGGER other_agent_frame_provenance_update
                     BEFORE UPDATE OF provenance_kind, knowledge_access, representation ON other_agent_frame_facts
                     WHEN (NEW.provenance_kind = 'user_correction'
                           AND (NEW.knowledge_access <> 'reported' OR NEW.representation <> 'stated'))
                       OR (NEW.provenance_kind IN ('sense_reading', 'utterance_outcome')
                           AND (NEW.knowledge_access <> 'authorized_observation' OR NEW.representation <> 'inferred'))
                       OR (NEW.provenance_kind = 'deterministic_inference'
                           AND (NEW.knowledge_access <> 'inferred' OR NEW.representation <> 'inferred'))
                     BEGIN SELECT RAISE(ABORT, 'other-agent frame access and representation must match code-derived provenance'); END",
                    "CREATE TRIGGER other_model_hypothesis_live_limit_insert
                     BEFORE INSERT ON other_model_hypotheses
                     WHEN NEW.status = 'active'
                       AND (SELECT COUNT(*) FROM other_model_hypotheses WHERE actor = 'primary_user' AND status = 'active') >= 3
                     BEGIN SELECT RAISE(ABORT, 'at most three primary-user hypotheses may be active'); END",
                    "CREATE TRIGGER other_model_hypothesis_live_limit_update
                     BEFORE UPDATE OF status ON other_model_hypotheses
                     WHEN OLD.status <> 'active' AND NEW.status = 'active'
                       AND (SELECT COUNT(*) FROM other_model_hypotheses WHERE actor = 'primary_user' AND status = 'active') >= 3
                     BEGIN SELECT RAISE(ABORT, 'at most three primary-user hypotheses may be active'); END",
                ]
            ),
        ];
        return $migrations;
    }

    /** @return list<string> */
    private function utteranceOutcomeV10Statements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `utterance_outcomes` (\n\t`id` INTEGER PRIMARY KEY AUTOINCREMENT\n\t,`created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n\t,`thread_step_id` INTEGER NOT NULL\n\t,`spoken_at` TEXT NOT NULL\n\t,`utterance` TEXT NOT NULL\n\t,`window_seconds` INTEGER NOT NULL\n\t,`response_latency_seconds` INTEGER NULL DEFAULT NULL\n\t,`responses_in_window` INTEGER NOT NULL\n\t,`response_text` TEXT NULL DEFAULT NULL\n\t,`reactions_in_window` INTEGER NOT NULL\n\t,`present_at_utterance` INTEGER NULL DEFAULT NULL\n\t,`engagement` REAL NOT NULL\n\t,`descriptor` TEXT NULL DEFAULT NULL\n\t,`descriptor_confidence` REAL NOT NULL\n\t,`descriptor_reason` TEXT NULL DEFAULT NULL\n\t,`reflected_at` TEXT NULL DEFAULT NULL\n\t,`status` TEXT NOT NULL\n)",
            'CREATE UNIQUE INDEX IF NOT EXISTS `utterance_outcomes_step` ON `utterance_outcomes` (`thread_step_id`)',
            'CREATE INDEX IF NOT EXISTS `utterance_outcomes_status` ON `utterance_outcomes` (`status`,`spoken_at`)',
            'CREATE INDEX IF NOT EXISTS `utterance_outcomes_descriptor` ON `utterance_outcomes` (`descriptor`)',
        ];
    }

    /** @param class-string $modelClass
     *  @return list<string>
     */
    private function createStatements(string $modelClass): array
    {
        $statements = [];
        foreach (preg_split('/;\s*/', SQLiteWriter::getCreateTable($modelClass)) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }
        return $statements;
    }

    /** @param array{name: string, statements: list<string>} $migration */
    private function applyMigration(int $version, array $migration): void
    {
        $connection = Connections::getConnection();
        $checksum = $this->migrationChecksum($migration);
        $connection->beginTransaction();
        try {
            foreach ($migration['statements'] as $statement) {
                $connection->exec($statement);
            }
            $insert = $connection->prepare(
                'INSERT INTO schema_migrations (version, name, checksum, applied_at)
                 VALUES (:version, :name, :checksum, :applied_at)'
            );
            $insert->execute([
                ':version' => $version,
                ':name' => $migration['name'],
                ':checksum' => $checksum,
                ':applied_at' => time(),
            ]);
            $connection->exec('PRAGMA user_version = ' . $version);
            $connection->commit();
        } catch (Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
    }

    /** @param array{name: string, statements: list<string>} $migration */
    private function verifyMigration(int $version, array $migration): void
    {
        $connection = Connections::getConnection();
        $statement = $connection->prepare(
            'SELECT name, checksum FROM schema_migrations WHERE version = :version'
        );
        $statement->execute([':version' => $version]);
        $row = $statement->fetch();
        $statement->closeCursor();
        if (!is_array($row)
            || ($row['name'] ?? null) !== $migration['name']
            || ($row['checksum'] ?? null) !== $this->migrationChecksum($migration)
        ) {
            throw new RuntimeException(sprintf(
                'Schema migration %d is missing or does not match the executable.',
                $version
            ));
        }
    }

    /** @param array{name: string, statements: list<string>} $migration */
    private function migrationChecksum(array $migration): string
    {
        return hash('sha256', $migration['name'] . "\n" . implode("\n", $migration['statements']));
    }
}
