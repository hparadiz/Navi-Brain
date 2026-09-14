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
use NaviBrain\Model\HeldValue;
use NaviBrain\Model\ModelEndpoint;
use NaviBrain\Model\Intention;
use NaviBrain\Model\MemoryConsolidationEpisode;
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
use NaviBrain\Model\SchemaMigration;
use NaviBrain\Model\SelfModelFact;
use NaviBrain\Model\Sense;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\SensorySource;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Model\UtteranceOutcome;
use NaviBrain\Model\ValueAppraisal;
use NaviBrain\Model\ValueRevision;
use NaviBrain\Model\WorkItem;
use NaviBrain\Model\WorkingMemorySlot;
use RuntimeException;
use Throwable;

final class Schema
{
    public const VERSION = 25;
    private const BASELINE_VERSION = 4;

    /** @var array<int, array{name: string, statements: list<string>}> */
    private const STATIC_MIGRATIONS = [
        // Version 23 is already installed. Keep its original statements and
        // checksum immutable; subsequent additions belong in version 24.
        23 => [
            'name' => 'track_decision_integration_ownership',
            'statements' => [
                'CREATE TABLE decision_integration_claims (
                    cycle_id INTEGER PRIMARY KEY,
                    work_id INTEGER NOT NULL,
                    owner TEXT NOT NULL,
                    claimed_at INTEGER NOT NULL,
                    settled INTEGER NOT NULL DEFAULT 0 CHECK (settled IN (0,1))
                )',
                'CREATE INDEX decision_integration_claims_unsettled
                 ON decision_integration_claims (settled,cycle_id)',
            ],
        ],
        24 => [
            'name' => 'add_planning_claims_and_maintenance_cursors',
            'statements' => [
                'CREATE TABLE decision_planning_claims (
                    cycle_id INTEGER PRIMARY KEY,
                    owner TEXT NOT NULL,
                    claimed_at INTEGER NOT NULL,
                    settled INTEGER NOT NULL DEFAULT 0 CHECK (settled IN (0,1))
                )',
                'CREATE INDEX decision_planning_claims_unsettled
                 ON decision_planning_claims (settled,cycle_id)',
                'CREATE TABLE decision_recovery_scan (
                    id INTEGER PRIMARY KEY CHECK (id = 1),
                    planning_after_id INTEGER NOT NULL DEFAULT 0 CHECK (planning_after_id >= 0),
                    integration_after_id INTEGER NOT NULL DEFAULT 0 CHECK (integration_after_id >= 0),
                    action_after_id INTEGER NOT NULL DEFAULT 0 CHECK (action_after_id >= 0),
                    unattempted_action_after_id INTEGER NOT NULL DEFAULT 0 CHECK (unattempted_action_after_id >= 0)
                )',
                'INSERT INTO decision_recovery_scan (id) VALUES (1)',
                "CREATE INDEX decision_async_claims_recovery ON decision_async_claims(action_id)
                 WHERE status IN ('started','held','waiting')",
                "CREATE TABLE work_item_lanes (
                    work_id INTEGER PRIMARY KEY,
                    lane TEXT NOT NULL CHECK (lane = 'opencode-dream')
                )",
                "CREATE INDEX work_items_active_type_id ON work_items(work_type,id)
                 WHERE status IN ('queued','leased')",
                "CREATE INDEX working_memory_maintenance_priority ON working_memory_slots(id)
                 WHERE status = 'active' OR projection_pending IS NOT NULL",
                "CREATE INDEX working_memory_maintenance_audit ON working_memory_slots(id)
                 WHERE status <> 'active' AND projection_pending IS NULL",
                'CREATE TABLE memory_consolidation_scan (
                    id INTEGER PRIMARY KEY CHECK (id = 1),
                    ascending_after_id INTEGER NOT NULL DEFAULT 0 CHECK (ascending_after_id >= 0),
                    selection_turn INTEGER NOT NULL DEFAULT 0 CHECK (selection_turn >= 0),
                    pending_after_id INTEGER NOT NULL DEFAULT 0 CHECK (pending_after_id >= 0),
                    queued_after_id INTEGER NOT NULL DEFAULT 0 CHECK (queued_after_id >= 0),
                    work_after_id INTEGER NOT NULL DEFAULT 0 CHECK (work_after_id >= 0)
                )',
                'INSERT INTO memory_consolidation_scan (id,ascending_after_id,selection_turn)
                 VALUES (1,0,0)',
                'CREATE TABLE workspace_maintenance_scan (
                    id INTEGER PRIMARY KEY CHECK (id = 1),
                    priority_after_id INTEGER NOT NULL DEFAULT 0 CHECK (priority_after_id >= 0),
                    audit_after_id INTEGER NOT NULL DEFAULT 0 CHECK (audit_after_id >= 0),
                    audit_next INTEGER NOT NULL DEFAULT 0 CHECK (audit_next IN (0,1))
                )',
                'INSERT INTO workspace_maintenance_scan (id) VALUES (1)',
            ],
        ],
        25 => [
            'name' => 'add_held_values_and_two_loop_revision',
            'statements' => [
                'CREATE TABLE held_values (
                    id INTEGER PRIMARY KEY AUTOINCREMENT
                    ,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ,value_key TEXT NOT NULL
                    ,statement TEXT NOT NULL
                    ,rationale TEXT NOT NULL
                    ,weight REAL NOT NULL
                    ,authority TEXT NOT NULL
                    ,status TEXT NOT NULL
                    ,review_interval_hours INTEGER NOT NULL
                    ,last_reviewed_at TEXT NULL DEFAULT NULL
                )',
                'CREATE UNIQUE INDEX held_values_key ON held_values (value_key)',
                'CREATE INDEX held_values_status ON held_values (status)',
                'CREATE TABLE value_appraisals (
                    id INTEGER PRIMARY KEY AUTOINCREMENT
                    ,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ,value_id INTEGER NOT NULL
                    ,event_id INTEGER NULL DEFAULT NULL
                    ,intention_id INTEGER NULL DEFAULT NULL
                    ,alignment REAL NOT NULL
                    ,evidence TEXT NOT NULL
                    ,source TEXT NOT NULL
                    ,generated_intention_id INTEGER NULL DEFAULT NULL
                )',
                'CREATE INDEX value_appraisals_value ON value_appraisals (value_id,id)',
                'CREATE INDEX value_appraisals_intention ON value_appraisals (generated_intention_id)',
                'CREATE TABLE value_revisions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT
                    ,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ,value_id INTEGER NOT NULL
                    ,previous_statement TEXT NOT NULL
                    ,previous_weight REAL NOT NULL
                    ,new_statement TEXT NOT NULL
                    ,new_weight REAL NOT NULL
                    ,basis TEXT NOT NULL
                    ,reason TEXT NOT NULL
                    ,authority TEXT NOT NULL
                    ,event_id INTEGER NULL DEFAULT NULL
                )',
                'CREATE INDEX value_revisions_value ON value_revisions (value_id,id)',
            ],
        ],
        22 => [
            'name' => 'index_explicit_cognition_control',
            'statements' => [
                "CREATE INDEX events_cognition_control_latest ON events (id DESC)
                 WHERE kind IN ('executive.control.paused', 'executive.control.resumed')",
            ],
        ],
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
        MemoryConsolidationEpisode::class,
        HeldValue::class,
        ValueAppraisal::class,
        ValueRevision::class,
    ];

    /** @return array{version: int, tables: list<string>} */
    public function ensure(): array
    {
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
                $this->procedureRunV13Statements(),
                $this->actionExecutionV13Statements(),
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
        $migrations[15] = [
            'name' => 'create_memory_consolidation_episode_ledger',
            'statements' => $this->createStatements(MemoryConsolidationEpisode::class),
        ];
        $migrations[16] = [
            'name' => 'add_exact_event_and_memory_projection_journals',
            'statements' => [
                'ALTER TABLE events ADD COLUMN dedupe_key TEXT NULL DEFAULT NULL',
                'CREATE UNIQUE INDEX events_dedupe_key ON events (dedupe_key)',
                'ALTER TABLE working_memory_slots ADD COLUMN projection_memory_id INTEGER NULL DEFAULT NULL',
                'ALTER TABLE working_memory_slots ADD COLUMN projection_pending TEXT NULL DEFAULT NULL',
                'CREATE TABLE memory_store_operations (
                    operation_key TEXT PRIMARY KEY,
                    request_hash TEXT NOT NULL,
                    requested_at INTEGER NOT NULL,
                    memory_id INTEGER NULL,
                    event_id INTEGER NULL
                )',
            ],
        ];
        $migrations[17] = [
            'name' => 'add_procedure_compile_generation_guard',
            'statements' => [
                'CREATE TABLE procedure_compile_guards (
                    shape_key TEXT PRIMARY KEY,
                    current_generation INTEGER NOT NULL DEFAULT 0,
                    pending_generation INTEGER NULL,
                    pending_hash TEXT NULL
                )',
            ],
        ];
        $migrations[18] = [
            'name' => 'index_bounded_consolidation_scheduler_queries',
            'statements' => [
                'CREATE INDEX work_items_type_status
                 ON work_items (work_type, status, id)',
                'CREATE INDEX memory_consolidation_status_work
                 ON memory_consolidation_episodes (status, work_item_id)',
            ],
        ];
        $migrations[19] = [
            'name' => 'snapshot_procedure_run_generation',
            'statements' => [
                'ALTER TABLE procedure_runs
                 ADD COLUMN procedure_memory_id INTEGER NULL DEFAULT NULL',
                'CREATE INDEX procedure_runs_generation
                 ON procedure_runs (procedure_id, procedure_memory_id, status)',
            ],
        ];
        $migrations[20] = [
            'name' => 'add_procedure_invocation_and_dispatch_claims',
            'statements' => [
                'ALTER TABLE procedure_runs
                 ADD COLUMN operation_key TEXT NULL DEFAULT NULL',
                'CREATE UNIQUE INDEX procedure_runs_operation_key
                 ON procedure_runs (operation_key)',
                'CREATE TABLE action_dispatch_claims (
                    action_trace_id INTEGER PRIMARY KEY,
                    owner TEXT NOT NULL,
                    claimed_at INTEGER NOT NULL
                )',
            ],
        ];
        $migrations[21] = [
            'name' => 'complete_exact_procedure_and_async_ledgers',
            'statements' => [
                'CREATE INDEX procedure_compile_guards_pending
                 ON procedure_compile_guards (pending_generation,shape_key)
                 WHERE pending_generation IS NOT NULL',
                'ALTER TABLE action_executions
                 ADD COLUMN procedure_memory_id INTEGER NULL DEFAULT NULL',
                'UPDATE action_executions
                 SET procedure_memory_id = (
                     SELECT procedure_memory_id FROM procedure_runs
                     WHERE procedure_runs.id = action_executions.procedure_run_id
                 )
                 WHERE procedure_run_id IS NOT NULL
                   AND procedure_id = (
                       SELECT procedure_id FROM procedure_runs
                       WHERE procedure_runs.id = action_executions.procedure_run_id
                   )
                   AND EXISTS (
                       SELECT 1 FROM procedure_runs
                       WHERE procedure_runs.id = action_executions.procedure_run_id
                         AND procedure_runs.procedure_memory_id IS NOT NULL
                   )',
                'UPDATE action_executions
                 SET step_index = -(COALESCE(step_index, 0) + 1)
                 WHERE procedure_run_id IS NOT NULL',
                'UPDATE action_executions
                 SET step_index = -step_index
                 WHERE procedure_run_id IS NOT NULL',
                'CREATE UNIQUE INDEX action_executions_run_step_unique
                 ON action_executions (procedure_run_id, step_index)
                 WHERE procedure_run_id IS NOT NULL AND step_index IS NOT NULL',
                "ALTER TABLE action_dispatch_claims
                 ADD COLUMN status TEXT NOT NULL DEFAULT 'legacy'",
                'ALTER TABLE action_dispatch_claims
                 ADD COLUMN outcome_hash TEXT NULL DEFAULT NULL',
                'ALTER TABLE action_dispatch_claims
                 ADD COLUMN outcome_data TEXT NULL DEFAULT NULL',
                "UPDATE action_dispatch_claims
                 SET owner = 'legacy', claimed_at = 0
                 WHERE status = 'legacy' AND owner = ''",
                "UPDATE action_executions
                 SET status = 'dispatching', updated_at = CURRENT_TIMESTAMP
                 WHERE status = 'pending' AND EXISTS (
                     SELECT 1 FROM action_dispatch_claims
                     WHERE action_dispatch_claims.action_trace_id = action_executions.action_trace_id
                 )",
                "INSERT OR IGNORE INTO action_dispatch_claims
                 (action_trace_id,owner,claimed_at,status,outcome_hash,outcome_data)
                 SELECT action_trace_id,'legacy',0,
                        CASE status WHEN 'waiting' THEN 'waiting' ELSE 'legacy' END,
                        NULL,NULL
                 FROM action_executions
                 WHERE status IN ('dispatching','waiting')",
                "UPDATE action_dispatch_claims
                 SET status = 'waiting'
                 WHERE action_trace_id IN (
                     SELECT action_trace_id FROM action_executions
                     WHERE status = 'waiting'
                 )",
                'CREATE TABLE procedure_invalidation_claims (
                    procedure_id INTEGER NOT NULL,
                    memory_id INTEGER NOT NULL,
                    action_id INTEGER NULL,
                    reason TEXT NOT NULL,
                    PRIMARY KEY (procedure_id,memory_id)
                )',
                'CREATE TABLE procedure_replacement_claims (
                    procedure_id INTEGER NOT NULL,
                    previous_memory_id INTEGER NOT NULL,
                    shape_key TEXT NOT NULL,
                    generation INTEGER NOT NULL,
                    pending_hash TEXT NOT NULL,
                    next_memory_id INTEGER NULL,
                    PRIMARY KEY (procedure_id,previous_memory_id),
                    UNIQUE (shape_key,generation)
                )',
                'CREATE TABLE look_request_claims (
                    event_id INTEGER PRIMARY KEY,
                    owner TEXT NOT NULL,
                    status TEXT NOT NULL,
                    outcome_hash TEXT NULL,
                    outcome_data TEXT NULL
                )',
                'CREATE INDEX look_request_claims_status_event
                 ON look_request_claims (status,event_id)',
                "INSERT INTO look_request_claims
                 (event_id,owner,status,outcome_hash,outcome_data)
                 SELECT requested.id,'','completed',NULL,NULL
                 FROM events AS requested
                 WHERE requested.kind = 'look.requested'",
                "UPDATE look_request_claims
                 SET status = 'legacy'
                 WHERE event_id IN (
                     SELECT dispatch_event_id FROM action_executions
                     WHERE status = 'waiting' AND dispatch_event_id IS NOT NULL
                 )",
                'CREATE TABLE decision_async_claims (
                    action_id INTEGER PRIMARY KEY,
                    decision_cycle_id INTEGER NOT NULL UNIQUE,
                    request_event_id INTEGER NULL UNIQUE,
                    owner TEXT NOT NULL,
                    status TEXT NOT NULL,
                    outcome_hash TEXT NULL
                )',
                'CREATE INDEX decision_async_claims_status_action
                 ON decision_async_claims (status,action_id)',
            ],
        ];
        return $migrations;
    }

    /**
     * Version 13 predates resumable generation snapshots. Keep its executable
     * checksum immutable while the live ProcedureRun model gains later fields.
     *
     * @return list<string>
     */
    private function procedureRunV13Statements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `procedure_runs` (\n\t`id` INTEGER PRIMARY KEY AUTOINCREMENT\n\t,`created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n\t,`updated_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n\t,`completed_at` TEXT NULL DEFAULT NULL\n\t,`procedure_id` INTEGER NOT NULL\n\t,`intention_id` INTEGER NOT NULL\n\t,`arguments` TEXT NOT NULL\n\t,`current_step` INTEGER NOT NULL\n\t,`results` TEXT NOT NULL\n\t,`action_trace_ids` TEXT NOT NULL\n\t,`status` TEXT NOT NULL\n\t,`error` TEXT NULL DEFAULT NULL\n)",
            'CREATE INDEX IF NOT EXISTS `procedure_runs_procedure` ON `procedure_runs` (`procedure_id`,`created_at`)',
            'CREATE INDEX IF NOT EXISTS `procedure_runs_status` ON `procedure_runs` (`status`,`updated_at`)',
            'CREATE INDEX IF NOT EXISTS `procedure_runs_intention` ON `procedure_runs` (`intention_id`)',
        ];
    }

    /** @return list<string> */
    private function actionExecutionV13Statements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `action_executions` (\n\t`id` INTEGER PRIMARY KEY AUTOINCREMENT\n\t,`created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n\t,`updated_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n\t,`completed_at` TEXT NULL DEFAULT NULL\n\t,`action_trace_id` INTEGER NOT NULL\n\t,`procedure_id` INTEGER NULL DEFAULT NULL\n\t,`procedure_run_id` INTEGER NULL DEFAULT NULL\n\t,`step_index` INTEGER NULL DEFAULT NULL\n\t,`action_kind` TEXT NOT NULL\n\t,`arguments` TEXT NOT NULL\n\t,`verifier` TEXT NOT NULL\n\t,`dispatch_event_id` INTEGER NULL DEFAULT NULL\n\t,`observed` TEXT NOT NULL\n\t,`verified` INTEGER NOT NULL\n\t,`status` TEXT NOT NULL\n)",
            'CREATE UNIQUE INDEX IF NOT EXISTS `action_executions_trace` ON `action_executions` (`action_trace_id`)',
            'CREATE INDEX IF NOT EXISTS `action_executions_run_step` ON `action_executions` (`procedure_run_id`,`step_index`)',
            'CREATE INDEX IF NOT EXISTS `action_executions_dispatch` ON `action_executions` (`dispatch_event_id`)',
            'CREATE INDEX IF NOT EXISTS `action_executions_kind_status` ON `action_executions` (`action_kind`,`status`)',
        ];
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
            $record = new SchemaMigration([
                'version' => $version,
                'name' => $migration['name'],
                'checksum' => $checksum,
                'applied_at' => time(),
            ], true, true);
            $record->save();
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
        $record = SchemaMigration::getByID($version);
        if (!$record instanceof SchemaMigration
            || $record->name !== $migration['name']
            || $record->checksum !== $this->migrationChecksum($migration)
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
