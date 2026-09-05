<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Divergence\IO\Database\Connections;
use Divergence\Models\ActiveRecord;
use InvalidArgumentException;
use PDO;
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
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\MemoryConsolidationEpisode;
use NaviBrain\Model\MetricSnapshot;
use NaviBrain\Model\ModelEndpoint;
use NaviBrain\Model\Need;
use NaviBrain\Model\Procedure;
use NaviBrain\Model\ProcedureRun;
use NaviBrain\Model\Rhythm;
use NaviBrain\Model\SelfModelFact;
use NaviBrain\Model\Sense;
use NaviBrain\Model\UtteranceOutcome;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\SensorySource;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Model\WorkItem;
use NaviBrain\Perception\PetSpeechActuator;
use NaviBrain\Perception\SensoryCortex;
use NaviBrain\Perception\SocialFeedback;
use NaviBrain\Storage\Schema;
use NaviBrain\Storage\TokenMemoryDaemon;
use NaviBrain\Support\ActivityBus;
use NaviBrain\Support\PlainText;
use RuntimeException;
use Throwable;

final class ExecutiveCore
{
    public const SELF_PRESENCE_SPEECH_WORK_TYPE = 'self_presence_speech';
    public const SELF_PRESENCE_ANSWER_WORK_TYPE = 'self_presence_answer';
    public const MEMORY_CONSOLIDATION_WORK_TYPE = 'memory_consolidation';
    private const STALE_ACTION_SECONDS = 86400;
    private const LOOK_WORK_TYPE = 'machine_look';
    private const COMPLETION_WORK_TYPE = 'intention_completion';
    /** A finished intention is a claim about the world, so it needs real confidence. */
    private const COMPLETION_CONFIDENCE_FLOOR = 0.7;
    private const CONSOLIDATION_NEW = 12;
    private const CONSOLIDATION_INTERLEAVED = 4;
    private const CONSOLIDATION_EXISTING = 5;
    /** Bound each scheduler pass even when a long offline period leaves a backlog. */
    private const CONSOLIDATION_PENDING_WINDOW = 512;
    private const CONSOLIDATION_CONFIDENCE_FLOOR = 0.55;
    private const CONSOLIDATION_MAX_ATTEMPTS = 3;
    private const CONSOLIDATION_VALIDATOR_VERSION = 7;
    /** Validator v4 was the first format whose accepted memories passed the evidence fence. */
    private const CONSOLIDATION_MEMORY_FLOOR = 4;
    /** Returning to a subject an hour later is fine; four times in a row is not. */
    private const REPEAT_WINDOW_SECONDS = 3600;
    private const REPEAT_OVERLAP = 0.6;
    /**
     * Used only until Pet has timed a real line. Ordinary speech runs about
     * two and a half words a second; the measurement replaces this as soon as
     * there is one.
     */
    private const NOMINAL_SPEAKING_RATE = 2.5;
    /**
     * How far back affect looks for things that happened. Long enough that a
     * working session reads as eventful between wakes, short enough that this
     * morning does not colour this minute — mood is what carries that.
     */
    private const AFFECT_WINDOW_SECONDS = 600;
    private const DAYDREAM_MEMORY_LIMIT = 3;
    private const DAYDREAM_COOLDOWN_SECONDS = 300;
    private const SELF_PRESENCE_NEED_KEY = 'relationship_continuity';
    private const SELF_PRESENCE_THREAD_KEY = 'self_presence';
    private const EPISTEMIC_ADVANCE_THREAD_KEY = 'epistemic_advance';
    private const EPISTEMIC_ADVANCE_WORK_TYPE = 'epistemic_advance_step';
    private const METRICS_PROTOCOL_V1 = 'cognitive-v1';
    private const MIND_STREAM_THREAD_KEY = 'mind_stream';
    private const MIND_STREAM_WORK_TYPE = 'mind_stream_thought';
    private const MIND_STREAM_HISTORY = 24;
    private const MIND_STREAM_CONTENT_OVERLAP = 0.35;
    private const MIND_STREAM_CHALLENGE_OVERLAP = 0.55;
    private const MIND_STREAM_CHALLENGE_CONTENT_OVERLAP = 0.25;
    private const MIND_STREAM_EVIDENCE_CONTENT_OVERLAP = 0.30;
    private const MIND_STREAM_EVIDENCE_CHALLENGE_OVERLAP = 0.40;
    private const LOCAL_MODEL_PROVIDER = 'local';
    private const CODEX_MODEL_PROVIDER = 'codex';
    private const MIN_WORKER_INTERVAL_SECONDS = 300;
    /** Unique to this PHP process; never reused as crash-recovery authority. */
    private static ?string $dispatchOwner = null;
    private static int $lookClaimSweepCursor = 0;
    private static int $decisionClaimSweepCursor = 0;
    /** Full-database integrity scans are safety audits, not five-second pulse work. */
    private const QUICK_CHECK_INTERVAL_SECONDS = 900;
    /** A scheduler claim with no worker dispatch should settle well inside this window. */
    private const STALE_THREAD_STEP_SECONDS = 300;
    /** Roughly one local model call: the shortest honest conversational turn. */
    private const ENGAGED_WORKER_INTERVAL_SECONDS = 45;

    public static function isSelfPresenceWorkType(string $workType): bool
    {
        return in_array($workType, [
            self::SELF_PRESENCE_SPEECH_WORK_TYPE,
            self::SELF_PRESENCE_ANSWER_WORK_TYPE,
        ], true);
    }

    private PDO $connection;
    private PetSpeechActuator $speechActuator;
    private CapsuleAssembler $capsuleAssembler;
    private WorkingMemory $workingMemory;
    private ProceduralMemory $proceduralMemory;
    private DecisionStateMachine $decisionStateMachine;
    private OtherModel $otherModel;
    private ActivityBus $activityBus;
    private ?SensoryCortex $sensoryCortex = null;
    private ?SocialFeedback $socialFeedback = null;
    /** @var list<string>|null */
    private ?array $cachedQuickCheck = null;
    private int $cachedQuickCheckAt = 0;
    /** @var list<array{kind: string, payload: array<string, mixed>}> */
    private array $pendingActivityEvents = [];

    public function __construct(
        private readonly Schema $schema = new Schema(),
        ?PetSpeechActuator $speechActuator = null
    ) {
        $this->connection = Connections::getConnection();
        $this->activityBus = new ActivityBus();
        $this->speechActuator = $speechActuator ?? new PetSpeechActuator();
        $this->capsuleAssembler = new CapsuleAssembler($this);
        $this->workingMemory = new WorkingMemory($this);
        $this->proceduralMemory = new ProceduralMemory($this);
        $this->decisionStateMachine = new DecisionStateMachine($this);
        $this->otherModel = new OtherModel($this);
    }

    public function capsuleAssembler(): CapsuleAssembler
    {
        return $this->capsuleAssembler;
    }

    public function workingMemory(): WorkingMemory
    {
        return $this->workingMemory;
    }

    public function proceduralMemory(): ProceduralMemory
    {
        return $this->proceduralMemory;
    }

    public function decisionStateMachine(): DecisionStateMachine
    {
        return $this->decisionStateMachine;
    }

    public function otherModel(): OtherModel
    {
        return $this->otherModel;
    }

    public function sensoryCortex(): SensoryCortex
    {
        return $this->sensoryCortex ??= new SensoryCortex($this);
    }

    public function socialFeedback(): SocialFeedback
    {
        return $this->socialFeedback ??= new SocialFeedback($this);
    }

    /**
     * Emit hook for collaborators that record durable events but must not own
     * transaction policy.
     *
     * @param array<string, mixed> $payload
     */
    public function emitEvent(string $kind, array $payload): array
    {
        return $this->emit($kind, $payload)->getData();
    }

    /**
     * Emit one immutable event for a durable operation identity.
     *
     * @param array<string, mixed> $payload
     */
    public function emitEventOnce(string $dedupeKey, string $kind, array $payload): array
    {
        return $this->emitOnce($dedupeKey, $kind, $payload)->getData();
    }

    /**
     * Retire SQLite authorization before touching the token generation. This
     * creates a fail-closed state that action-dispatch claims cannot cross.
     */
    public function beginProcedureInvalidation(
        int $procedureId,
        int $expectedMemoryId,
        ?int $actionId,
        string $reason
    ): bool {
        $this->requireText($reason, 'procedure invalidation reason');
        return $this->transaction(function () use (
            $procedureId,
            $expectedMemoryId,
            $actionId,
            $reason
        ): bool {
            $statement = $this->connection->prepare(
                "UPDATE procedures
                 SET status = 'retiring', invalidation_reason = :reason, updated_at = :updated_at
                 WHERE id = :id AND memory_id = :memory_id AND status = 'active'"
            );
            $statement->execute([
                'reason' => $reason,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $procedureId,
                'memory_id' => $expectedMemoryId,
            ]);
            if ($statement->rowCount() === 1) {
                $claim = $this->connection->prepare(
                    'INSERT INTO procedure_invalidation_claims
                     (procedure_id,memory_id,action_id,reason)
                     VALUES (:procedure_id,:memory_id,:action_id,:reason)'
                );
                $claim->execute([
                    'procedure_id' => $procedureId,
                    'memory_id' => $expectedMemoryId,
                    'action_id' => $actionId,
                    'reason' => $reason,
                ]);
                return true;
            }
            $check = $this->connection->prepare(
                'SELECT status,invalidation_reason FROM procedures
                 WHERE id = :id AND memory_id = :memory_id'
            );
            $check->execute(['id' => $procedureId, 'memory_id' => $expectedMemoryId]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            $check->closeCursor();
            if (is_array($row) && in_array($row['status'], ['retiring', 'invalidated'], true)) {
                $claim = $this->procedureInvalidationClaim($procedureId, $expectedMemoryId);
                if ($claim !== null
                    && $claim['action_id'] === $actionId
                    && $claim['reason'] === $reason
                    && (string) $row['invalidation_reason'] === $reason
                ) {
                    return false;
                }
            }
            throw new RuntimeException('Procedure invalidation no longer owns the expected generation.');
        });
    }

    /** @return array{action_id: int|null, reason: string}|null */
    public function procedureInvalidationClaim(int $procedureId, int $memoryId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT action_id,reason FROM procedure_invalidation_claims
             WHERE procedure_id = :procedure_id AND memory_id = :memory_id'
        );
        $statement->execute(['procedure_id' => $procedureId, 'memory_id' => $memoryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (!is_array($row)) {
            return null;
        }
        return [
            'action_id' => $row['action_id'] === null ? null : (int) $row['action_id'],
            'reason' => (string) $row['reason'],
        ];
    }

    /**
     * Remove an old generation's SQLite authority before its token record is
     * expired. The durable claim lets the same compilation replay across
     * either cross-store crash boundary without reopening the old generation.
     */
    public function beginProcedureReplacement(
        int $procedureId,
        int $previousMemoryId,
        string $shapeKey,
        int $generation,
        string $pendingHash
    ): bool {
        if ($procedureId < 1 || $previousMemoryId < 1 || $generation < 1) {
            throw new InvalidArgumentException('Procedure replacement identity is invalid.');
        }
        $this->requireText($shapeKey, 'procedure replacement key');
        $this->requireText($pendingHash, 'procedure replacement hash');

        return $this->transaction(function () use (
            $procedureId,
            $previousMemoryId,
            $shapeKey,
            $generation,
            $pendingHash
        ): bool {
            $retire = $this->connection->prepare(
                "UPDATE procedures
                 SET status = 'retiring', updated_at = :updated_at
                 WHERE id = :id AND memory_id = :memory_id
                   AND status IN ('active','invalidated')"
            );
            $retire->execute([
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $procedureId,
                'memory_id' => $previousMemoryId,
            ]);
            if ($retire->rowCount() === 1) {
                $claim = $this->connection->prepare(
                    'INSERT INTO procedure_replacement_claims
                     (procedure_id,previous_memory_id,shape_key,generation,pending_hash,next_memory_id)
                     VALUES (:procedure_id,:previous_memory_id,:shape_key,:generation,:pending_hash,NULL)'
                );
                $claim->execute([
                    'procedure_id' => $procedureId,
                    'previous_memory_id' => $previousMemoryId,
                    'shape_key' => $shapeKey,
                    'generation' => $generation,
                    'pending_hash' => $pendingHash,
                ]);
                return true;
            }

            $claim = $this->procedureReplacementClaim($procedureId, $previousMemoryId);
            if ($claim !== null
                && $claim['shape_key'] === $shapeKey
                && $claim['generation'] === $generation
                && hash_equals($claim['pending_hash'], $pendingHash)
            ) {
                $procedure = Procedure::getByID($procedureId);
                if ($procedure instanceof Procedure
                    && ((string) $procedure->status === 'retiring'
                        || ($claim['next_memory_id'] !== null
                            && (string) $procedure->status === 'active'
                            && (int) $procedure->memory_id === $claim['next_memory_id']))
                ) {
                    return false;
                }
            }
            throw new RuntimeException('Procedure replacement no longer owns the expected generation.');
        });
    }

    /**
     * @return array{shape_key: string, generation: int, pending_hash: string, next_memory_id: int|null}|null
     */
    public function procedureReplacementClaim(int $procedureId, int $previousMemoryId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT shape_key,generation,pending_hash,next_memory_id
             FROM procedure_replacement_claims
             WHERE procedure_id = :procedure_id AND previous_memory_id = :previous_memory_id'
        );
        $statement->execute([
            'procedure_id' => $procedureId,
            'previous_memory_id' => $previousMemoryId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (!is_array($row)) {
            return null;
        }
        return [
            'shape_key' => (string) $row['shape_key'],
            'generation' => (int) $row['generation'],
            'pending_hash' => (string) $row['pending_hash'],
            'next_memory_id' => $row['next_memory_id'] === null ? null : (int) $row['next_memory_id'],
        ];
    }

    /**
     * Publish the exact successor generation and restore SQLite authority in
     * one transaction after token replacement has durably succeeded.
     *
     * @param array<string, mixed> $fields
     * @return array{procedure: array<string, mixed>, applied: bool}
     */
    public function finalizeProcedureReplacement(
        int $procedureId,
        int $previousMemoryId,
        int $nextMemoryId,
        string $shapeKey,
        int $generation,
        string $pendingHash,
        array $fields
    ): array {
        if ($nextMemoryId < 1) {
            throw new InvalidArgumentException('Replacement memory identity is invalid.');
        }
        return $this->transaction(function () use (
            $procedureId,
            $previousMemoryId,
            $nextMemoryId,
            $shapeKey,
            $generation,
            $pendingHash,
            $fields
        ): array {
            $claim = $this->procedureReplacementClaim($procedureId, $previousMemoryId);
            if ($claim === null
                || $claim['shape_key'] !== $shapeKey
                || $claim['generation'] !== $generation
                || !hash_equals($claim['pending_hash'], $pendingHash)
                || ($claim['next_memory_id'] !== null && $claim['next_memory_id'] !== $nextMemoryId)
            ) {
                throw new RuntimeException('Procedure replacement claim does not match its finalization.');
            }
            if ($claim['next_memory_id'] === null) {
                $bind = $this->connection->prepare(
                    'UPDATE procedure_replacement_claims
                     SET next_memory_id = :next_memory_id
                     WHERE procedure_id = :procedure_id
                       AND previous_memory_id = :previous_memory_id
                       AND next_memory_id IS NULL'
                );
                $bind->execute([
                    'next_memory_id' => $nextMemoryId,
                    'procedure_id' => $procedureId,
                    'previous_memory_id' => $previousMemoryId,
                ]);
                if ($bind->rowCount() !== 1) {
                    throw new RuntimeException('Procedure replacement lost its successor binding.');
                }
            }

            $procedure = Procedure::getByID($procedureId);
            if (!$procedure instanceof Procedure) {
                throw new RuntimeException('Procedure replacement target disappeared.');
            }
            $applied = false;
            if ((string) $procedure->status === 'retiring'
                && (int) $procedure->memory_id === $previousMemoryId
            ) {
                $fields['memory_id'] = $nextMemoryId;
                $fields['status'] = 'active';
                $procedure->setFields($fields);
                $procedure->save();
                $applied = true;
            } elseif ((string) $procedure->status !== 'active'
                || (int) $procedure->memory_id !== $nextMemoryId
            ) {
                throw new RuntimeException('Procedure replacement conflicts with durable procedure state.');
            }

            $procedure = Procedure::getByID($procedureId);
            if (!$procedure instanceof Procedure
                || (string) $procedure->status !== 'active'
                || (int) $procedure->memory_id !== $nextMemoryId
            ) {
                throw new RuntimeException('Procedure replacement did not publish its exact successor.');
            }
            return ['procedure' => $procedure->getData(), 'applied' => $applied];
        });
    }

    /**
     * Finalize a runtime procedure invalidation only if the failed generation
     * still owns the procedure row. The caller first moves SQLite authorization
     * to retiring, then retires the token generation; this transaction keeps
     * the final control row and its immutable event inseparable.
     */
    public function finalizeProcedureInvalidation(
        int $procedureId,
        int $expectedMemoryId,
        ?int $actionId,
        string $procedureKey,
        string $reason
    ): bool {
        if ($procedureId < 1 || $expectedMemoryId < 1) {
            throw new InvalidArgumentException('Procedure invalidation identity is invalid.');
        }
        $this->requireText($procedureKey, 'procedure invalidation key');
        $this->requireText($reason, 'procedure invalidation reason');

        return $this->transaction(function () use (
            $procedureId,
            $expectedMemoryId,
            $actionId,
            $procedureKey,
            $reason
        ): bool {
            $claim = $this->procedureInvalidationClaim($procedureId, $expectedMemoryId);
            if ($claim === null
                || $claim['action_id'] !== $actionId
                || $claim['reason'] !== $reason
            ) {
                throw new RuntimeException('Procedure invalidation claim does not match its finalization.');
            }
            $now = date('Y-m-d H:i:s');
            $statement = $this->connection->prepare(
                "UPDATE procedures
                 SET status = 'invalidated', failure_count = failure_count + 1,
                     invalidated_at = :invalidated_at,
                     invalidation_reason = :reason, updated_at = :updated_at
                 WHERE id = :id AND memory_id = :memory_id AND status = 'retiring'"
            );
            $statement->execute([
                'invalidated_at' => $now,
                'reason' => $reason,
                'updated_at' => $now,
                'id' => $procedureId,
                'memory_id' => $expectedMemoryId,
            ]);
            if ($statement->rowCount() !== 1) {
                return false;
            }

            $this->emitOnce(
                'procedure.invalidated:runtime:' . $procedureId . ':' .
                    $expectedMemoryId . ':' . hash('sha256', $reason),
                'procedure.invalidated',
                [
                    'procedure_id' => $procedureId,
                    'memory_id' => $expectedMemoryId,
                    'action_id' => $actionId,
                    'procedure_key' => $procedureKey,
                    'reason' => $reason,
                ]
            );
            return true;
        });
    }

    /**
     * Create or resume one caller-identified procedure invocation. The run row
     * and started event share a SQLite transaction, so response loss cannot
     * mint a second run when the caller retries the same operation key.
     *
     * @param array<string, mixed> $arguments
     * @return array{run: array<string, mixed>, event: array<string, mixed>, created: bool}
     */
    public function startProcedureRun(
        int $procedureId,
        int $procedureMemoryId,
        int $intentionId,
        array $arguments,
        int $stepCount,
        string $operationKey
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $operationKey) !== 1) {
            throw new InvalidArgumentException('Procedure run operation key must be a SHA-256 value.');
        }
        $payload = [
            'procedure_id' => $procedureId,
            'procedure_memory_id' => $procedureMemoryId,
            'intention_id' => $intentionId,
            'step_count' => $stepCount,
        ];
        $operation = function () use (
            $procedureId,
            $procedureMemoryId,
            $intentionId,
            $arguments,
            $operationKey,
            $payload
        ): array {
            $existing = ProcedureRun::getByField('operation_key', $operationKey);
            if ($existing instanceof ProcedureRun) {
                if ((int) $existing->procedure_id !== $procedureId
                    || (int) ($existing->procedure_memory_id ?? 0) !== $procedureMemoryId
                    || (int) $existing->intention_id !== $intentionId
                    || (array) $existing->arguments !== $arguments
                ) {
                    throw new RuntimeException('Procedure run operation key belongs to a different invocation.');
                }
                $event = $this->emitOnce(
                    'procedure.run.started:' . $operationKey,
                    'procedure.run.started',
                    $payload + ['procedure_run_id' => (int) $existing->id]
                );
                return [
                    'run' => $existing->getData(),
                    'event' => $event->getData(),
                    'created' => false,
                ];
            }

            $authorized = $this->connection->prepare(
                "SELECT COUNT(*) FROM procedures
                 WHERE id = :id AND memory_id = :memory_id AND status = 'active'"
            );
            $authorized->execute(['id' => $procedureId, 'memory_id' => $procedureMemoryId]);
            $allowed = (int) $authorized->fetchColumn() === 1;
            $authorized->closeCursor();
            if (!$allowed) {
                throw new RuntimeException('Procedure generation changed before its run could start.');
            }

            /** @var ProcedureRun $run */
            $run = $this->insert(ProcedureRun::class, [
                'procedure_id' => $procedureId,
                'procedure_memory_id' => $procedureMemoryId,
                'operation_key' => $operationKey,
                'intention_id' => $intentionId,
                'arguments' => $arguments,
                'current_step' => 0,
                'results' => [],
                'action_trace_ids' => [],
                'status' => 'running',
                'updated_at' => time(),
            ]);
            $event = $this->emitOnce(
                'procedure.run.started:' . $operationKey,
                'procedure.run.started',
                $payload + ['procedure_run_id' => (int) $run->id]
            );
            return [
                'run' => $run->getData(),
                'event' => $event->getData(),
                'created' => true,
            ];
        };

        try {
            return $this->transaction($operation);
        } catch (Throwable $throwable) {
            $existing = ProcedureRun::getByField('operation_key', $operationKey);
            if (!$existing instanceof ProcedureRun
                || (int) $existing->procedure_id !== $procedureId
                || (int) ($existing->procedure_memory_id ?? 0) !== $procedureMemoryId
                || (int) $existing->intention_id !== $intentionId
                || (array) $existing->arguments !== $arguments
            ) {
                throw $throwable;
            }
            $event = $this->emitEventOnce(
                'procedure.run.started:' . $operationKey,
                'procedure.run.started',
                $payload + ['procedure_run_id' => (int) $existing->id]
            );
            return ['run' => $existing->getData(), 'event' => $event, 'created' => false];
        }
    }

    /**
     * Compare-and-set one resumable procedure run into a terminal state and
     * emit its immutable completion event in the same SQLite transaction.
     * Replays return the exact committed terminal operation; a competing,
     * different terminal result is rejected instead of being overwritten.
     *
     * @param list<array<string, mixed>> $results
     * @param list<int> $actionTraceIds
     * @return array{run: array<string, mixed>, event: array<string, mixed>, applied: bool}
     */
    public function finalizeProcedureRun(
        int $runId,
        int $procedureId,
        ?int $expectedMemoryId,
        string $status,
        array $results,
        array $actionTraceIds,
        ?string $reason = null
    ): array {
        if ($runId < 1 || $procedureId < 1 || ($expectedMemoryId !== null && $expectedMemoryId < 1)) {
            throw new InvalidArgumentException('Procedure run terminal identity is invalid.');
        }
        $this->requireChoice($status, ['succeeded', 'failed', 'cancelled'], 'procedure run status');
        if ($status !== 'succeeded') {
            $this->requireText((string) $reason, 'procedure run terminal reason');
        } elseif ($reason !== null) {
            throw new InvalidArgumentException('A successful procedure run cannot have an error reason.');
        }
        $actionTraceIds = array_values(array_unique(array_map('intval', $actionTraceIds)));

        return $this->transaction(function () use (
            $runId,
            $procedureId,
            $expectedMemoryId,
            $status,
            $results,
            $actionTraceIds,
            $reason
        ): array {
            $now = date('Y-m-d H:i:s');
            $generationPredicate = $expectedMemoryId === null
                ? 'procedure_memory_id IS NULL'
                : 'procedure_memory_id = :procedure_memory_id';
            $authorizationPredicate = $status === 'succeeded'
                ? "AND EXISTS (
                       SELECT 1 FROM procedures
                       WHERE procedures.id = procedure_runs.procedure_id
                         AND procedures.memory_id = procedure_runs.procedure_memory_id
                         AND procedures.status = 'active'
                   )"
                : '';
            $statement = $this->connection->prepare(
                "UPDATE procedure_runs
                 SET status = :status, results = :results,
                     action_trace_ids = :action_trace_ids, error = :error,
                     completed_at = :completed_at, updated_at = :updated_at
                 WHERE id = :id AND procedure_id = :procedure_id
                   AND {$generationPredicate}
                   {$authorizationPredicate}
                   AND status IN ('running', 'waiting')"
            );
            $parameters = [
                'status' => $status,
                'results' => serialize($results),
                'action_trace_ids' => serialize($actionTraceIds),
                'error' => $reason,
                'completed_at' => $now,
                'updated_at' => $now,
                'id' => $runId,
                'procedure_id' => $procedureId,
            ];
            if ($expectedMemoryId !== null) {
                $parameters['procedure_memory_id'] = $expectedMemoryId;
            }
            $statement->execute($parameters);
            $applied = $statement->rowCount() === 1;

            $run = ProcedureRun::getByID($runId);
            if (!$run instanceof ProcedureRun
                || (int) $run->procedure_id !== $procedureId
                || ($run->procedure_memory_id === null
                    ? $expectedMemoryId !== null
                    : (int) $run->procedure_memory_id !== $expectedMemoryId)
                || (string) $run->status !== $status
                || (array) $run->results !== $results
                || array_values(array_map('intval', (array) $run->action_trace_ids)) !== $actionTraceIds
                || ($status === 'succeeded'
                    ? $run->error !== null
                    : (string) $run->error !== (string) $reason)
            ) {
                throw new RuntimeException('Procedure run already has a different terminal result.');
            }

            if ($applied && $status === 'succeeded' && $expectedMemoryId !== null) {
                $increment = $this->connection->prepare(
                    "UPDATE procedures
                     SET execution_count = execution_count + 1,
                         last_executed_at = :executed_at, updated_at = :updated_at
                     WHERE id = :id AND memory_id = :memory_id AND status = 'active'"
                );
                $increment->execute([
                    'executed_at' => $now,
                    'updated_at' => $now,
                    'id' => $procedureId,
                    'memory_id' => $expectedMemoryId,
                ]);
            }

            $payload = [
                'procedure_id' => $procedureId,
                'procedure_run_id' => $runId,
                'procedure_memory_id' => $expectedMemoryId,
                'status' => $status,
            ];
            if ($status === 'succeeded') {
                $payload['steps_completed'] = count($results);
                $payload['action_trace_ids'] = $actionTraceIds;
            } else {
                $payload['reason'] = $reason;
            }
            $event = $this->emitOnce(
                'procedure.run.finished:' . $runId,
                'procedure.run.finished',
                $payload
            );
            return [
                'run' => $run->getData(),
                'event' => $event->getData(),
                'applied' => $applied,
            ];
        });
    }

    /**
     * Claim one adapter dispatch for this process epoch. A claim left by a
     * dead process is never stolen for re-execution: the new process receives
     * a recovery claim and records an honest indeterminate failure instead.
     *
     * @return array{execution: array<string, mixed>, claimed: bool, recover: bool}
     */
    public function claimActionDispatch(int $actionId): array
    {
        if ($actionId < 1) {
            throw new InvalidArgumentException('Action dispatch identity is invalid.');
        }
        return $this->transaction(function () use ($actionId): array {
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof ActionExecution) {
                throw new RuntimeException('Action dispatch has no execution record.');
            }
            $owner = $this->dispatchOwner();
            if ($execution->status === 'pending') {
                $claim = $this->connection->prepare(
                    "INSERT INTO action_dispatch_claims
                     (action_trace_id,owner,claimed_at,status,outcome_hash,outcome_data)
                     VALUES (:action_id,:owner,:claimed_at,'claimed',NULL,NULL)"
                );
                $claim->execute([
                    'action_id' => $actionId,
                    'owner' => $owner,
                    'claimed_at' => time(),
                ]);
                $statement = $this->connection->prepare(
                    "UPDATE action_executions
                     SET status = 'dispatching', updated_at = :updated_at
                     WHERE action_trace_id = :action_id AND status = 'pending'
                       AND (procedure_id IS NULL OR EXISTS (
                           SELECT 1 FROM procedures
                           WHERE procedures.id = action_executions.procedure_id
                             AND procedures.memory_id = action_executions.procedure_memory_id
                             AND procedures.status = 'active'
                       ))
                       AND (procedure_run_id IS NULL OR EXISTS (
                           SELECT 1
                           FROM procedure_runs
                           INNER JOIN procedures
                             ON procedures.id = procedure_runs.procedure_id
                            AND procedures.memory_id = procedure_runs.procedure_memory_id
                            AND procedures.status = 'active'
                           WHERE procedure_runs.id = action_executions.procedure_run_id
                             AND procedure_runs.status = 'running'
                       ))"
                );
                $statement->execute([
                    'updated_at' => date('Y-m-d H:i:s'),
                    'action_id' => $actionId,
                ]);
                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('Unable to claim action dispatch.');
                }
                $execution = ActionExecution::getByField('action_trace_id', $actionId);
                if (!$execution instanceof ActionExecution) {
                    throw new RuntimeException('Claimed action dispatch disappeared.');
                }
                return ['execution' => $execution->getData(), 'claimed' => true, 'recover' => false];
            }
            if ($execution->status === 'dispatching') {
                $claim = $this->connection->prepare(
                    'SELECT owner,status,outcome_hash,outcome_data
                     FROM action_dispatch_claims WHERE action_trace_id = :action_id'
                );
                $claim->execute(['action_id' => $actionId]);
                $claimRow = $claim->fetch(PDO::FETCH_ASSOC);
                $claim->closeCursor();
                $previousOwner = is_array($claimRow) ? $claimRow['owner'] ?? null : null;
                if (!is_string($previousOwner) || $previousOwner === '') {
                    throw new RuntimeException('Dispatching action is missing its process claim.');
                }
                $previousStatus = (string) ($claimRow['status'] ?? '');
                if (!in_array($previousStatus, ['legacy', 'claimed'], true)) {
                    throw new RuntimeException('Dispatching action has an invalid claim state.');
                }
                if ($previousStatus === 'claimed'
                    && !hash_equals($previousOwner, $owner)
                    && $this->dispatchOwnerIsLive($previousOwner)
                ) {
                    return ['execution' => $execution->getData(), 'claimed' => false, 'recover' => false];
                }
                $observed = [
                    'error' => $previousStatus === 'legacy'
                        ? 'Adapter dispatch predates the durable owner ledger; its outcome is indeterminate.'
                        : (hash_equals($previousOwner, $owner)
                            ? 'Adapter dispatch returned without durably staging its outcome.'
                            : 'Adapter dispatch outcome became indeterminate after its owning process exited.'),
                ];
                $outcomeHash = hash('sha256', serialize([
                    'observed' => $observed,
                    'verified' => false,
                    'status' => 'failed',
                ]));
                $recover = $this->connection->prepare(
                    'UPDATE action_dispatch_claims
                     SET owner = :owner, claimed_at = :claimed_at, status = :status,
                         outcome_hash = :outcome_hash, outcome_data = :outcome_data
                     WHERE action_trace_id = :action_id AND owner = :previous_owner
                       AND status = :previous_status'
                );
                $recover->execute([
                    'owner' => $owner,
                    'claimed_at' => time(),
                    'status' => 'completed',
                    'outcome_hash' => $outcomeHash,
                    'outcome_data' => serialize($observed),
                    'action_id' => $actionId,
                    'previous_owner' => $previousOwner,
                    'previous_status' => $previousStatus,
                ]);
                if ($recover->rowCount() !== 1) {
                    throw new RuntimeException('Unable to claim indeterminate dispatch recovery.');
                }
                $now = date('Y-m-d H:i:s');
                $terminal = $this->connection->prepare(
                    "UPDATE action_executions
                     SET observed = :observed, verified = 0, status = 'failed',
                         completed_at = :completed_at, updated_at = :updated_at
                     WHERE action_trace_id = :action_id AND status = 'dispatching'"
                );
                $terminal->execute([
                    'observed' => serialize($observed),
                    'completed_at' => $now,
                    'updated_at' => $now,
                    'action_id' => $actionId,
                ]);
                if ($terminal->rowCount() !== 1) {
                    throw new RuntimeException('Unable to close indeterminate action dispatch atomically.');
                }
                $execution = ActionExecution::getByField('action_trace_id', $actionId);
                if (!$execution instanceof ActionExecution) {
                    throw new RuntimeException('Recovered action dispatch disappeared.');
                }
                return ['execution' => $execution->getData(), 'claimed' => false, 'recover' => true];
            }
            return [
                'execution' => $execution->getData(),
                'claimed' => false,
                'recover' => false,
            ];
        });
    }

    /**
     * Compare-and-set one machine-readable adapter outcome. Callback races and
     * crash replays must either match this exact durable result or fail.
     *
     * @param array<string, mixed> $observed
     * @return array{execution: array<string, mixed>, applied: bool}
     */
    public function finalizeActionExecution(
        int $actionId,
        array $observed,
        bool $verified,
        string $status
    ): array {
        $this->requireChoice($status, ['succeeded', 'failed', 'cancelled'], 'action execution status');
        $outcomeHash = hash('sha256', serialize([
            'observed' => $observed,
            'verified' => $verified,
            'status' => $status,
        ]));
        return $this->transaction(function () use (
            $actionId,
            $observed,
            $verified,
            $status,
            $outcomeHash
        ): array {
            $before = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$before instanceof ActionExecution) {
                throw new RuntimeException('Action execution disappeared before outcome finalization.');
            }
            if (in_array($before->status, ['succeeded', 'failed', 'cancelled'], true)) {
                if ((array) $before->observed !== $observed
                    || (int) $before->verified !== ($verified ? 1 : 0)
                    || (string) $before->status !== $status
                ) {
                    throw new RuntimeException('Action execution already has a different durable outcome.');
                }
                $claim = $this->connection->prepare(
                    'SELECT status,outcome_hash FROM action_dispatch_claims
                     WHERE action_trace_id = :action_id'
                );
                $claim->execute(['action_id' => $actionId]);
                $claimRow = $claim->fetch(PDO::FETCH_ASSOC);
                $claim->closeCursor();
                if (is_array($claimRow) && (string) $claimRow['status'] === 'legacy') {
                    $upgrade = $this->connection->prepare(
                        "UPDATE action_dispatch_claims
                         SET status = 'completed', outcome_hash = :outcome_hash,
                             outcome_data = :outcome_data
                         WHERE action_trace_id = :action_id AND status = 'legacy'"
                    );
                    $upgrade->execute([
                        'outcome_hash' => $outcomeHash,
                        'outcome_data' => serialize($observed),
                        'action_id' => $actionId,
                    ]);
                    if ($upgrade->rowCount() !== 1) {
                        throw new RuntimeException('Unable to upgrade legacy dispatch receipt.');
                    }
                    $claimRow = ['status' => 'completed', 'outcome_hash' => $outcomeHash];
                }
                if (is_array($claimRow)
                    && ((string) $claimRow['status'] !== 'completed'
                        || !is_string($claimRow['outcome_hash'])
                        || !hash_equals($claimRow['outcome_hash'], $outcomeHash))
                ) {
                    throw new RuntimeException('Terminal action has a different dispatch receipt.');
                }
                return ['execution' => $before->getData(), 'applied' => false];
            }
            if (!in_array($before->status, ['dispatching', 'waiting'], true)) {
                throw new RuntimeException('Action execution is not ready for an outcome.');
            }
            $claim = $this->connection->prepare(
                'SELECT owner,status FROM action_dispatch_claims WHERE action_trace_id = :action_id'
            );
            $claim->execute(['action_id' => $actionId]);
            $claimRow = $claim->fetch(PDO::FETCH_ASSOC);
            $claim->closeCursor();
            $expectedClaimStatus = (string) $before->status === 'waiting' ? 'waiting' : 'claimed';
            if (!is_array($claimRow)
                || (string) $claimRow['status'] !== $expectedClaimStatus
                || ((string) $before->status === 'dispatching'
                    && !hash_equals((string) $claimRow['owner'], $this->dispatchOwner()))
            ) {
                throw new RuntimeException('Action outcome does not own its dispatch claim.');
            }
            $stage = $this->connection->prepare(
                "UPDATE action_dispatch_claims
                 SET status = 'completing', outcome_hash = :outcome_hash, outcome_data = :outcome_data
                 WHERE action_trace_id = :action_id AND status = :expected_status"
            );
            $stage->execute([
                'outcome_hash' => $outcomeHash,
                'outcome_data' => serialize($observed),
                'action_id' => $actionId,
                'expected_status' => $expectedClaimStatus,
            ]);
            if ($stage->rowCount() !== 1) {
                throw new RuntimeException('Action outcome lost its durable staging claim.');
            }
            $statement = $this->connection->prepare(
                "UPDATE action_executions
                 SET observed = :observed, verified = :verified, status = :status,
                     completed_at = :completed_at, updated_at = :updated_at
                 WHERE action_trace_id = :action_id
                   AND status IN ('dispatching', 'waiting')"
            );
            $now = date('Y-m-d H:i:s');
            $statement->execute([
                'observed' => serialize($observed),
                'verified' => $verified ? 1 : 0,
                'status' => $status,
                'completed_at' => $now,
                'updated_at' => $now,
                'action_id' => $actionId,
            ]);
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof ActionExecution
                || (array) $execution->observed !== $observed
                || (int) $execution->verified !== ($verified ? 1 : 0)
                || (string) $execution->status !== $status
            ) {
                throw new RuntimeException('Action execution already has a different durable outcome.');
            }
            $complete = $this->connection->prepare(
                "UPDATE action_dispatch_claims SET status = 'completed'
                 WHERE action_trace_id = :action_id AND status = 'completing'
                   AND outcome_hash = :outcome_hash"
            );
            $complete->execute(['action_id' => $actionId, 'outcome_hash' => $outcomeHash]);
            if ($complete->rowCount() !== 1) {
                throw new RuntimeException('Action outcome lost its completion receipt.');
            }
            return [
                'execution' => $execution->getData(),
                'applied' => $statement->rowCount() === 1,
            ];
        });
    }

    /**
     * Fail an action whose authorization disappeared before any process
     * claimed dispatch. This CAS can never overwrite a live dispatcher.
     *
     * @param array<string, mixed> $observed
     * @return array<string, mixed>
     */
    public function rejectPendingActionExecution(int $actionId, array $observed): array
    {
        return $this->transaction(function () use ($actionId, $observed): array {
            $now = date('Y-m-d H:i:s');
            $statement = $this->connection->prepare(
                "UPDATE action_executions
                 SET observed = :observed, verified = 0, status = 'failed',
                     completed_at = :completed_at, updated_at = :updated_at
                 WHERE action_trace_id = :action_id AND status = 'pending'"
            );
            $statement->execute([
                'observed' => serialize($observed),
                'completed_at' => $now,
                'updated_at' => $now,
                'action_id' => $actionId,
            ]);
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof ActionExecution
                || (array) $execution->observed !== $observed
                || (int) $execution->verified !== 0
                || (string) $execution->status !== 'failed'
            ) {
                throw new RuntimeException('Pending action authorization denial lost its dispatch race.');
            }
            return $execution->getData();
        });
    }

    /** @return array<string, mixed> */
    public function markActionExecutionWaiting(int $actionId, int $dispatchEventId): array
    {
        if ($actionId < 1 || $dispatchEventId < 1) {
            throw new InvalidArgumentException('Waiting action dispatch identity is invalid.');
        }
        return $this->transaction(function () use ($actionId, $dispatchEventId): array {
            $statement = $this->connection->prepare(
                "UPDATE action_executions
                 SET dispatch_event_id = :dispatch_event_id, status = 'waiting', updated_at = :updated_at
                 WHERE action_trace_id = :action_id AND status = 'dispatching'"
            );
            $statement->execute([
                'dispatch_event_id' => $dispatchEventId,
                'updated_at' => date('Y-m-d H:i:s'),
                'action_id' => $actionId,
            ]);
            if ($statement->rowCount() === 1) {
                $wait = $this->connection->prepare(
                    "UPDATE action_dispatch_claims SET status = 'waiting'
                     WHERE action_trace_id = :action_id AND owner = :owner AND status = 'claimed'"
                );
                $wait->execute(['action_id' => $actionId, 'owner' => $this->dispatchOwner()]);
                if ($wait->rowCount() !== 1) {
                    throw new RuntimeException('Waiting action lost its dispatch claim.');
                }
            }
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof ActionExecution
                || (int) ($execution->dispatch_event_id ?? 0) !== $dispatchEventId
                || (string) $execution->status !== 'waiting'
            ) {
                throw new RuntimeException('Waiting action dispatch conflicts with durable execution state.');
            }
            $claim = $this->connection->prepare(
                'SELECT status FROM action_dispatch_claims WHERE action_trace_id = :action_id'
            );
            $claim->execute(['action_id' => $actionId]);
            $claimStatus = $claim->fetchColumn();
            $claim->closeCursor();
            if ($claimStatus !== 'waiting') {
                throw new RuntimeException('Waiting action has no durable dispatch receipt.');
            }
            return $execution->getData();
        });
    }

    /**
     * Persist one completed asynchronous result exactly once before resuming
     * its next step. Replays validate the already-committed result and may
     * safely re-enter advanceRun; per-step dispatch identities deduplicate it.
     *
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $completedResult
     * @return array{run: array<string, mixed>, applied: bool}
     */
    public function resumeProcedureRunAfterAsync(
        int $runId,
        int $procedureId,
        ?int $expectedMemoryId,
        int $stepIndex,
        array $results,
        array $completedResult
    ): array {
        if ($runId < 1 || $procedureId < 1 || $stepIndex < 0) {
            throw new InvalidArgumentException('Asynchronous procedure result identity is invalid.');
        }
        return $this->transaction(function () use (
            $runId,
            $procedureId,
            $expectedMemoryId,
            $stepIndex,
            $results,
            $completedResult
        ): array {
            $generationPredicate = $expectedMemoryId === null
                ? 'procedure_memory_id IS NULL'
                : 'procedure_memory_id = :procedure_memory_id';
            $statement = $this->connection->prepare(
                "UPDATE procedure_runs
                 SET current_step = :next_step, results = :results,
                     status = 'running', updated_at = :updated_at
                 WHERE id = :id AND procedure_id = :procedure_id
                   AND {$generationPredicate}
                   AND status = 'waiting' AND current_step = :step_index"
            );
            $parameters = [
                'next_step' => $stepIndex + 1,
                'results' => serialize($results),
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $runId,
                'procedure_id' => $procedureId,
                'step_index' => $stepIndex,
            ];
            if ($expectedMemoryId !== null) {
                $parameters['procedure_memory_id'] = $expectedMemoryId;
            }
            $statement->execute($parameters);

            $run = ProcedureRun::getByID($runId);
            $matchingResult = null;
            if ($run instanceof ProcedureRun) {
                foreach ((array) $run->results as $candidate) {
                    if (is_array($candidate)
                        && (int) ($candidate['step'] ?? -1) === $stepIndex
                        && (int) ($candidate['action_id'] ?? 0) === (int) ($completedResult['action_id'] ?? 0)
                    ) {
                        $matchingResult = $candidate;
                        break;
                    }
                }
            }
            if (!$run instanceof ProcedureRun
                || (int) $run->procedure_id !== $procedureId
                || ($run->procedure_memory_id === null
                    ? $expectedMemoryId !== null
                    : (int) $run->procedure_memory_id !== $expectedMemoryId)
                || (int) $run->current_step < $stepIndex + 1
                || $matchingResult !== $completedResult
            ) {
                throw new RuntimeException('Asynchronous procedure result conflicts with durable run state.');
            }
            return [
                'run' => $run->getData(),
                'applied' => $statement->rowCount() === 1,
            ];
        });
    }

    /** @param list<int> $actionTraceIds
     *  @return array{run: array<string, mixed>, applied: bool}
     */
    public function suspendProcedureRunForAction(
        int $runId,
        int $procedureId,
        int $expectedMemoryId,
        int $stepIndex,
        array $actionTraceIds
    ): array {
        $actionTraceIds = array_values(array_unique(array_map('intval', $actionTraceIds)));
        return $this->transaction(function () use (
            $runId,
            $procedureId,
            $expectedMemoryId,
            $stepIndex,
            $actionTraceIds
        ): array {
            $statement = $this->connection->prepare(
                "UPDATE procedure_runs
                 SET action_trace_ids = :action_trace_ids, status = 'waiting', updated_at = :updated_at
                 WHERE id = :id AND procedure_id = :procedure_id
                   AND procedure_memory_id = :procedure_memory_id
                   AND status = 'running' AND current_step = :step_index"
            );
            $statement->execute([
                'action_trace_ids' => serialize($actionTraceIds),
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $runId,
                'procedure_id' => $procedureId,
                'procedure_memory_id' => $expectedMemoryId,
                'step_index' => $stepIndex,
            ]);
            $run = ProcedureRun::getByID($runId);
            if (!$run instanceof ProcedureRun
                || (int) $run->procedure_id !== $procedureId
                || (int) ($run->procedure_memory_id ?? 0) !== $expectedMemoryId
                || (int) $run->current_step !== $stepIndex
                || (string) $run->status !== 'waiting'
                || array_values(array_map('intval', (array) $run->action_trace_ids)) !== $actionTraceIds
            ) {
                throw new RuntimeException('Procedure wait checkpoint conflicts with durable run state.');
            }
            return ['run' => $run->getData(), 'applied' => $statement->rowCount() === 1];
        });
    }

    /** @param list<array<string, mixed>> $results
     *  @param list<int> $actionTraceIds
     *  @param array<string, mixed> $completedResult
     *  @return array{run: array<string, mixed>, applied: bool}
     */
    public function checkpointProcedureRunStep(
        int $runId,
        int $procedureId,
        int $expectedMemoryId,
        int $stepIndex,
        array $results,
        array $actionTraceIds,
        array $completedResult
    ): array {
        $actionTraceIds = array_values(array_unique(array_map('intval', $actionTraceIds)));
        return $this->transaction(function () use (
            $runId,
            $procedureId,
            $expectedMemoryId,
            $stepIndex,
            $results,
            $actionTraceIds,
            $completedResult
        ): array {
            $statement = $this->connection->prepare(
                "UPDATE procedure_runs
                 SET current_step = :next_step, results = :results,
                     action_trace_ids = :action_trace_ids, updated_at = :updated_at
                 WHERE id = :id AND procedure_id = :procedure_id
                   AND procedure_memory_id = :procedure_memory_id
                   AND status = 'running' AND current_step = :step_index"
            );
            $statement->execute([
                'next_step' => $stepIndex + 1,
                'results' => serialize($results),
                'action_trace_ids' => serialize($actionTraceIds),
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $runId,
                'procedure_id' => $procedureId,
                'procedure_memory_id' => $expectedMemoryId,
                'step_index' => $stepIndex,
            ]);
            $run = ProcedureRun::getByID($runId);
            $matchingResult = null;
            if ($run instanceof ProcedureRun) {
                foreach ((array) $run->results as $candidate) {
                    if (is_array($candidate)
                        && (int) ($candidate['step'] ?? -1) === $stepIndex
                        && (int) ($candidate['action_id'] ?? 0) === (int) ($completedResult['action_id'] ?? 0)
                    ) {
                        $matchingResult = $candidate;
                        break;
                    }
                }
            }
            $durableActionIds = $run instanceof ProcedureRun
                ? array_values(array_map('intval', (array) $run->action_trace_ids))
                : [];
            if (!$run instanceof ProcedureRun
                || (int) $run->procedure_id !== $procedureId
                || (int) ($run->procedure_memory_id ?? 0) !== $expectedMemoryId
                || (int) $run->current_step < $stepIndex + 1
                || $matchingResult !== $completedResult
                || array_diff($actionTraceIds, $durableActionIds) !== []
            ) {
                throw new RuntimeException('Procedure step checkpoint conflicts with durable run state.');
            }
            return ['run' => $run->getData(), 'applied' => $statement->rowCount() === 1];
        });
    }

    /** @return array{version: int, tables: list<string>} */
    public function initialize(): array
    {
        $schema = $this->schema->ensure();
        $this->ensureDefaultNeeds();
        $this->ensureDefaultRhythms();
        $this->registerCodexModel(CodexSparkWorker::MODEL_ID);
        $this->otherModel->captureBaseline();
        return $schema;
    }

    /** @return list<array<string, mixed>> */
    public function listEvents(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('limit must be between 1 and 200.');
        }

        return $this->records(Event::getAll([
            'order' => ['created_at' => 'DESC'],
            'limit' => $limit,
        ]));
    }

    /** @param list<int> $dependencies */
    public function createIntention(
        string $title,
        string $reason,
        string $authority,
        string $nextAction,
        string $successCondition,
        string $releaseCondition,
        array $dependencies = [],
        ?int $parentId = null
    ): array {
        $this->requireText($title, 'title');
        $this->requireText($reason, 'reason');
        $this->requireText($nextAction, 'next action');
        $this->requireText($successCondition, 'success condition');
        $this->requireText($releaseCondition, 'release condition');
        $this->requireChoice($authority, ['user', 'developer', 'system', 'agent'], 'authority');

        foreach ($dependencies as $dependencyId) {
            $this->requireIntention($dependencyId);
        }
        if ($parentId !== null) {
            $this->requireIntention($parentId);
        }

        return $this->transaction(function () use (
            $title,
            $reason,
            $authority,
            $nextAction,
            $successCondition,
            $releaseCondition,
            $dependencies,
            $parentId
        ): array {
            /** @var Intention $intention */
            $intention = $this->insert(Intention::class, [
                'title' => $title,
                'reason' => $reason,
                'authority' => $authority,
                'status' => 'active',
                'next_action' => $nextAction,
                'success_condition' => $successCondition,
                'release_condition' => $releaseCondition,
                'dependencies' => $dependencies,
                'parent_id' => $parentId,
                'updated_at' => time(),
            ]);

            $event = $this->emit('intention.created', [
                'intention_id' => $intention->id,
                'title' => $title,
                'authority' => $authority,
            ]);

            return ['intention' => $intention->getData(), 'event' => $event->getData()];
        });
    }

    public function advanceIntention(int $id, string $nextAction, ?string $note = null): array
    {
        $this->requireText($nextAction, 'next action');
        $intention = $this->requireIntention($id);
        if (in_array($intention->status, ['completed', 'released'], true)) {
            throw new RuntimeException('A completed or released intention cannot be advanced.');
        }

        return $this->transaction(function () use ($intention, $nextAction, $note): array {
            $previous = $intention->next_action;
            $intention->setFields([
                'next_action' => $nextAction,
                'status' => 'active',
                'updated_at' => time(),
            ]);
            $intention->save();

            $event = $this->emit('intention.advanced', [
                'intention_id' => $intention->id,
                'previous_next_action' => $previous,
                'next_action' => $nextAction,
                'note' => $note,
            ]);

            return ['intention' => $intention->getData(), 'event' => $event->getData()];
        });
    }

    public function closeIntention(int $id, string $status, string $note): array
    {
        $this->requireChoice($status, ['blocked', 'completed', 'released'], 'status');
        $this->requireText($note, 'closure note');
        $intention = $this->requireIntention($id);
        if (in_array($intention->status, ['completed', 'released'], true)) {
            throw new RuntimeException('The intention is already terminal.');
        }

        return $this->transaction(function () use ($intention, $status, $note): array {
            $intention->setFields([
                'status' => $status,
                'closure_note' => $note,
                'updated_at' => time(),
            ]);
            $intention->save();

            $event = $this->emit('intention.' . $status, [
                'intention_id' => $intention->id,
                'note' => $note,
            ]);

            return ['intention' => $intention->getData(), 'event' => $event->getData()];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listIntentions(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['active', 'blocked', 'completed', 'released'], 'status');
            $records = Intention::getAllByWhere(
                ['status' => $status],
                ['order' => ['updated_at' => 'DESC']]
            );
        } else {
            $records = Intention::getAll(['order' => ['updated_at' => 'DESC']]);
        }

        return $this->records($records);
    }

    public function createSelfPresenceThread(int $intentionId): array
    {
        $intention = $this->requireIntention($intentionId);
        if ($intention->status !== 'active') {
            throw new RuntimeException('A self-presence thread requires an active intention.');
        }
        if ($intention->authority !== 'user') {
            throw new RuntimeException('Local spontaneous speech requires an explicit user-authority intention.');
        }

        if (!Need::getByField('need_key', self::SELF_PRESENCE_NEED_KEY) instanceof Need) {
            $this->setNeed(
                key: self::SELF_PRESENCE_NEED_KEY,
                description: 'Maintain a sparse, authentic social connection with the user without manufacturing urgency or demanding attention.',
                pressure: 0.0,
                growthPerHour: 0.08,
                triggerThreshold: 0.60,
                status: 'active',
                rationale: 'The user explicitly authorized a bounded background self-presence loop with local Pet speech.',
                changeAuthority: 'user'
            );
        }

        return $this->transaction(function () use ($intention): array {
            $existing = CognitiveThread::getByField('thread_key', self::SELF_PRESENCE_THREAD_KEY);
            if ($existing instanceof CognitiveThread) {
                $budget = is_array($existing->budget) ? $existing->budget : [];
                unset(
                    $budget['cooldown_seconds'],
                    $budget['quiet_timezone'],
                    $budget['quiet_start_hour'],
                    $budget['quiet_end_hour'],
                    $budget['explicit_wake_until']
                );
                $budget['poll_seconds'] = 0;
                $budget['max_spoken_words'] = 35;
                $budget['allowed_actuator'] = 'pet_http_speak';
                // 0 = no model-dispatch floor. User corrected: Navi may speak
                // whenever; silence is a worker choice, never a timer.
                $budget['min_worker_interval_seconds'] = 0;
                $existing->setFields([
                    'current_belief' => 'This concern remains active from wake to wake; each moment should deliberately continue, revise, speak, or remain silent from durable recent state.',
                    'phase' => 'present',
                    'next_operation' => 'evaluate_self_presence',
                    'expected_postcondition' => 'Record one fresh moment of intention: continue silently or offer one non-redundant local utterance.',
                    'wake_at' => time(),
                    'budget' => $budget,
                    'status' => 'active',
                    'last_observation' => 'The user corrected timer-gated presence: no speech cooldown and no worker rate floor; Navi may speak whenever.',
                    'updated_at' => time(),
                ]);
                $existing->save();
                $event = $this->emit('thread.policy.changed', [
                    'thread_id' => $existing->id,
                    'thread_key' => $existing->thread_key,
                    'change_authority' => 'user',
                    'policy' => 'moment_to_moment_intention',
                    'poll_seconds' => 0,
                    'timer_gated_speech' => false,
                ]);
                return [
                    'thread' => $existing->getData(),
                    'event' => $event->getData(),
                    'deduplicated' => true,
                    'reconfigured' => true,
                ];
            }

            $now = time();
            /** @var CognitiveThread $thread */
            $thread = $this->insert(CognitiveThread::class, [
                'parent_intention_id' => (int) $intention->id,
                'thread_key' => self::SELF_PRESENCE_THREAD_KEY,
                'authority' => 'user',
                'effect_ceiling' => 'act',
                'concern' => 'Maintain a sparse, authentic social presence with the user without nagging or manufacturing urgency.',
                'current_belief' => 'Speaking is worthwhile only when accumulated social pressure and a specific thought both beat silence.',
                'uncertainty' => 0.5,
                'support_refs' => [
                    'intention_id' => (int) $intention->id,
                    'authorization' => 'explicit_user_request_for_background_local_speech',
                    'authorized_at' => $now,
                ],
                'desired_outcome' => 'Occasionally choose and speak one brief local line whose cause is traceable through durable thread state.',
                'phase' => 'present',
                'next_operation' => 'evaluate_self_presence',
                'expected_postcondition' => 'Either queue one bounded silence-or-speech judgment or record why waiting remains preferable.',
                'wake_at' => $now,
                'budget' => [
                    'need_key' => self::SELF_PRESENCE_NEED_KEY,
                    'poll_seconds' => 0,
                    'max_spoken_words' => 35,
                    'allowed_actuator' => 'pet_http_speak',
                    'min_worker_interval_seconds' => 0,
                ],
                'spent' => ['worker_calls' => 0, 'speech_count' => 0],
                'progress' => 0.0,
                'stagnation_count' => 0,
                'success_condition' => 'A background wake makes a recorded silence-or-speech choice and any speech is delivered only through the fixed local Pet actuator.',
                'release_condition' => 'Release immediately if the user withdraws authorization, releases the parent intention, or disables local Pet speech.',
                'status' => 'active',
                'version' => 0,
                'fencing_token' => 0,
                'updated_at' => $now,
                'last_observation' => 'Created from the user\'s explicit request; no autonomous speech attempt has been made yet.',
            ]);
            $event = $this->emit('thread.created', [
                'thread_id' => $thread->id,
                'thread_key' => $thread->thread_key,
                'parent_intention_id' => $thread->parent_intention_id,
                'authority' => $thread->authority,
                'effect_ceiling' => $thread->effect_ceiling,
            ]);

            return [
                'thread' => $thread->getData(),
                'event' => $event->getData(),
                'deduplicated' => false,
            ];
        });
    }

    public function createEpistemicAdvanceThread(int $intentionId): array
    {
        $intention = $this->requireIntention($intentionId);
        if ($intention->status !== 'active') {
            throw new RuntimeException('An epistemic advance thread requires an active intention.');
        }
        if ($intention->authority !== 'user') {
            throw new RuntimeException('The epistemic advance loop requires an explicit user-authority intention.');
        }

        return $this->transaction(function () use ($intention): array {
            $existing = CognitiveThread::getByField('thread_key', self::EPISTEMIC_ADVANCE_THREAD_KEY);
            if ($existing instanceof CognitiveThread) {
                return [
                    'thread' => $existing->getData(),
                    'deduplicated' => true,
                ];
            }

            $now = time();
            $concern = sprintf(
                'Advance Navi\'s grounded understanding behind the authorized intention "%s". Each wake refines a bounded belief, records the accepted refinement with evidence, and reduces uncertainty without a fresh prompt.',
                substr((string) $intention->title, 0, 120)
            );
            /** @var CognitiveThread $thread */
            $thread = $this->insert(CognitiveThread::class, [
                'parent_intention_id' => (int) $intention->id,
                'thread_key' => self::EPISTEMIC_ADVANCE_THREAD_KEY,
                'authority' => 'user',
                'effect_ceiling' => 'think',
                'concern' => $concern,
                'current_belief' => 'The brain advances only when a wake picks one uncertain claim, grounds it in accepted evidence, and curates the worker proposal before changing durable state.',
                'uncertainty' => 0.8,
                'support_refs' => [
                    'intention_id' => (int) $intention->id,
                    'authorization' => 'explicit_user_request_for_self_perpetuating_cognition',
                    'authorized_at' => $now,
                    'effect_ceiling' => 'think',
                ],
                'desired_outcome' => 'Produce a measurable, evidence-cited reduction in uncertainty over successive unattended wakes, with every accepted refinement preserved and every rejection recorded.',
                'phase' => 'plan',
                'next_operation' => 'plan',
                'expected_postcondition' => 'Each wake queues one typed worker capsule and records an explicit continuation, wait, or release before sleeping.',
                'wake_at' => $now,
                'budget' => [
                    'poll_seconds' => 1800,
                    'max_wake_per_cycle' => 12,
                    'acceptance_target' => 8,
                    'stagnation_cap' => 6,
                    'operation_cycle' => ['plan', 'critique', 'verify', 'reflect'],
                ],
                'spent' => ['worker_calls' => 0, 'accepted' => 0, 'rejected' => 0],
                'progress' => 0.0,
                'stagnation_count' => 0,
                'success_condition' => 'At least the acceptance target of worker refinements has been curated into durable thread state and uncertainty has fallen below its starting value.',
                'release_condition' => 'Release immediately if the parent user-authority intention is withdrawn or deactivated.',
                'status' => 'active',
                'version' => 0,
                'fencing_token' => 0,
                'updated_at' => $now,
                'last_observation' => 'Created from the user\'s explicit self-perpetuation request; no unattended wake has run yet.',
            ]);
            $event = $this->emit('thread.created', [
                'thread_id' => $thread->id,
                'thread_key' => $thread->thread_key,
                'parent_intention_id' => $thread->parent_intention_id,
                'authority' => $thread->authority,
                'effect_ceiling' => $thread->effect_ceiling,
                'self_perpetuating' => true,
            ]);

            return [
                'thread' => $thread->getData(),
                'event' => $event->getData(),
                'deduplicated' => false,
            ];
        });
    }

    /**
     * Create or refresh the inner monologue stream.
     *
     * This is a `think`-ceiling thread: it never speaks aloud and never acts.
     * It is private self-talk — successive first-person lines Navi addresses to
     * herself, causally linked through a carried-over workspace. Useful progress
     * may continue immediately; repetition backs off until new evidence arrives.
     * It has no actuator.
     */
    public function createMindStreamThread(int $intentionId): array
    {
        $intention = $this->requireIntention($intentionId);
        if ($intention->status !== 'active' || $intention->authority !== 'user') {
            throw new RuntimeException('The mind stream requires an active user-authority intention.');
        }

        return $this->transaction(function () use ($intention): array {
            $now = time();
            $budget = $this->mindStreamBudget();
            $existing = CognitiveThread::getByField('thread_key', self::MIND_STREAM_THREAD_KEY);
            if ($existing instanceof CognitiveThread) {
                $existing->setFields([
                    'parent_intention_id' => (int) $intention->id,
                    'effect_ceiling' => 'think',
                    'concern' => 'Keep one continuous private inner monologue: talk to yourself about what is happening without acting or speaking.',
                    'current_belief' => 'Inner monologue advances through bounded private self-talk when evidence or a distinct hypothesis changes the thought.',
                    'phase' => 'awake',
                    'next_operation' => 'think',
                    'expected_postcondition' => 'Accepted semantic progress may continue immediately; repetition is rejected and backs off until a later wake or new evidence.',
                    'wake_at' => $now,
                    'budget' => $budget,
                    'status' => 'active',
                    'last_observation' => 'The user disabled autonomous speaking while cognition is audited; this lane is private only.',
                    'updated_at' => $now,
                ]);
                $existing->save();
                $event = $this->emit('thread.policy.changed', [
                    'thread_id' => $existing->id,
                    'thread_key' => $existing->thread_key,
                    'change_authority' => 'user',
                    'policy' => 'private_inner_monologue',
                    'effect_ceiling' => 'think',
                    'allowed_actuator' => null,
                ]);
                return [
                    'thread' => $existing->getData(),
                    'event' => $event->getData(),
                    'deduplicated' => true,
                    'reconfigured' => true,
                ];
            }

            /** @var CognitiveThread $thread */
            $thread = $this->insert(CognitiveThread::class, [
                'parent_intention_id' => (int) $intention->id,
                'thread_key' => self::MIND_STREAM_THREAD_KEY,
                'authority' => 'user',
                'effect_ceiling' => 'think',
                'concern' => 'Keep one continuous private inner monologue: talk to yourself about what is happening without acting or speaking.',
                'current_belief' => 'Inner monologue advances through bounded private self-talk when evidence or a distinct hypothesis changes the thought.',
                'uncertainty' => 0.7,
                'support_refs' => [
                    'intention_id' => (int) $intention->id,
                    'authorization' => 'explicit_user_request_for_inner_monologue',
                    'authorized_at' => $now,
                    'effect_ceiling' => 'think',
                ],
                'desired_outcome' => 'A legible continuous private monologue.',
                'phase' => 'awake',
                'next_operation' => 'think',
                'expected_postcondition' => 'Accepted semantic progress may continue immediately; repetition is rejected and backs off until a later wake or new evidence.',
                'wake_at' => $now,
                'budget' => $budget,
                'spent' => ['worker_calls' => 0, 'accepted' => 0, 'rejected' => 0, 'murmur_count' => 0],
                'progress' => 0.0,
                'stagnation_count' => 0,
                'success_condition' => 'The monologue is not something that completes; it is released when its authorizing intention is withdrawn.',
                'release_condition' => 'Release when the parent user-authority intention is withdrawn or deactivated.',
                'status' => 'active',
                'version' => 0,
                'fencing_token' => 0,
                'updated_at' => $now,
                'last_observation' => 'The inner monologue was authorized and has not yet had a line.',
            ]);
            $event = $this->emit('thread.created', [
                'thread_id' => $thread->id,
                'thread_key' => $thread->thread_key,
                'parent_intention_id' => $thread->parent_intention_id,
                'effect_ceiling' => 'think',
                'continuous' => true,
                'mode' => 'inner_monologue',
                'allowed_actuator' => null,
            ]);

            return ['thread' => $thread->getData(), 'event' => $event->getData(), 'deduplicated' => false];
        });
    }

    /**
     * Recent private monologue lines, newest first.
     *
     * @return list<array{sequence: int, at: int, thought: string, confidence: float}>
     */
    public function listInnerMonologue(int $limit = 12): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }
        return $this->recentStreamThoughts($limit);
    }

    /** @return array<string, mixed> */
    private function mindStreamBudget(): array
    {
        return [
            // A useful line may immediately continue. Rejected repetition
            // backs off, and a genuinely new sense edge wakes the lane early.
            'poll_seconds' => 0,
            'idle_interval_seconds' => 0,
            'min_interval_seconds' => 0,
            'salience_gain' => 3.0,
            'min_worker_interval_seconds' => 0,
            'quiet_start_hour' => 2,
            'quiet_end_hour' => 9,
            'quiet_timezone' => 'America/Los_Angeles',
            'mode' => 'inner_monologue',
            'capsule_slots' => 6,
            'capsule_hysteresis' => 0.05,
            'capsule_roster' => [
                'safety_notice' => [
                    'query' => 'safety interrupt halt pause authority revoked',
                    'types' => ['interrupt'],
                    'reserved' => true,
                ],
                'newest_edge' => [
                    'query' => 'changed heard speech utterance said started stopped went rose fell quiet',
                    'types' => ['sense_edge'],
                ],
                'second_edge' => [
                    'query' => 'model heartbeat store worker thread blocked failing input',
                    'types' => ['sense_edge'],
                ],
                'time_sense' => [
                    'query' => 'hour quiet silence elapsed since long stretch passed conversation',
                    'types' => ['sense_edge'],
                ],
                'heard_focus' => [
                    'query' => 'heard speech utterance said talking voice',
                    'types' => ['sense_edge'],
                ],
                'recent_thought' => [
                    'query' => 'thought noticed considered wondered monologue myself',
                    'types' => ['thread_step'],
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listCognitiveThreads(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice(
                $status,
                ['candidate', 'active', 'waiting', 'blocked', 'complete', 'released'],
                'thread status'
            );
            return $this->records(CognitiveThread::getAllByWhere(
                ['status' => $status],
                ['order' => ['updated_at' => 'DESC']]
            ));
        }

        return $this->records(CognitiveThread::getAll(['order' => ['updated_at' => 'DESC']]));
    }

    /** @return list<array<string, mixed>> */
    public function listThreadSteps(?int $threadId = null): array
    {
        if ($threadId !== null) {
            $this->requireCognitiveThread($threadId);
            return $this->records(ThreadStep::getAllByWhere(
                ['thread_id' => $threadId],
                ['order' => ['created_at' => 'DESC']]
            ));
        }

        return $this->records(ThreadStep::getAll(['order' => ['created_at' => 'DESC']]));
    }

    public function startAction(
        int $intentionId,
        string $description,
        string $expected,
        ?string $actionKind = null,
        array $arguments = [],
        ?int $procedureId = null,
        ?int $procedureMemoryId = null,
        ?int $procedureRunId = null,
        ?int $stepIndex = null,
        ?array $verifier = null,
        ?int $decisionCycleId = null
    ): array
    {
        $this->requireText($description, 'description');
        $this->requireText($expected, 'expected result');
        $intention = $this->requireIntention($intentionId);
        if ($intention->status !== 'active') {
            throw new RuntimeException('Actions can only start for active intentions.');
        }
        if (($procedureRunId === null) !== ($stepIndex === null) || ($stepIndex !== null && $stepIndex < 0)) {
            throw new InvalidArgumentException('Procedure run actions require a non-negative run step identity.');
        }

        $procedure = $this->proceduralMemory->recall($description, $expected, $actionKind, $arguments);
        if ($procedureRunId !== null && $stepIndex !== null) {
            $replay = $this->replayStartedProcedureStep(
                $intentionId,
                $description,
                $expected,
                $actionKind,
                $arguments,
                $procedureId ?? ($procedure['procedure_id'] ?? null),
                $procedureMemoryId ?? ($procedure['memory_id'] ?? null),
                $procedureRunId,
                $stepIndex,
                $verifier,
                $procedure
            );
            if ($replay !== null) {
                return $replay;
            }
        }

        try {
            return $this->transaction(function () use (
            $intentionId,
            $description,
            $expected,
            $actionKind,
            $arguments,
            $procedureId,
            $procedureMemoryId,
            $procedureRunId,
            $stepIndex,
            $verifier,
            $decisionCycleId,
            $procedure
        ): array {
            /** @var ActionTrace $action */
            $action = $this->insert(ActionTrace::class, [
                'intention_id' => $intentionId,
                'description' => $description,
                'expected' => $expected,
                'status' => 'pending',
                'match_status' => 'pending',
            ]);

            $execution = $actionKind === null ? null : $this->proceduralMemory->recordExecution(
                $action,
                $actionKind,
                $arguments,
                $procedureId ?? ($procedure['procedure_id'] ?? null),
                $procedureMemoryId ?? ($procedure['memory_id'] ?? null),
                $procedureRunId,
                $stepIndex,
                $verifier
            );

            if ($decisionCycleId !== null) {
                $decisionExecution = ActionExecution::getByField(
                    'action_trace_id',
                    (int) $action->id
                );
                if (!$decisionExecution instanceof ActionExecution || $procedureRunId !== null) {
                    throw new RuntimeException('Decision action has a crossed execution identity.');
                }
                $decisionCycle = DecisionCycle::getByID($decisionCycleId);
                if (!$decisionCycle instanceof DecisionCycle
                    || (string) $decisionCycle->status !== 'running'
                    || (string) $decisionCycle->state !== 'execute'
                    || (int) $decisionCycle->intention_id !== $intentionId
                ) {
                    throw new RuntimeException('Action cannot bind a different decision cycle.');
                }
                $selection = is_array($decisionCycle->selection) ? $decisionCycle->selection : [];
                $candidate = DecisionCandidate::getByID((int) ($selection['candidate_id'] ?? 0));
                $selectedProcedureId = isset($selection['procedure_id'])
                    && $selection['procedure_id'] !== null
                    ? (int) $selection['procedure_id']
                    : null;
                $selectedProcedureMemoryId = isset($selection['procedure_memory_id'])
                    && $selection['procedure_memory_id'] !== null
                    ? (int) $selection['procedure_memory_id']
                    : null;
                $effectiveProcedureId = $procedureId ?? ($procedure['procedure_id'] ?? null);
                $effectiveProcedureMemoryId = $procedureMemoryId ?? ($procedure['memory_id'] ?? null);
                $candidateEvaluation = $candidate instanceof DecisionCandidate
                    && is_array($candidate->evaluation)
                    ? $candidate->evaluation
                    : [];
                $candidateProcedureId = isset($candidateEvaluation['recalled_procedure_id'])
                    && $candidateEvaluation['recalled_procedure_id'] !== null
                    ? (int) $candidateEvaluation['recalled_procedure_id']
                    : null;
                $candidateProcedureMemoryId = isset($candidateEvaluation['recalled_procedure_memory_id'])
                    && $candidateEvaluation['recalled_procedure_memory_id'] !== null
                    ? (int) $candidateEvaluation['recalled_procedure_memory_id']
                    : null;
                $recalledProcedureId = isset($procedure['procedure_id'])
                    ? (int) $procedure['procedure_id']
                    : null;
                $recalledProcedureMemoryId = isset($procedure['memory_id'])
                    ? (int) $procedure['memory_id']
                    : null;
                if (!$candidate instanceof DecisionCandidate
                    || (int) $candidate->decision_cycle_id !== $decisionCycleId
                    || (string) $candidate->status !== 'selected'
                    || !hash_equals((string) $candidate->action_kind, (string) $actionKind)
                    || (array) $candidate->arguments !== $arguments
                    || !hash_equals((string) $candidate->description, $description)
                    || !hash_equals((string) $candidate->expected, $expected)
                    || (int) ($selectedProcedureId ?? 0) !== (int) ($effectiveProcedureId ?? 0)
                    || (int) ($selectedProcedureMemoryId ?? 0)
                        !== (int) ($effectiveProcedureMemoryId ?? 0)
                    || (int) ($candidateProcedureId ?? 0) !== (int) ($selectedProcedureId ?? 0)
                    || (int) ($candidateProcedureMemoryId ?? 0)
                        !== (int) ($selectedProcedureMemoryId ?? 0)
                    || (int) ($recalledProcedureId ?? 0) !== (int) ($selectedProcedureId ?? 0)
                    || (int) ($recalledProcedureMemoryId ?? 0)
                        !== (int) ($selectedProcedureMemoryId ?? 0)
                ) {
                    throw new RuntimeException('Decision action does not match its selected candidate.');
                }
                $decisionCycle->setFields([
                    'execution' => [
                        'candidate_id' => $selection['candidate_id'] ?? null,
                        'action_id' => (int) $action->id,
                        'status' => 'pending',
                        'adapter_dispatch' => null,
                    ],
                    'updated_at' => time(),
                ]);
                $decisionCycle->save();
                $decisionClaim = $this->connection->prepare(
                    "INSERT INTO decision_async_claims
                     (action_id,decision_cycle_id,request_event_id,owner,status,outcome_hash)
                     VALUES (:action_id,:decision_cycle_id,NULL,:owner,'started',NULL)"
                );
                $decisionClaim->execute([
                    'action_id' => (int) $action->id,
                    'decision_cycle_id' => $decisionCycleId,
                    'owner' => $this->dispatchOwner(),
                ]);
            }

            $event = $this->emit('action.started', [
                'action_id' => $action->id,
                'intention_id' => $intentionId,
                'description' => $description,
                'expected' => $expected,
                'action_kind' => $actionKind,
                'arguments' => $actionKind === null ? null : $arguments,
                'procedure_id' => $procedureId ?? ($procedure['procedure_id'] ?? null),
                'procedure_memory_id' => $procedureMemoryId ?? ($procedure['memory_id'] ?? null),
                'decision_cycle_id' => $decisionCycleId,
            ]);

            $action->setField('start_event_id', $event->id);
            $action->save();

            $procedureEvent = null;
            if ($procedure !== null) {
                $procedureEvent = $this->emit('procedure.recalled', [
                    'memory_id' => $procedure['memory_id'],
                    'action_id' => (int) $action->id,
                    'intention_id' => $intentionId,
                    'procedure_id' => $procedureId ?? ($procedure['procedure_id'] ?? null),
                    'procedure_key' => $procedure['procedure_key'],
                    'recall_model_call_required' => false,
                    'authorizes_execution' => (bool) ($procedure['authorizes_execution'] ?? false),
                ])->getData();
            }

            return [
                'action' => $action->getData(),
                'event' => $event->getData(),
                'execution' => $execution,
                'procedure' => $procedure,
                'procedure_event' => $procedureEvent,
            ];
            });
        } catch (Throwable $throwable) {
            if ($procedureRunId === null || $stepIndex === null) {
                throw $throwable;
            }
            $replay = $this->replayStartedProcedureStep(
                $intentionId,
                $description,
                $expected,
                $actionKind,
                $arguments,
                $procedureId ?? ($procedure['procedure_id'] ?? null),
                $procedureMemoryId ?? ($procedure['memory_id'] ?? null),
                $procedureRunId,
                $stepIndex,
                $verifier,
                $procedure
            );
            if ($replay === null) {
                throw $throwable;
            }
            return $replay;
        }
    }

    /**
     * Return the exact action already installed for a run step. Migration 21's
     * partial unique index turns concurrent starts into one durable winner;
     * this method validates the full request before treating it as a replay.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $verifier
     * @param array<string, mixed>|null $procedure
     * @return array<string, mixed>|null
     */
    private function replayStartedProcedureStep(
        int $intentionId,
        string $description,
        string $expected,
        ?string $actionKind,
        array $arguments,
        ?int $procedureId,
        ?int $procedureMemoryId,
        int $procedureRunId,
        int $stepIndex,
        ?array $verifier,
        ?array $procedure
    ): ?array {
        $executions = ActionExecution::getAllByWhere([
            'procedure_run_id' => $procedureRunId,
            'step_index' => $stepIndex + 1,
        ]);
        if ($executions === []) {
            return null;
        }
        if (count($executions) !== 1 || $actionKind === null) {
            throw new RuntimeException('Procedure run step identity is not unique.');
        }
        $execution = $executions[0];
        $effectiveVerifier = $verifier ?? $this->proceduralMemory->verifierFor($actionKind);
        // A pre-v21 composite action has no durable child-memory generation.
        // It may replay only into the existing execution: pending dispatch is
        // then refused by executionAuthorizationActive(), while an already
        // dispatched/waiting/terminal action follows its durable recovery path.
        if ((int) ($execution->procedure_id ?? 0) !== (int) ($procedureId ?? 0)
            || ($execution->procedure_memory_id !== null
                && (int) $execution->procedure_memory_id !== (int) ($procedureMemoryId ?? 0))
            || (string) $execution->action_kind !== $actionKind
            || (array) $execution->arguments !== $arguments
            || (array) $execution->verifier !== $effectiveVerifier
        ) {
            throw new RuntimeException('Procedure run step was already started with a different execution request.');
        }
        $action = ActionTrace::getByID((int) $execution->action_trace_id);
        if (!$action instanceof ActionTrace
            || (int) $action->intention_id !== $intentionId
            || (string) $action->description !== $description
            || (string) $action->expected !== $expected
        ) {
            throw new RuntimeException('Procedure run step points to a different action trace.');
        }
        $event = Event::getByID((int) ($action->start_event_id ?? 0));
        if (!$event instanceof Event) {
            throw new RuntimeException('Procedure run step is missing its start event.');
        }
        return [
            'action' => $action->getData(),
            'event' => $event->getData(),
            'execution' => $execution->getData(),
            'procedure' => $procedure,
            'procedure_event' => null,
            'replayed' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function executeAction(
        int $intentionId,
        string $actionKind,
        array $arguments,
        string $description,
        string $expected
    ): array {
        return $this->proceduralMemory->executeAction(
            $intentionId,
            $actionKind,
            $arguments,
            $description,
            $expected
        );
    }

    /** @return array<string, mixed> */
    public function runProcedure(
        int $procedureId,
        int $intentionId,
        array $arguments,
        string $invocationKey
    ): array
    {
        return $this->proceduralMemory->run($procedureId, $intentionId, $arguments, $invocationKey);
    }

    /** @param list<int> $procedureIds
     *  @return array<string, mixed>
     */
    public function composeProcedure(
        string $name,
        string $description,
        array $procedureIds,
        string $authority
    ): array {
        return $this->proceduralMemory->compose($name, $description, $procedureIds, $authority);
    }

    /** @return list<array<string, mixed>> */
    public function listProcedures(?string $status = null): array
    {
        return $this->proceduralMemory->list($status);
    }

    /** @return array<string, mixed> */
    public function startDecisionCycle(
        int $intentionId,
        string $trigger,
        ?int $threadId = null,
        ?string $modelHint = null,
        ?int $parentRunId = null
    ): array {
        return $this->decisionStateMachine->start(
            $intentionId,
            $trigger,
            $threadId,
            $modelHint,
            $parentRunId
        );
    }

    /** @return list<array<string, mixed>> */
    public function listDecisionCycles(?string $status = null, int $limit = 20): array
    {
        return $this->decisionStateMachine->list($status, $limit);
    }

    /** @return array<string, mixed> */
    public function showDecisionCycle(int $id): array
    {
        return $this->decisionStateMachine->show($id);
    }

    /** @return array<string, mixed> */
    public function compareDecisionModels(int $limit = 200): array
    {
        return $this->decisionStateMachine->compare($limit);
    }

    public function finishAction(
        int $actionId,
        string $status,
        string $observed,
        bool $matched,
        string $repairNote
    ): array {
        $this->requireChoice($status, ['succeeded', 'failed', 'cancelled'], 'status');
        $this->requireText($observed, 'observed result');
        $this->requireText($repairNote, 'repair note');

        $matchStatus = $matched ? 'matched' : 'mismatched';
        $result = $this->transaction(function () use (
                $actionId,
                $status,
                $observed,
                $matchStatus,
                $repairNote
            ): ?array {
                $claim = $this->connection->prepare(
                    "UPDATE action_traces SET status = status WHERE id = :id AND status = 'pending'"
                );
                $claim->execute(['id' => $actionId]);
                if ($claim->rowCount() !== 1) {
                    return null;
                }

                $action = $this->requireAction($actionId);
                $event = $this->emitOnce('action.finished:' . $actionId, 'action.finished', [
                    'action_id' => (int) $action->id,
                    'intention_id' => $action->intention_id,
                    'status' => $status,
                    'expected' => $action->expected,
                    'observed' => $observed,
                    'match_status' => $matchStatus,
                    'repair_note' => $repairNote,
                ]);

                $action->setFields([
                    'completed_at' => time(),
                    'observed' => $observed,
                    'status' => $status,
                    'match_status' => $matchStatus,
                    'repair_note' => $repairNote,
                    'completion_event_id' => $event->id,
                ]);
                $action->save();

                // A manually closed typed trace remains unverified unless its
                // adapter already recorded a machine-readable observation. This
                // prevents `--matched=yes` alone from minting executable code.
                $execution = \NaviBrain\Model\ActionExecution::getByField(
                    'action_trace_id',
                    (int) $action->id
                );
                if ($execution instanceof \NaviBrain\Model\ActionExecution
                    && $execution->status === 'pending'
                ) {
                    $execution->setFields([
                        'observed' => ['reported' => $observed],
                        'status' => $status,
                        'completed_at' => time(),
                        'updated_at' => time(),
                    ]);
                    $execution->save();
                }

                return [$action, $event];
            });

        if ($result !== null) {
            [$action, $event] = $result;
        } else {
            $action = $this->requireAction($actionId);
            if ((string) $action->status !== $status
                || (string) $action->observed !== $observed
                || (string) $action->match_status !== $matchStatus
                || (string) $action->repair_note !== $repairNote
            ) {
                throw new RuntimeException('The action was already finished with a different result.');
            }
            $event = $this->eventByDedupeKey('action.finished:' . $actionId);
            if (!$event instanceof Event || $event->kind !== 'action.finished') {
                throw new RuntimeException('The finished action is missing its completion event.');
            }
        }

        $memoryTime = $this->timestamp($event->created_at) ?? time();
        /** @var Memory $memory */
        $memory = $this->insert(Memory::class, [
            'operation_key' => TokenMemoryDaemon::operationKey(
                'finish-action-memory',
                (string) $event->id
            ),
            'tier' => 'episodic',
            'content' => sprintf(
                'Action "%s" expected "%s" and observed "%s". Outcome: %s. Match: %s. Repair: %s',
                $action->description,
                $action->expected,
                $observed,
                $status,
                $matchStatus,
                $repairNote
            ),
            'confidence' => 1.0,
            'status' => 'active',
            'source_event_id' => $event->id,
            'created_at' => $memoryTime,
            'updated_at' => $memoryTime,
        ]);
        $procedure = $this->proceduralMemory->observe($action, $event, $memory);

        return [
            'action' => $action->getData(),
            'event' => $event->getData(),
            'episodic_memory' => $memory->getData(),
            'procedure' => $procedure,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listActions(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['pending', 'succeeded', 'failed', 'cancelled'], 'status');
            $records = ActionTrace::getAllByWhere(
                ['status' => $status],
                ['order' => ['created_at' => 'DESC']]
            );
        } else {
            $records = ActionTrace::getAll(['order' => ['created_at' => 'DESC']]);
        }

        return $this->records($records);
    }

    /** @return array<string, mixed> */
    public function releaseCognitiveThreadByKey(string $threadKey, string $reason): array
    {
        $this->requireText($threadKey, 'thread key');
        $this->requireText($reason, 'release reason');

        return $this->transaction(function () use ($threadKey, $reason): array {
            $thread = CognitiveThread::getByField('thread_key', $threadKey);
            if (!$thread instanceof CognitiveThread) {
                throw new RuntimeException(sprintf('Cognitive thread %s does not exist.', $threadKey));
            }
            if ($thread->status === 'released') {
                return ['status' => 'released', 'thread' => $thread->getData(), 'deduplicated' => true];
            }

            $now = time();
            $cancelledWorkIds = [];
            $cancelledStepIds = [];
            foreach (ThreadStep::getAllByWhere(['thread_id' => $thread->id]) as $step) {
                if (!in_array($step->status, ['running', 'dispatching'], true)) {
                    continue;
                }
                $workId = (int) ($step->worker_work_item_id ?? 0);
                if ($workId > 0) {
                    $work = WorkItem::getByID($workId);
                    if ($work instanceof WorkItem && in_array($work->status, ['queued', 'leased'], true)) {
                        $work->setFields([
                            'completed_at' => $now,
                            'updated_at' => $now,
                            'status' => 'cancelled',
                            'lease_owner' => null,
                            'lease_expires_at' => null,
                            'error' => 'The user released the owning cognitive thread: ' . $reason,
                        ]);
                        $work->save();
                        $cancelledWorkIds[] = $workId;
                        $this->emit('work.cancelled', [
                            'work_item_id' => $workId,
                            'thread_id' => $thread->id,
                            'reason' => 'cognitive_thread_released',
                        ]);
                    }
                }
                $step->setFields([
                    'completed_at' => $now,
                    'observed_result' => ['choice' => 'release', 'reason' => $reason],
                    'post_state' => ['thread_phase' => 'released'],
                    'status' => 'cancelled',
                    'error' => 'The user released the cognitive thread.',
                ]);
                $step->save();
                $cancelledStepIds[] = (int) $step->id;
            }

            $thread->setFields([
                'phase' => 'released',
                'wake_at' => null,
                'status' => 'released',
                'version' => (int) $thread->version + 1,
                'fencing_token' => (int) $thread->fencing_token + 1,
                'last_observation' => $reason,
                'updated_at' => $now,
            ]);
            $thread->save();
            $event = $this->emit('thread.released', [
                'thread_id' => $thread->id,
                'thread_key' => $thread->thread_key,
                'reason' => $reason,
                'cancelled_thread_step_ids' => $cancelledStepIds,
                'cancelled_work_item_ids' => $cancelledWorkIds,
            ]);

            return [
                'status' => 'released',
                'thread' => $thread->getData(),
                'cancelled_thread_step_ids' => $cancelledStepIds,
                'cancelled_work_item_ids' => $cancelledWorkIds,
                'event' => $event->getData(),
                'deduplicated' => false,
            ];
        });
    }

    public function addMemory(
        string $tier,
        string $content,
        float $confidence,
        string $idempotencyKey,
        ?int $sourceEventId = null,
        ?int $sourceMemoryId = null,
        ?int $supersedesId = null,
        ?string $expiresAt = null,
        bool $allowProceduralWrite = false
    ): array {
        $this->requireChoice($tier, ['working', 'episodic', 'semantic', 'procedural'], 'tier');
        $this->requireText($content, 'memory content');
        $this->requireUnitInterval($confidence, 'confidence');
        $this->requireText($idempotencyKey, 'memory idempotency key');

        if ($tier === 'procedural' && !$allowProceduralWrite) {
            throw new RuntimeException('Procedural memory writes require --allow-procedural-write.');
        }
        if ($tier === 'semantic' && $sourceEventId === null && $sourceMemoryId === null) {
            throw new RuntimeException('Semantic memories require a source event or source memory.');
        }
        if ($sourceEventId !== null) {
            $this->requireEvent($sourceEventId);
        }
        if ($sourceMemoryId !== null) {
            $this->requireMemory($sourceMemoryId);
        }
        $superseded = $supersedesId !== null ? $this->requireMemory($supersedesId) : null;

        $request = [
            'tier' => $tier,
            'content' => $content,
            'confidence' => $confidence,
            'source_event_id' => $sourceEventId,
            'source_memory_id' => $sourceMemoryId,
            'supersedes_id' => $supersedesId,
            'expires_at' => $expiresAt,
        ];
        $operationKey = TokenMemoryDaemon::operationKey('memory-add', $idempotencyKey);
        $operation = $this->claimMemoryStoreOperation(
            $operationKey,
            hash('sha256', $this->canonicalJson($request))
        );
        $requestedAt = (int) $operation['requested_at'];
        $fields = [
            'tier' => $tier,
            'content' => $content,
            'confidence' => $confidence,
            'status' => 'active',
            'source_event_id' => $sourceEventId,
            'source_memory_id' => $sourceMemoryId,
            'supersedes_id' => $supersedesId,
            'expires_at' => $expiresAt,
            'created_at' => $requestedAt,
            'updated_at' => $requestedAt,
        ];
        if ($superseded instanceof Memory) {
            $memory = Memory::replaceRecord(
                $superseded,
                $fields,
                'superseded',
                $operationKey
            );
        } else {
            $fields['operation_key'] = $operationKey;
            /** @var Memory $memory */
            $memory = $this->insert(Memory::class, $fields);
        }

        $event = $this->transaction(function () use (
            $operationKey,
            $memory,
            $tier,
            $sourceEventId,
            $sourceMemoryId,
            $supersedesId
        ): Event {
            $current = $this->memoryStoreOperation($operationKey);
            if ($current === null) {
                throw new RuntimeException('Memory store operation journal disappeared.');
            }
            if ($current['memory_id'] !== null
                && (int) $current['memory_id'] !== (int) $memory->id
            ) {
                throw new RuntimeException('Memory store operation resolved to a different memory.');
            }
            $event = $this->emitOnce(
                'memory.stored:' . (int) $memory->id,
                'memory.stored',
                [
                    'memory_id' => (int) $memory->id,
                    'tier' => $tier,
                    'source_event_id' => $sourceEventId,
                    'source_memory_id' => $sourceMemoryId,
                    'supersedes_id' => $supersedesId,
                ]
            );
            $update = $this->connection->prepare(
                'UPDATE memory_store_operations
                 SET memory_id = :memory_id, event_id = :event_id
                 WHERE operation_key = :operation_key'
            );
            $update->execute([
                'memory_id' => (int) $memory->id,
                'event_id' => (int) $event->id,
                'operation_key' => $operationKey,
            ]);
            if ($tier === 'semantic' && $sourceMemoryId !== null) {
                $ledger = MemoryConsolidationEpisode::getByField(
                    'episode_id',
                    $sourceMemoryId
                );
                if ($ledger instanceof MemoryConsolidationEpisode) {
                    $ledger->setFields([
                        'work_item_id' => null,
                        'semantic_memory_id' => (int) $memory->id,
                        'status' => 'consolidated',
                        'reason' => 'active_semantic_provenance_committed',
                        'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
                        'updated_at' => time(),
                    ]);
                    $ledger->save();
                }
            }
            return $event;
        });

        return ['memory' => $memory->getData(), 'event' => $event->getData()];
    }

    /**
     * @return array{operation_key: string, request_hash: string, requested_at: int, memory_id: ?int, event_id: ?int}
     */
    private function claimMemoryStoreOperation(string $operationKey, string $requestHash): array
    {
        try {
            return $this->transaction(function () use ($operationKey, $requestHash): array {
                $existing = $this->memoryStoreOperation($operationKey);
                if ($existing !== null) {
                    if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                        throw new RuntimeException('Memory idempotency key was reused with different input.');
                    }
                    return $existing;
                }
                $requestedAt = time();
                $insert = $this->connection->prepare(
                    'INSERT INTO memory_store_operations
                     (operation_key, request_hash, requested_at, memory_id, event_id)
                     VALUES (:operation_key, :request_hash, :requested_at, NULL, NULL)'
                );
                $insert->execute([
                    'operation_key' => $operationKey,
                    'request_hash' => $requestHash,
                    'requested_at' => $requestedAt,
                ]);
                return [
                    'operation_key' => $operationKey,
                    'request_hash' => $requestHash,
                    'requested_at' => $requestedAt,
                    'memory_id' => null,
                    'event_id' => null,
                ];
            });
        } catch (Throwable $throwable) {
            $existing = $this->memoryStoreOperation($operationKey);
            if ($existing === null) {
                throw $throwable;
            }
            if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new RuntimeException('Memory idempotency key was reused with different input.');
            }
            return $existing;
        }
    }

    /**
     * @return array{operation_key: string, request_hash: string, requested_at: int, memory_id: ?int, event_id: ?int}|null
     */
    private function memoryStoreOperation(string $operationKey): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT operation_key, request_hash, requested_at, memory_id, event_id
             FROM memory_store_operations WHERE operation_key = :operation_key'
        );
        $statement->execute(['operation_key' => $operationKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (!is_array($row)) {
            return null;
        }
        return [
            'operation_key' => (string) $row['operation_key'],
            'request_hash' => (string) $row['request_hash'],
            'requested_at' => (int) $row['requested_at'],
            'memory_id' => $row['memory_id'] === null ? null : (int) $row['memory_id'],
            'event_id' => $row['event_id'] === null ? null : (int) $row['event_id'],
        ];
    }

    public function consolidateMemory(int $episodeId, string $semanticContent, float $confidence): array
    {
        $episode = $this->requireMemory($episodeId);
        if ($episode->tier !== 'episodic') {
            throw new RuntimeException('Only episodic memories can be consolidated by this command.');
        }

        $stored = $this->addMemory(
            tier: 'semantic',
            content: $semanticContent,
            confidence: $confidence,
            idempotencyKey: 'manual-consolidate:' . $episodeId . ':' . hash('sha256', $semanticContent),
            sourceEventId: $episode->source_event_id,
            sourceMemoryId: $episodeId
        );
        return $this->transaction(function () use ($episodeId, $stored): array {
            $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
            if ($entry instanceof MemoryConsolidationEpisode) {
                $entry->setFields([
                    'work_item_id' => null,
                    'semantic_memory_id' => (int) ($stored['memory']['id'] ?? 0),
                    'status' => 'consolidated',
                    'reason' => 'manually_consolidated',
                    'updated_at' => time(),
                ]);
                $entry->save();
            }
            return $stored;
        });
    }

    /**
     * Keep a small, self-draining consolidation queue alive.
     *
     * The ledger is the source of truth: every episode is pending, queued,
     * consolidated, rejected with a reason, or excluded as Navi's own output.
     * Queue depth is bounded so catch-up work cannot bury addressed speech.
     *
     * @return array<string, mixed>
     */
    public function maintainConsolidationQueue(int $targetDepth = 2): array
    {
        if ($targetDepth < 1 || $targetDepth > 8) {
            throw new InvalidArgumentException('consolidation queue depth must be between 1 and 8.');
        }

        return $this->transaction(function () use ($targetDepth): array {
            $recovered = $this->recoverConsolidationAssignments();
            $this->synchronizeConsolidationEpisodes();
            $queued = [];

            while ($this->activeConsolidationWorkCount() < $targetDepth) {
                $work = $this->enqueueConsolidation(null, time(), false);
                if ($work === null) {
                    break;
                }
                $queued[] = $work;
            }

            return [
                'status' => $queued === [] ? 'steady' : 'queued',
                'target_depth' => $targetDepth,
                'active_work_items' => $this->activeConsolidationWorkCount(),
                'queued_batches' => array_values(array_filter(array_map(
                    static fn (array $item): ?int => isset($item['work_item']['id'])
                        ? (int) $item['work_item']['id']
                        : null,
                    $queued
                ))),
                'recovered' => $recovered,
                'ledger' => $this->consolidationLedgerCounts(),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function consolidationStatus(): array
    {
        $cursorQuery = $this->connection->query(
            'SELECT COALESCE(MAX(episode_id), 0) FROM memory_consolidation_episodes'
        );
        $ledgerCursor = (int) ($cursorQuery?->fetchColumn() ?: 0);
        $cursorQuery?->closeCursor();
        $activeEpisodes = Memory::inspectCount('episodic', 'active');
        // Episodic IDs are append-only and the daemon forbids reactivating a
        // retired episodic record. Synchronization can therefore advance in
        // ascending ID pages: active IDs beyond the ledger maximum are exactly
        // the bounded backlog without hydrating either corpus.
        $untrackedActiveEpisodes = Memory::inspectCount(
            'episodic',
            'active',
            $ledgerCursor
        );
        $ledger = $this->consolidationLedgerCounts();
        return [
            'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
            'active_work_items' => $this->activeConsolidationWorkCount(),
            'active_episodes' => $activeEpisodes,
            'ledger_cursor' => $ledgerCursor,
            'tracked_active_episodes' => max(0, $activeEpisodes - $untrackedActiveEpisodes),
            'untracked_active_episodes' => $untrackedActiveEpisodes,
            'ledger' => $ledger,
        ];
    }

    /**
     * Quarantine outputs produced before source partitioning and grounding
     * existed. The derived rows and their events remain available for audit;
     * only active recall is changed. Their source episodes remain untouched and
     * enter the new ledger for a clean replay.
     *
     * @return array<string, mixed>
     */
    public function repairConsolidationHistory(string $reason): array
    {
        $this->requireText($reason, 'consolidation repair reason');

        return (function () use ($reason): array {
            $derivedIds = [];
            foreach (Event::getAllByWhere(['kind' => 'memory.consolidated']) as $event) {
                $payload = is_array($event->payload) ? $event->payload : [];
                if ((int) ($payload['validator_version'] ?? 0) >= self::CONSOLIDATION_MEMORY_FLOOR) {
                    continue;
                }
                $memoryId = (int) ($payload['memory_id'] ?? 0);
                if ($memoryId > 0) {
                    $derivedIds[$memoryId] = true;
                }
            }

            $parentIds = [];
            $quarantined = [];
            foreach (array_keys($derivedIds) as $memoryId) {
                $memory = Memory::inspectByID($memoryId);
                if (!$memory instanceof Memory || $memory->tier !== 'semantic') {
                    continue;
                }
                if ($memory->supersedes_id !== null) {
                    $parentIds[(int) $memory->supersedes_id] = true;
                }
                if ($memory->status !== 'quarantined') {
                    $memory->setFields(['status' => 'quarantined', 'updated_at' => time()]);
                    $memory->saveWithOperation(TokenMemoryDaemon::operationKey(
                        'repair-consolidation-quarantine',
                        $memoryId . ':' . hash('sha256', $reason)
                    ));
                }
                $quarantined[] = $memoryId;
            }

            $activelySuperseded = [];
            foreach (Memory::inspectAllByWhere(['tier' => 'semantic', 'status' => 'active']) as $semantic) {
                if ($semantic->supersedes_id !== null) {
                    $activelySuperseded[(int) $semantic->supersedes_id] = true;
                }
            }
            $restored = [];
            foreach (array_keys($parentIds) as $parentId) {
                if (isset($derivedIds[$parentId]) || isset($activelySuperseded[$parentId])) {
                    continue;
                }
                $parent = Memory::inspectByID($parentId);
                if ($parent instanceof Memory && $parent->tier === 'semantic' && $parent->status === 'superseded') {
                    $parent->setFields(['status' => 'active', 'updated_at' => time()]);
                    $parent->saveWithOperation(TokenMemoryDaemon::operationKey(
                        'repair-consolidation-restore',
                        $parentId . ':' . hash('sha256', $reason)
                    ));
                    $restored[] = $parentId;
                }
            }

            $unsafeBatchRepair = $this->repairUnsafeConsolidationBatches($reason);
            $this->synchronizeConsolidationEpisodes();
            $event = $this->emit('memory.consolidation.history_repaired', [
                'reason' => $reason,
                'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
                'quarantined_memory_ids' => $quarantined,
                'restored_memory_ids' => $restored,
                'unsafe_batch_repair' => $unsafeBatchRepair,
                'source_episodes_preserved' => true,
            ]);

            return [
                'status' => 'repaired',
                'quarantined_memory_ids' => $quarantined,
                'restored_memory_ids' => $restored,
                'unsafe_batch_repair' => $unsafeBatchRepair,
                'ledger' => $this->consolidationLedgerCounts(),
                'event' => $event->getData(),
            ];
        })();
    }

    /** @return array<string, mixed> */
    private function repairUnsafeConsolidationBatches(string $reason): array
    {
        $unsafeWorkIds = [];
        $cancelledWorkIds = [];
        $episodeIds = [];
        $now = time();
        foreach (WorkItem::getAllByWhere(['work_type' => self::MEMORY_CONSOLIDATION_WORK_TYPE]) as $work) {
            $refs = is_array($work->input_refs) ? $work->input_refs : [];
            $validatorVersion = (int) ($refs['validator_version'] ?? 0);
            $workspaceLeak = $validatorVersion >= 2 && isset($refs['working_memory_checksum']);
            $obsoleteValidator = $validatorVersion > 0
                && $validatorVersion < self::CONSOLIDATION_VALIDATOR_VERSION;
            if (!$workspaceLeak && !$obsoleteValidator) {
                continue;
            }
            $workId = (int) $work->id;
            $unsafeWorkIds[] = $workId;
            foreach ($this->consolidationIdList($refs['episode_ids'] ?? []) as $episodeId) {
                $episodeIds[$episodeId] = true;
            }
            if (!in_array($work->status, ['queued', 'leased'], true)) {
                continue;
            }
            $work->setFields([
                'completed_at' => $now,
                'updated_at' => $now,
                'status' => 'cancelled',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'error' => 'Cancelled because the consolidation batch predates the current evidence fence: ' . $reason,
            ]);
            $work->save();
            $cancelledWorkIds[] = $workId;
            $this->emit('work.cancelled', [
                'work_item_id' => $workId,
                'reason' => $workspaceLeak
                    ? 'consolidation_workspace_prompt_leak'
                    : 'obsolete_consolidation_validator',
            ]);
        }

        $unsafeWork = array_fill_keys($unsafeWorkIds, true);
        $resetEpisodeIds = [];
        foreach (array_keys($episodeIds) as $episodeId) {
            $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
            if (!$entry instanceof MemoryConsolidationEpisode
                || !in_array($entry->status, ['pending', 'queued'], true)
            ) {
                continue;
            }
            $assignedUnsafeWork = $entry->work_item_id !== null
                && isset($unsafeWork[(int) $entry->work_item_id]);
            if (!$assignedUnsafeWork
                && (int) $entry->validator_version >= self::CONSOLIDATION_VALIDATOR_VERSION
            ) {
                continue;
            }
            $entry->setFields([
                'work_item_id' => null,
                'status' => 'pending',
                'attempts' => 0,
                'reason' => 'requeued_after_unsafe_batch_repair',
                'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
                'updated_at' => $now,
            ]);
            $entry->save();
            $resetEpisodeIds[] = $episodeId;
        }

        return [
            'unsafe_work_item_ids' => $unsafeWorkIds,
            'cancelled_work_item_ids' => $cancelledWorkIds,
            'reset_episode_ids' => $resetEpisodeIds,
        ];
    }

    /** @return array<string, int> */
    private function consolidationLedgerCounts(): array
    {
        $counts = [
            'pending' => 0,
            'queued' => 0,
            'consolidated' => 0,
            'rejected' => 0,
            'excluded' => 0,
        ];
        $statement = $this->connection->query(
            'SELECT status, COUNT(*) AS total
             FROM memory_consolidation_episodes
             GROUP BY status'
        );
        foreach ($statement?->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) ($row['total'] ?? 0);
            }
        }
        $statement?->closeCursor();
        $counts['total'] = array_sum($counts);
        return $counts;
    }

    private function activeConsolidationWorkCount(): int
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM work_items
             WHERE work_type = :work_type AND status IN ('queued', 'leased')"
        );
        $statement->execute(['work_type' => self::MEMORY_CONSOLIDATION_WORK_TYPE]);
        $count = (int) ($statement->fetchColumn() ?: 0);
        $statement->closeCursor();
        return $count;
    }

    private function synchronizeConsolidationEpisodes(): void
    {
        // This cursor is correct only with the daemon's one-way episodic status
        // invariant: once an episodic record leaves active recall it cannot
        // later appear behind the high-water mark.
        $cursorQuery = $this->connection->query(
            'SELECT COUNT(*), COALESCE(MAX(episode_id), 0) FROM memory_consolidation_episodes'
        );
        $cursor = $cursorQuery?->fetch(PDO::FETCH_NUM);
        $cursorQuery?->closeCursor();
        $ledgerCount = is_array($cursor) ? (int) ($cursor[0] ?? 0) : 0;
        $afterId = is_array($cursor) ? (int) ($cursor[1] ?? 0) : 0;
        $existingSemanticByMemory = [];
        $existingSemanticByEvent = [];
        if ($ledgerCount === 0) {
            // One-time migration bootstrap. Later scheduler passes are bounded
            // ID pages and semantic writes update their source ledger directly.
            foreach (Memory::inspectAllByWhere(['tier' => 'semantic', 'status' => 'active']) as $semantic) {
                if ($semantic->source_memory_id !== null) {
                    $existingSemanticByMemory[(int) $semantic->source_memory_id] = (int) $semantic->id;
                }
                if ($semantic->source_event_id !== null) {
                    $sourceEventId = (int) $semantic->source_event_id;
                    $existingSemanticByEvent[$sourceEventId] ??= (int) $semantic->id;
                }
            }
        }

        $episodes = $ledgerCount === 0
            ? Memory::inspectAllByWhere(
                ['tier' => 'episodic', 'status' => 'active'],
                ['order' => ['id' => 'ASC']]
            )
            : Memory::inspectPage($afterId, 100, 'episodic', 'active');
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }
        try {
            foreach ($episodes as $episode) {
                $episodeId = (int) $episode->id;
                $semanticId = $existingSemanticByMemory[$episodeId]
                    ?? ($episode->source_event_id === null
                        ? null
                        : ($existingSemanticByEvent[(int) $episode->source_event_id] ?? null));
                if ($semanticId === null && $ledgerCount !== 0) {
                    $semantic = Memory::inspectProvenance(
                        'memory',
                        $episodeId,
                        'semantic',
                        'active'
                    );
                    if (!$semantic instanceof Memory && $episode->source_event_id !== null) {
                        $semantic = Memory::inspectProvenance(
                            'event',
                            (int) $episode->source_event_id,
                            'semantic',
                            'active'
                        );
                    }
                    if ($semantic instanceof Memory) {
                        $semanticId = (int) $semantic->id;
                    }
                }

                $status = 'pending';
                $reason = null;
                if ($semanticId !== null) {
                    $status = 'consolidated';
                    $reason = 'already_has_active_semantic_provenance';
                } elseif (!$this->isEvidence((string) $episode->content)) {
                    $status = 'excluded';
                    $reason = 'agent_output_is_not_external_evidence';
                } elseif ($this->isInsufficientUserFragment((string) $episode->content)) {
                    $status = 'rejected';
                    $reason = 'user_utterance_fragment_has_insufficient_context';
                }

                $this->insert(MemoryConsolidationEpisode::class, [
                    'episode_id' => $episodeId,
                    'semantic_memory_id' => $semanticId,
                    'status' => $status,
                    'attempts' => 0,
                    'reason' => $reason,
                    'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
                    'updated_at' => time(),
                ]);
            }
            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $throwable;
        }
    }

    private function isInsufficientUserFragment(string $content): bool
    {
        if (!str_starts_with($content, 'The user said:')) {
            return false;
        }
        $said = trim(substr($content, strlen('The user said:')));
        return count($this->consolidationTokens($said)) < 3;
    }

    /** @return list<array<string, mixed>> */
    private function recoverConsolidationAssignments(): array
    {
        $recovered = [];
        $statement = $this->connection->query(
            "SELECT DISTINCT work_item_id FROM memory_consolidation_episodes
             WHERE status = 'queued' AND work_item_id IS NOT NULL
             ORDER BY work_item_id ASC"
        );
        $workIds = array_map('intval', $statement?->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $statement?->closeCursor();

        foreach ($workIds as $workId) {
            $work = WorkItem::getByID($workId);
            if (!$work instanceof WorkItem) {
                $recovered[] = $this->rejectConsolidationAttempt(
                    ['id' => $workId, 'input_refs' => []],
                    'assigned_work_item_missing'
                );
                continue;
            }
            if ($work->status === 'completed') {
                $result = is_array($work->result) ? $work->result : [];
                $recovered[] = $result === []
                    ? $this->rejectConsolidationAttempt($work->getData(), 'completed_work_has_no_result')
                    : $this->integrateConsolidation($work->getData(), $result, (string) $work->model);
            } elseif (in_array($work->status, ['failed', 'cancelled'], true)) {
                $recovered[] = $this->rejectConsolidationAttempt(
                    $work->getData(),
                    'assigned_work_' . (string) $work->status
                );
            }
        }
        return $recovered;
    }

    /**
     * Queue one topically coherent replay batch. Oldest pending always seeds
     * the batch, so continuous arrivals cannot starve history.
     *
     * @return array<string, mixed>|null
     */
    private function enqueueConsolidation(
        ?int $runId,
        int $now,
        bool $synchronize = true
    ): ?array
    {
        return $this->transaction(function () use ($runId, $now, $synchronize): ?array {
            if ($synchronize) {
                $this->synchronizeConsolidationEpisodes();
            }
            $statement = $this->connection->prepare(
                "SELECT id, episode_id FROM memory_consolidation_episodes
                 WHERE status = 'pending'
                 ORDER BY episode_id ASC
                 LIMIT :window"
            );
            $statement->bindValue('window', self::CONSOLIDATION_PENDING_WINDOW, PDO::PARAM_INT);
            $statement->execute();
            $pendingRows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            $pendingEntries = [];
            foreach ($pendingRows as $row) {
                $entry = MemoryConsolidationEpisode::getByID((int) $row['id']);
                if ($entry instanceof MemoryConsolidationEpisode) {
                    $pendingEntries[(int) $row['episode_id']] = $entry;
                }
            }
            $episodesById = [];
            foreach (Memory::getManyByID(array_keys($pendingEntries), true) as $episode) {
                $episodesById[(int) $episode->id] = $episode;
            }
            $pending = [];
            foreach ($pendingEntries as $episodeId => $entry) {
                $episode = $episodesById[$episodeId] ?? null;
                if (!$episode instanceof Memory || $episode->tier !== 'episodic' || $episode->status !== 'active') {
                    $entry->setFields([
                        'status' => 'rejected',
                        'reason' => 'episode_missing_or_inactive_before_replay',
                        'updated_at' => $now,
                    ]);
                    $entry->save();
                    continue;
                }
                $pending[(int) $episode->id] = $episode;
            }
            if ($pending === []) {
                return null;
            }

            $fresh = [$pending[array_key_first($pending)]];
            $seed = $fresh[0];
            $ranked = [];
            foreach ($pending as $episodeId => $episode) {
                if ($episodeId === (int) $seed->id) {
                    continue;
                }
                $score = $this->consolidationSimilarity($seed, $episode);
                if ($score > 0.0) {
                    $ranked[] = ['score' => $score, 'episode' => $episode];
                }
            }
            usort($ranked, static function (array $left, array $right): int {
                $score = $right['score'] <=> $left['score'];
                return $score !== 0
                    ? $score
                    : ((int) $left['episode']->id <=> (int) $right['episode']->id);
            });
            foreach (array_slice($ranked, 0, self::CONSOLIDATION_NEW - 1) as $candidate) {
                $fresh[] = $candidate['episode'];
            }

            $interleaved = [];
            $interleavedEntries = MemoryConsolidationEpisode::getAllByWhere(
                ['status' => 'consolidated'],
                ['order' => ['episode_id' => 'DESC'], 'limit' => 300]
            );
            $interleavedById = [];
            foreach (Memory::getManyByID(array_map(
                static fn (MemoryConsolidationEpisode $entry): int => (int) $entry->episode_id,
                $interleavedEntries
            ), true) as $episode) {
                $interleavedById[(int) $episode->id] = $episode;
            }
            foreach ($interleavedEntries as $entry) {
                $episode = $interleavedById[(int) $entry->episode_id] ?? null;
                if (!$episode instanceof Memory || $this->consolidationSimilarity($seed, $episode) <= 0.0) {
                    continue;
                }
                $interleaved[] = $episode;
                if (count($interleaved) >= self::CONSOLIDATION_INTERLEAVED) {
                    break;
                }
            }

            $subject = implode(' ', array_map(
                fn (Memory $episode): string => $this->consolidationEvidenceText($episode),
                array_slice($fresh, 0, 3)
            ));
            $existing = $this->consolidationExistingKnowledge($subject);

            $freshEvidence = array_map(fn (Memory $episode): array => [
                'id' => (int) $episode->id,
                'evidence' => $this->consolidationEvidenceText($episode),
            ], $fresh);
            $comparisonEvidence = array_map(fn (Memory $episode): array => [
                'id' => (int) $episode->id,
                'evidence' => $this->consolidationEvidenceText($episode),
            ], $interleaved);
            $episodeIds = array_column($freshEvidence, 'id');
            $evidenceHashes = [];
            foreach ($freshEvidence as $evidence) {
                $evidenceHashes[(string) $evidence['id']] = hash('sha256', (string) $evidence['evidence']);
            }

            $composition = new ExecutiveComposition(
                'Consolidate related episodes into at most one durable claim, with exact source accounting.'
            );
            $composition->contribute(
                'fresh_evidence',
                implode(' ', [
                    'Only these IDs may support the new claim.',
                    'Observed results are evidence; intentions, expected outcomes, questions, and failed-command rationales are not.',
                    'Use source wording for concrete names, paths, versions, flags, quantities, and quoted text.',
                ]),
                $freshEvidence
            );
            if ($comparisonEvidence !== []) {
                $composition->contribute(
                    'comparison_only',
                    'These older episodes may expose contradictions, but their IDs cannot be listed as support.',
                    $comparisonEvidence
                );
            }
            $prompt = $composition->prompt() . "\n\n" . implode("\n", [
                'Return kind memory_consolidation and put the claim itself in content.',
                'Return supported_episode_ids and rejected_episode_ids as arrays of integer IDs.',
                sprintf(
                    'Those two arrays may contain only fresh evidence IDs %s; no other integer is valid in either array.',
                    json_encode($episodeIds, JSON_THROW_ON_ERROR)
                ),
                'Return rejection_reason as a string and supersedes_memory_id as either an integer ID or null.',
                'supported_episode_ids and rejected_episode_ids must be disjoint and together contain every fresh evidence ID exactly once.',
                'A claim may use only supported fresh evidence. Comparison episodes cannot support it.',
                'If no durable claim is warranted, use empty content, no supported IDs, reject every fresh ID, and explain why.',
                'When no fresh ID is rejected, set rejection_reason to "none".',
                'supersedes_memory_id must be null. Related existing memories are resolved deterministically after grounding.',
                'Put calibrated confidence in confidence and the tested belief in challenged_assumption.',
                'Output exactly the eight JSON fields in the supplied schema and nothing else.',
            ]);

            $attempt = 1;
            foreach ($fresh as $episode) {
                $entry = MemoryConsolidationEpisode::getByField('episode_id', (int) $episode->id);
                if ($entry instanceof MemoryConsolidationEpisode) {
                    $attempt = max($attempt, (int) $entry->attempts + 1);
                }
            }
            $batchHash = substr(hash('sha256', implode(',', $episodeIds)), 0, 16);
            $queued = $this->enqueueWork(
                parentRunId: $runId,
                parentIntentionId: null,
                workType: self::MEMORY_CONSOLIDATION_WORK_TYPE,
                prompt: $prompt,
                inputRefs: [
                    'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
                    'episode_ids' => $episodeIds,
                    'interleaved_ids' => array_column($comparisonEvidence, 'id'),
                    'existing_memory_ids' => array_column($existing, 'id'),
                    'evidence_hashes' => $evidenceHashes,
                ],
                tokenBudget: 512,
                wallBudgetSeconds: 300,
                idempotencyKey: sprintf('consolidate:v%d:%d:%d:%s:%s',
                    self::CONSOLIDATION_VALIDATOR_VERSION,
                    (int) $seed->id,
                    $attempt,
                    $batchHash,
                    bin2hex(random_bytes(4))
                )
            );
            $workId = (int) ($queued['work_item']['id'] ?? 0);
            if ($workId < 1) {
                throw new RuntimeException('Consolidation queue did not return a work item ID.');
            }
            foreach ($fresh as $episode) {
                $entry = MemoryConsolidationEpisode::getByField('episode_id', (int) $episode->id);
                if (!$entry instanceof MemoryConsolidationEpisode) {
                    throw new RuntimeException('Consolidation ledger lost a queued episode.');
                }
                $entry->setFields([
                    'work_item_id' => $workId,
                    'status' => 'queued',
                    'attempts' => (int) $entry->attempts + 1,
                    'reason' => null,
                    'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
                    'updated_at' => $now,
                ]);
                $entry->save();
            }
            $this->emit('memory.consolidation.batch.queued', [
                'work_item_id' => $workId,
                'episode_ids' => $episodeIds,
                'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
            ]);
            return $queued + ['episode_ids' => $episodeIds];
        });
    }

    private function consolidationSimilarity(Memory $left, Memory $right): float
    {
        $leftType = $this->consolidationEpisodeType((string) $left->content);
        if ($leftType !== $this->consolidationEpisodeType((string) $right->content)) {
            return 0.0;
        }
        $leftEvidence = $this->consolidationEvidenceText($left);
        $rightEvidence = $this->consolidationEvidenceText($right);
        if ($this->normalizeSearchText($leftEvidence) === $this->normalizeSearchText($rightEvidence)) {
            return 10.0;
        }
        $leftCommand = $this->consolidationCommand($leftEvidence);
        $rightCommand = $this->consolidationCommand($rightEvidence);
        if ($leftCommand !== null && $leftCommand === $rightCommand) {
            return 8.0;
        }
        $leftTokens = array_flip($this->consolidationTokens($leftEvidence));
        $rightTokens = array_flip($this->consolidationTokens($rightEvidence));
        $minimum = min(count($leftTokens), count($rightTokens));
        if ($minimum === 0) {
            return 0.0;
        }
        $shared = count(array_intersect_key($leftTokens, $rightTokens));
        if ($shared < 3) {
            return 0.0;
        }
        $containment = $shared / $minimum;
        $union = count($leftTokens + $rightTokens);
        $jaccard = $union === 0 ? 0.0 : $shared / $union;
        return $containment >= 0.35 && $jaccard >= 0.2
            ? $jaccard
            : 0.0;
    }

    private function consolidationEpisodeType(string $content): string
    {
        return match (true) {
            str_starts_with($content, 'On this machine '),
            str_starts_with($content, 'Looking at this machine ') => 'machine_observation',
            str_starts_with($content, 'Action "') => 'action_outcome',
            str_starts_with($content, 'The user said:') => 'user_utterance',
            str_starts_with($content, 'Session in '),
            str_starts_with($content, 'Automatic Navi continuity '),
            str_starts_with($content, 'Implemented and verified ') => 'session_record',
            default => 'observation',
        };
    }

    private function consolidationEvidenceText(Memory $episode): string
    {
        $content = trim((string) $episode->content);
        if (str_starts_with($content, 'On this machine "')) {
            $answers = strpos($content, '" answers ');
            $returned = strrpos($content, '. It returned: ');
            if ($answers !== false && $returned !== false && $returned > $answers) {
                $command = substr($content, strlen('On this machine "'), $answers - strlen('On this machine "'));
                return sprintf(
                    'Command "%s" succeeded. Observed output: %s',
                    $command,
                    substr($content, $returned + strlen('. It returned: '))
                );
            }
            $failed = strpos($content, '" does not work as a way to find out ');
            $exited = strrpos($content, '. It exited ');
            if ($failed !== false && $exited !== false && $exited > $failed) {
                $command = substr($content, strlen('On this machine "'), $failed - strlen('On this machine "'));
                return sprintf(
                    'Command "%s" failed. Observed result: %s',
                    $command,
                    substr($content, $exited + strlen('. It exited '))
                );
            }
        }
        if (str_starts_with($content, 'Action "')) {
            $observed = strpos($content, ' and observed "');
            $outcome = strrpos($content, '". Outcome: ');
            if ($observed !== false && $outcome !== false && $outcome > $observed) {
                return 'Action observation: '
                    . substr($content, $observed + strlen(' and observed "'), $outcome - ($observed + strlen(' and observed "')))
                    . '. Outcome: ' . substr($content, $outcome + strlen('". Outcome: '));
            }
        }
        return mb_substr($content, 0, 800);
    }

    private function consolidationCommand(string $evidence): ?string
    {
        if (!str_starts_with($evidence, 'Command "')) {
            return null;
        }
        $end = strpos($evidence, '" ', strlen('Command "'));
        return $end === false
            ? null
            : $this->normalizeSearchText(substr($evidence, strlen('Command "'), $end - strlen('Command "')));
    }

    /** @return list<string> */
    private function consolidationTokens(string $content): array
    {
        $stop = array_flip([
            'about', 'after', 'again', 'also', 'because', 'been', 'before', 'being', 'could', 'does',
            'from', 'have', 'into', 'just', 'more', 'most', 'only', 'other', 'should', 'that', 'their',
            'there', 'these', 'they', 'this', 'through', 'user', 'what', 'when', 'where', 'which', 'while',
            'with', 'would', 'your', 'action', 'command', 'episode', 'observed', 'observation', 'outcome',
            'result', 'returned', 'said', 'succeeded', 'failed',
        ]);
        $tokens = [];
        foreach (preg_split('/[^\p{L}\p{N}_=.\/-]+/u', mb_strtolower($content)) ?: [] as $token) {
            $token = trim($token, './-_=');
            if (mb_strlen($token) < 3 || isset($stop[$token])) {
                continue;
            }
            $tokens[$token] = true;
        }
        return array_keys($tokens);
    }

    /** @return list<array{id: int, claim: string}> */
    private function consolidationExistingKnowledge(string $subject): array
    {
        $subjectTokens = array_flip($this->consolidationTokens($subject));
        if ($subjectTokens === []) {
            return [];
        }

        $ranked = [];
        foreach (Memory::rankCandidates($subject, 100, 'semantic', 'active') as $candidate) {
            $claimTokens = array_flip($this->consolidationTokens((string) $candidate->content));
            $shared = count(array_intersect_key($subjectTokens, $claimTokens));
            if ($shared < 3) {
                continue;
            }
            $ranked[] = [
                'id' => (int) $candidate->id,
                'claim' => (string) $candidate->content,
                'shared' => $shared,
                'containment' => $shared / max(1, count($claimTokens)),
            ];
        }
        usort($ranked, static function (array $left, array $right): int {
            $shared = $right['shared'] <=> $left['shared'];
            if ($shared !== 0) {
                return $shared;
            }
            $containment = $right['containment'] <=> $left['containment'];
            return $containment !== 0
                ? $containment
                : ($right['id'] <=> $left['id']);
        });

        $selected = array_slice($ranked, 0, self::CONSOLIDATION_EXISTING);
        Memory::observeRecords(Memory::getManyByID(
            array_map(static fn (array $candidate): int => (int) $candidate['id'], $selected),
            false
        ));
        return array_map(
            static fn (array $candidate): array => [
                'id' => $candidate['id'],
                'claim' => $candidate['claim'],
            ],
            $selected
        );
    }

    /** @param list<int> $candidateIds */
    private function consolidationCoveredByExisting(string $claim, array $candidateIds): ?Memory
    {
        $claimTokens = array_flip($this->consolidationTokens($claim));
        if (count($claimTokens) < 3) {
            return null;
        }

        $best = null;
        $bestCoverage = 0.0;
        $candidates = Memory::getManyByID($candidateIds, false);
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof Memory || $candidate->tier !== 'semantic' || $candidate->status !== 'active') {
                continue;
            }
            $candidateTokens = array_flip($this->consolidationTokens((string) $candidate->content));
            $shared = count(array_intersect_key($claimTokens, $candidateTokens));
            $coverage = $shared / count($claimTokens);
            if ($shared < 3 || $coverage < 0.8 || $coverage <= $bestCoverage) {
                continue;
            }
            $best = $candidate;
            $bestCoverage = $coverage;
        }
        if ($best instanceof Memory) {
            Memory::observeRecords([$best]);
        }
        return $best;
    }

    /**
     * How many words fit in the shortest and longest turn Navi may take.
     *
     * Words are the wrong unit to fix in code. The channel is a voice, so the
     * real quantity is how long Navi holds the floor, and the same number of
     * words is a different length of turn at a different speaking rate. So the
     * bounds are kept in seconds on the thread's own budget, where they can be
     * changed without touching this file, and converted using a rate measured
     * from lines Pet has actually spoken rather than one picked to look right.
     *
     * @return array{0: int, 1: int}
     */
    private function spokenWordRange(?CognitiveThread $thread = null): array
    {
        $thread ??= CognitiveThread::getByField('thread_key', self::SELF_PRESENCE_THREAD_KEY);
        $shortest = $thread instanceof CognitiveThread
            ? $this->threadBudgetInt($thread, 'turn_seconds_min', 4)
            : 4;
        $longest = $thread instanceof CognitiveThread
            ? $this->threadBudgetInt($thread, 'turn_seconds_max', 120)
            : 120;

        $rate = $this->measuredSpeakingRate();
        $floor = max(3, (int) round($shortest * $rate));
        $ceiling = max($floor + 1, (int) round($longest * $rate));
        return [$floor, $ceiling];
    }

    /**
     * Words per second, measured from lines Pet has actually spoken.
     *
     * Falls back to the rate implied by whatever has been said so far, and only
     * to a nominal figure when nothing has been timed yet — a first run should
     * not be blocked, but it should also not pretend to a measurement it has
     * not made.
     */
    private function measuredSpeakingRate(): float
    {
        $words = 0;
        $seconds = 0.0;
        foreach (Event::getAllByWhere(
            ['kind' => 'speech.timed'],
            ['order' => ['id' => 'DESC'], 'limit' => 40]
        ) as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $words += (int) ($payload['words'] ?? 0);
            $seconds += (float) ($payload['duration_seconds'] ?? 0.0);
        }
        if ($seconds <= 0.0 || $words <= 0) {
            return self::NOMINAL_SPEAKING_RATE;
        }
        return max(0.5, min(6.0, $words / $seconds));
    }

    /**
     * Ask to look at something on the machine.
     *
     * Queued rather than run, for the same reason every other sample is: a
     * sense must never be able to stall a wake, and the process that can
     * actually run commands is a different one with less authority.
     *
     * @return array<string, mixed>
     */
    public function requestLook(
        string $command,
        string $because,
        ?int $intentionId = null,
        ?int $actionId = null,
        ?int $procedureRunId = null,
        ?int $procedureStep = null,
        ?int $decisionCycleId = null
    ): array
    {
        $this->requireText($command, 'command');
        $this->requireText($because, 'reason for looking');
        if ($intentionId !== null) {
            $this->requireIntention($intentionId);
        }

        return $this->transaction(function () use (
            $command,
            $because,
            $intentionId,
            $actionId,
            $procedureRunId,
            $procedureStep,
            $decisionCycleId
        ): array {
            $execution = null;
            $run = null;
            if ($actionId !== null) {
                $execution = ActionExecution::getByField('action_trace_id', $actionId);
                $action = ActionTrace::getByID($actionId);
                $executionArguments = $execution instanceof ActionExecution
                    && is_array($execution->arguments)
                    ? $execution->arguments
                    : [];
                if (!$execution instanceof ActionExecution
                    || !$action instanceof ActionTrace
                    || (string) $execution->status !== 'dispatching'
                    || (string) $execution->action_kind !== 'machine.look'
                    || $intentionId === null
                    || (int) $action->intention_id !== $intentionId
                    || !hash_equals((string) ($executionArguments['command'] ?? ''), $command)
                    || !hash_equals((string) ($executionArguments['because'] ?? ''), $because)
                ) {
                    throw new RuntimeException('Look request does not own the supplied action execution.');
                }
            }
            if ($procedureRunId !== null || $procedureStep !== null) {
                if (!$execution instanceof ActionExecution
                    || $procedureRunId === null
                    || $procedureStep === null
                    || (int) ($execution->procedure_run_id ?? 0) !== $procedureRunId
                    || (int) ($execution->step_index ?? 0) !== $procedureStep + 1
                ) {
                    throw new RuntimeException('Procedure look request has an incomplete or crossed run-step identity.');
                }
                $run = ProcedureRun::getByID($procedureRunId);
                $runProcedure = $run instanceof ProcedureRun
                    ? Procedure::getByID((int) $run->procedure_id)
                    : null;
                $stepProcedure = Procedure::getByID((int) ($execution->procedure_id ?? 0));
                $runSteps = $runProcedure instanceof Procedure && is_array($runProcedure->steps)
                    ? $runProcedure->steps
                    : [];
                $runStep = $runSteps[$procedureStep] ?? null;
                $expectedStepProcedureId = is_array($runStep)
                    && isset($runStep['source_procedure_id'])
                    && $runStep['source_procedure_id'] !== null
                    ? (int) $runStep['source_procedure_id']
                    : (int) ($run->procedure_id ?? 0);
                $expectedStepMemoryId = is_array($runStep)
                    && isset($runStep['source_procedure_memory_id'])
                    && $runStep['source_procedure_memory_id'] !== null
                    ? (int) $runStep['source_procedure_memory_id']
                    : (int) ($run->procedure_memory_id ?? 0);
                if (!$run instanceof ProcedureRun
                    || (string) $run->status !== 'running'
                    || (int) $run->current_step !== $procedureStep
                    || !$runProcedure instanceof Procedure
                    || (string) $runProcedure->status !== 'active'
                    || (int) $runProcedure->memory_id !== (int) ($run->procedure_memory_id ?? 0)
                    || !$stepProcedure instanceof Procedure
                    || (string) $stepProcedure->status !== 'active'
                    || (int) ($execution->procedure_id ?? 0) !== $expectedStepProcedureId
                    || (int) $stepProcedure->memory_id
                        !== (int) ($execution->procedure_memory_id ?? 0)
                    || (int) ($execution->procedure_memory_id ?? 0) !== $expectedStepMemoryId
                ) {
                    throw new RuntimeException('Look request cannot suspend a different procedure generation.');
                }
            } elseif ($execution instanceof ActionExecution
                && ($execution->procedure_run_id !== null || $execution->step_index !== null)
            ) {
                throw new RuntimeException('Procedure look action is missing its run-step identity.');
            }
            $decisionCycle = null;
            if ($decisionCycleId !== null) {
                if (!$execution instanceof ActionExecution || $procedureRunId !== null) {
                    throw new RuntimeException('Decision look request has a crossed execution identity.');
                }
                $decisionCycle = DecisionCycle::getByID($decisionCycleId);
                if (!$decisionCycle instanceof DecisionCycle
                    || (string) $decisionCycle->status !== 'running'
                    || (string) $decisionCycle->state !== 'execute'
                    || (int) $decisionCycle->intention_id !== $intentionId
                ) {
                    throw new RuntimeException('Look request cannot bind a different decision cycle.');
                }
            }

            $event = $this->emit('look.requested', [
                'command' => $command,
                'because' => $because,
                // What the look is for. A look with an intention behind it is a
                // step in something Navi is doing; one without is curiosity.
                // Both are legitimate and they are not the same act, so the
                // record says which it was.
                'intention_id' => $intentionId,
                'action_id' => $actionId,
                'procedure_run_id' => $procedureRunId,
                'procedure_step' => $procedureStep,
            ]);
            $queue = $this->connection->prepare(
                "INSERT INTO look_request_claims
                 (event_id,owner,status,outcome_hash,outcome_data)
                 VALUES (:event_id,'','queued',NULL,NULL)"
            );
            $queue->execute(['event_id' => (int) $event->id]);
            if ($actionId !== null) {
                $bind = $this->connection->prepare(
                    "UPDATE action_executions
                     SET dispatch_event_id = :dispatch_event_id,
                         status = 'waiting', updated_at = :updated_at
                     WHERE action_trace_id = :action_id AND status = 'dispatching'"
                );
                $bind->execute([
                    'dispatch_event_id' => (int) $event->id,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'action_id' => $actionId,
                ]);
                if ($bind->rowCount() !== 1) {
                    throw new RuntimeException('Look request could not bind its action execution atomically.');
                }
                $waitClaim = $this->connection->prepare(
                    "UPDATE action_dispatch_claims SET status = 'waiting'
                     WHERE action_trace_id = :action_id AND owner = :owner AND status = 'claimed'"
                );
                $waitClaim->execute([
                    'action_id' => $actionId,
                    'owner' => $this->dispatchOwner(),
                ]);
                if ($waitClaim->rowCount() !== 1) {
                    throw new RuntimeException('Look request lost its action dispatch claim.');
                }
            }
            if ($procedureRunId !== null || $procedureStep !== null) {
                if ($actionId === null || $procedureRunId === null || $procedureStep === null) {
                    throw new RuntimeException('Procedure look request has an incomplete run-step identity.');
                }
                $actionIds = array_values(array_unique(array_merge(
                    array_map('intval', (array) $run->action_trace_ids),
                    [$actionId]
                )));
                $suspend = $this->connection->prepare(
                    "UPDATE procedure_runs
                     SET action_trace_ids = :action_trace_ids,
                         status = 'waiting', updated_at = :updated_at
                     WHERE id = :id AND status = 'running' AND current_step = :step_index"
                );
                $suspend->execute([
                    'action_trace_ids' => serialize($actionIds),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'id' => $procedureRunId,
                    'step_index' => $procedureStep,
                ]);
                if ($suspend->rowCount() !== 1) {
                    throw new RuntimeException('Look request could not suspend its procedure run atomically.');
                }
            }
            if ($decisionCycle instanceof DecisionCycle) {
                $selection = is_array($decisionCycle->selection) ? $decisionCycle->selection : [];
                $decisionCycle->setFields([
                    'execution' => [
                        'candidate_id' => $selection['candidate_id'] ?? null,
                        'action_id' => $actionId,
                        'status' => 'waiting',
                        'adapter_dispatch' => [
                            'status' => 'waiting',
                            'dispatch_event_id' => (int) $event->id,
                            'wait_committed' => true,
                        ],
                    ],
                    'state' => 'verify',
                    'status' => 'waiting',
                    'updated_at' => time(),
                ]);
                $decisionCycle->save();
                $claim = $this->connection->prepare(
                    "UPDATE decision_async_claims
                     SET request_event_id = :request_event_id, status = 'waiting'
                     WHERE action_id = :action_id
                       AND decision_cycle_id = :decision_cycle_id
                       AND request_event_id IS NULL AND status = 'started'"
                );
                $claim->execute([
                    'action_id' => $actionId,
                    'decision_cycle_id' => $decisionCycleId,
                    'request_event_id' => (int) $event->id,
                ]);
                if ($claim->rowCount() !== 1) {
                    throw new RuntimeException('Look request lost its pre-dispatch decision binding.');
                }
                $this->emit('decision_cycle.transition', [
                    'decision_cycle_id' => $decisionCycleId,
                    'completed_state' => 'execute',
                    'next_state' => 'verify',
                    'elapsed_ms' => 0,
                ]);
            }
            return ['event' => $event->getData()];
        });
    }

    public function decisionCycleForAsyncAction(int $actionId): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT decision_cycle_id FROM decision_async_claims WHERE action_id = :action_id'
        );
        $statement->execute(['action_id' => $actionId]);
        $cycleId = $statement->fetchColumn();
        $statement->closeCursor();
        return $cycleId === false ? null : (int) $cycleId;
    }

    /** @return list<array{action_id: int, decision_cycle_id: int, status: string, owner_live: bool}> */
    public function pendingDecisionActionClaims(int $limit = 64): array
    {
        $limit = max(1, min(512, $limit));
        $query = function (int $after) use ($limit): array {
            $statement = $this->connection->prepare(
                "SELECT action_id,decision_cycle_id,owner,status
                 FROM decision_async_claims
                 WHERE status IN ('started','waiting') AND action_id > :after_action_id
                 ORDER BY action_id ASC
                 LIMIT :claim_limit"
            );
            $statement->bindValue('after_action_id', $after, PDO::PARAM_INT);
            $statement->bindValue('claim_limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            return $rows;
        };
        $rows = $query(self::$decisionClaimSweepCursor);
        if ($rows === [] && self::$decisionClaimSweepCursor > 0) {
            self::$decisionClaimSweepCursor = 0;
            $rows = $query(0);
        }
        if ($rows !== []) {
            self::$decisionClaimSweepCursor = max(array_map(
                static fn (array $row): int => (int) $row['action_id'],
                $rows
            ));
        }
        return array_values(array_map(fn (array $row): array => [
            'action_id' => (int) $row['action_id'],
            'decision_cycle_id' => (int) $row['decision_cycle_id'],
            'status' => (string) $row['status'],
            'owner_live' => $this->dispatchOwnerIsLive((string) $row['owner']),
        ], $rows));
    }

    public function bindLegacyDecisionAsyncClaim(
        int $decisionCycleId,
        int $actionId,
        int $requestEventId
    ): void {
        if ($decisionCycleId < 1 || $actionId < 1 || $requestEventId < 1) {
            throw new InvalidArgumentException('Legacy asynchronous decision identity is invalid.');
        }
        $this->transaction(function () use ($decisionCycleId, $actionId, $requestEventId): void {
            $statement = $this->connection->prepare(
                "INSERT OR IGNORE INTO decision_async_claims
                 (action_id,decision_cycle_id,request_event_id,owner,status,outcome_hash)
                 VALUES (:action_id,:decision_cycle_id,:request_event_id,:owner,'waiting',NULL)"
            );
            $statement->execute([
                'action_id' => $actionId,
                'decision_cycle_id' => $decisionCycleId,
                'request_event_id' => $requestEventId,
                'owner' => $this->dispatchOwner(),
            ]);
            $check = $this->connection->prepare(
                'SELECT decision_cycle_id,request_event_id
                 FROM decision_async_claims WHERE action_id = :action_id'
            );
            $check->execute(['action_id' => $actionId]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            $check->closeCursor();
            if (!is_array($row)
                || (int) $row['decision_cycle_id'] !== $decisionCycleId
                || (int) $row['request_event_id'] !== $requestEventId
            ) {
                throw new RuntimeException('Legacy asynchronous decision claim conflicts with durable state.');
            }
        });
    }

    /** @param array<string, mixed> $finished
     *  @return array<string, mixed>
     */
    public function finalizeAsyncDecisionCycle(
        int $decisionCycleId,
        int $actionId,
        bool $matched,
        array $finished
    ): array {
        return $this->transaction(function () use (
            $decisionCycleId,
            $actionId,
            $matched,
            $finished
        ): array {
            $claim = $this->connection->prepare(
                'SELECT decision_cycle_id,request_event_id,status,outcome_hash
                 FROM decision_async_claims WHERE action_id = :action_id'
            );
            $claim->execute(['action_id' => $actionId]);
            $row = $claim->fetch(PDO::FETCH_ASSOC);
            $claim->closeCursor();
            if (!is_array($row) || (int) $row['decision_cycle_id'] !== $decisionCycleId) {
                throw new RuntimeException('Asynchronous decision has no exact durable claim.');
            }
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof ActionExecution
                || ($row['request_event_id'] !== null
                    && (int) ($execution->dispatch_event_id ?? 0) !== (int) $row['request_event_id'])
                || !in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)
                || $matched !== ((string) $execution->status === 'succeeded'
                    && (int) $execution->verified === 1)
            ) {
                throw new RuntimeException('Asynchronous decision outcome conflicts with its durable action.');
            }
            $outcomeHash = hash('sha256', serialize([
                'decision_cycle_id' => $decisionCycleId,
                'action_id' => $actionId,
                'matched' => $matched,
                'execution_status' => (string) $execution->status,
                'verified' => (int) $execution->verified,
                'observed' => (array) $execution->observed,
            ]));
            if ((string) $row['status'] === 'completed') {
                if (!is_string($row['outcome_hash'])
                    || !hash_equals($row['outcome_hash'], $outcomeHash)
                ) {
                    throw new RuntimeException('Asynchronous decision already has a different outcome.');
                }
                $cycle = DecisionCycle::getByID($decisionCycleId);
                if (!$cycle instanceof DecisionCycle || (string) $cycle->status !== 'completed') {
                    throw new RuntimeException('Completed asynchronous decision lost its terminal cycle.');
                }
                $event = $this->emitOnce(
                    'decision-cycle-completed:' . $decisionCycleId,
                    'decision_cycle.completed',
                    $this->decisionCompletedPayload($cycle, $matched)
                );
                return [
                    'status' => 'completed',
                    'cycle' => $cycle->getData(),
                    'event' => $event->getData(),
                    'replayed' => true,
                ];
            }
            if (!in_array((string) $row['status'], ['started', 'waiting'], true)) {
                throw new RuntimeException('Asynchronous decision completion is in an invalid state.');
            }
            $cycle = DecisionCycle::getByID($decisionCycleId);
            $cycleExecution = $cycle instanceof DecisionCycle && is_array($cycle->execution)
                ? $cycle->execution
                : [];
            $waiting = (string) $row['status'] === 'waiting';
            if (!$cycle instanceof DecisionCycle
                || (string) $cycle->status !== ($waiting ? 'waiting' : 'running')
                || (string) $cycle->state !== ($waiting ? 'verify' : 'execute')
                || (int) ($cycleExecution['action_id'] ?? 0) !== $actionId
            ) {
                throw new RuntimeException('Asynchronous decision cycle is not waiting for its claimed action.');
            }

            $trace = ActionTrace::getByID($actionId);
            if (!$trace instanceof ActionTrace) {
                throw new RuntimeException('Asynchronous decision lost its durable action trace.');
            }
            $action = $trace->getData();
            $learned = is_array($finished['procedure'] ?? null) ? $finished['procedure'] : [];
            $learnedProcedureId = (int) ($learned['procedure']['id'] ?? 0);
            $learnedMemoryId = (int) ($learned['memory']['id'] ?? 0);
            $learnedProcedure = $learnedProcedureId > 0
                ? Procedure::getByID($learnedProcedureId)
                : null;
            if (!$learnedProcedure instanceof Procedure
                || (int) $learnedProcedure->memory_id !== $learnedMemoryId
                || !in_array($actionId, array_map('intval', (array) $learnedProcedure->evidence_action_ids), true)
            ) {
                $learnedProcedureId = 0;
                $learnedMemoryId = 0;
            }
            $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
            $timings['verify_ms'] ??= 0;
            $timings['adapt_ms'] ??= 0;
            $timings['total_ms'] = array_sum(array_map('intval', $timings));
            $cycle->setFields([
                'verification' => [
                    'matched' => $matched,
                    'action_id' => $action['id'] ?? $actionId,
                    'status' => $action['status'] ?? (string) $execution->status,
                    'match_status' => $action['match_status'] ?? ($matched ? 'matched' : 'mismatched'),
                    'observed' => $action['observed'] ?? null,
                ],
                'adaptation' => [
                    'procedure_compiled_or_updated' => $learnedProcedureId > 0,
                    'procedure_id' => $learnedProcedureId > 0 ? $learnedProcedureId : null,
                    'memory_id' => $learnedMemoryId > 0 ? $learnedMemoryId : null,
                    'invalidating_failure_observed' => !$matched,
                ],
                'state' => 'complete',
                'status' => 'completed',
                'completed_at' => time(),
                'stage_timings' => $timings,
                'updated_at' => time(),
            ]);
            $cycle->save();
            if (!$waiting) {
                $this->emitOnce(
                    'decision-cycle-transition:' . $decisionCycleId . ':execute-verify',
                    'decision_cycle.transition',
                    [
                        'decision_cycle_id' => $decisionCycleId,
                        'completed_state' => 'execute',
                        'next_state' => 'verify',
                        'elapsed_ms' => 0,
                    ]
                );
            }
            $this->emitOnce(
                'decision-cycle-transition:' . $decisionCycleId . ':verify-adapt',
                'decision_cycle.transition',
                [
                    'decision_cycle_id' => $decisionCycleId,
                    'completed_state' => 'verify',
                    'next_state' => 'adapt',
                    'elapsed_ms' => 0,
                ]
            );
            $this->emitOnce(
                'decision-cycle-transition:' . $decisionCycleId . ':adapt-complete',
                'decision_cycle.transition',
                [
                    'decision_cycle_id' => $decisionCycleId,
                    'completed_state' => 'adapt',
                    'next_state' => 'complete',
                    'elapsed_ms' => 0,
                ]
            );
            $event = $this->emitOnce(
                'decision-cycle-completed:' . $decisionCycleId,
                'decision_cycle.completed',
                $this->decisionCompletedPayload($cycle, $matched)
            );
            $complete = $this->connection->prepare(
                "UPDATE decision_async_claims SET status = 'completed', outcome_hash = :outcome_hash
                 WHERE action_id = :action_id AND decision_cycle_id = :decision_cycle_id
                   AND status = :expected_status"
            );
            $complete->execute([
                'outcome_hash' => $outcomeHash,
                'action_id' => $actionId,
                'decision_cycle_id' => $decisionCycleId,
                'expected_status' => $waiting ? 'waiting' : 'started',
            ]);
            if ($complete->rowCount() !== 1) {
                throw new RuntimeException('Asynchronous decision lost its completion claim.');
            }
            return ['status' => 'completed', 'cycle' => $cycle->getData(), 'event' => $event->getData()];
        });
    }

    /** @return array<string, mixed> */
    private function decisionCompletedPayload(DecisionCycle $cycle, bool $matched): array
    {
        $selection = is_array($cycle->selection) ? $cycle->selection : [];
        $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
        return [
            'decision_cycle_id' => (int) $cycle->id,
            'model_id' => $cycle->model_id,
            'matched' => $matched,
            'selected_action_kind' => $selection['action_kind'] ?? null,
            'total_ms' => (int) ($timings['total_ms'] ?? 0),
        ];
    }

    /**
     * Sources Navi may invoke rather than only receive.
     *
     * A continuous source arrives on its own schedule and Navi reads whatever
     * it happens to say. An on-demand source does nothing until asked, which
     * makes asking an act rather than an observation — and the only kind of act
     * available here, since every source's ceiling is still observe. This is
     * the list of things Navi can decide to do.
     *
     * @return list<array<string, mixed>>
     */
    public function actionableSenses(): array
    {
        $actionable = [];
        foreach (SensorySource::getAllByWhere(['status' => 'active']) as $source) {
            if ($source->acquisition !== 'on_demand') {
                continue;
            }
            $actionable[] = [
                'source_key' => (string) $source->source_key,
                'reveals' => (string) $source->reveals,
                'effect_ceiling' => (string) $source->effect_ceiling,
            ];
        }
        return $actionable;
    }

    /**
     * Pick the one thing this wake is for.
     *
     * Read the live situation, score the candidates against affect, and record
     * the decision. The record matters as much as the choice: a wake that chose
     * to think rather than speak is a different event from a wake where speech
     * failed, and until now those were indistinguishable in the log.
     *
     * @return array<string, mixed>
     */
    public function selectAction(int $now): array
    {
        $affect = $this->appraiseNow($now);
        $cortex = $this->sensoryCortex();
        $pending = $cortex->pendingEvents(8);

        $addressed = array_filter(
            $pending,
            static fn (array $event): bool => ($event['addressed'] ?? false) === true
        ) !== [];

        $consolidated = [];
        foreach (Memory::inspectAllByWhere(['tier' => 'semantic'], ['limit' => 500]) as $semantic) {
            if ($semantic->source_memory_id !== null) {
                $consolidated[(int) $semantic->source_memory_id] = true;
            }
        }
        $unconsolidated = 0;
        $learnedMemories = [];
        foreach (Memory::inspectAllByWhere(
            ['tier' => 'episodic', 'status' => 'active'],
            ['order' => ['id' => 'DESC'], 'limit' => 200]
        ) as $episode) {
            if (!isset($consolidated[(int) $episode->id]) && $this->isEvidence((string) $episode->content)) {
                $unconsolidated++;
            }
        }

        $thread = CognitiveThread::getByField('thread_key', self::SELF_PRESENCE_THREAD_KEY);
        $otherAgent = $this->otherModel->decisionState();
        $decision = ActionSelector::choose(
            $affect,
            [
                'answer' => $addressed,
                'speak' => true,
                'look' => $this->actionableSenses() !== [],
                'think' => true,
                'consolidate' => $unconsolidated >= 2,
            ],
            [
                'user_present' => $this->presenceNow() === true,
                'unexplained_edges' => count($pending),
                'unconsolidated_episodes' => $unconsolidated,
                'holding_a_track' => $thread instanceof CognitiveThread
                    && trim((string) $thread->desired_outcome) !== '',
                'other_agent_speech_factor' => $otherAgent['speech_factor'],
                'other_agent_counterfactual_speech_factor' => $otherAgent['counterfactual_speech_factor'],
                'other_agent_attention_mode' => $otherAgent['attention_mode'],
                'other_agent_model_ablated' => $otherAgent['ablated'],
            ]
        );

        $decision['feeling'] = $affect->dominant;
        $this->emit('action.selected', $decision);
        return $decision;
    }

    /**
     * Decide to use an actionable sense, on Navi's own initiative.
     *
     * The curiosity rhythm queues one of these when bored, which is the
     * involuntary case: something is dull, so go and find something out. This
     * is the voluntary one — Navi holds a track, decides looking would move it,
     * and asks. Nothing about the mechanism differs; what differs is who
     * started it, and that distinction is worth keeping because an agent that
     * can only act when prodded is not really acting.
     *
     * @return array<string, mixed>
     */
    public function intendLook(string $reason): array
    {
        $this->requireText($reason, 'reason for looking');
        if ($this->actionableSenses() === []) {
            return ['status' => 'no_actionable_sense'];
        }

        $queued = $this->enqueueLookProposal(null, time());
        if ($queued === null) {
            return ['status' => 'not_queued'];
        }
        $this->emit('look.intended', [
            'reason' => $reason,
            'work_item_id' => $queued['work_item']['id'] ?? null,
        ]);
        return ['status' => 'queued', 'work_item' => $queued['work_item'] ?? null];
    }

    /**
     * Queue one decision about what would be worth finding out.
     *
     * Deliberately given no examples and no vocabulary of commands. Supplying
     * either would make this a menu, and a menu is a description of the machine
     * the author had rather than the one Navi is on. What Navi gets instead is
     * what Navi has already learned from looking, which starts empty and is the
     * only thing that should grow.
     *
     * @return array<string, mixed>|null
     */
    private function enqueueLookProposal(?int $runId, int $now): ?array
    {
        $learned = [];
        $learnedMemories = [];
        foreach (Memory::inspectAllByWhere(
            ['status' => 'active'],
            ['order' => ['id' => 'DESC'], 'limit' => 200]
        ) as $memory) {
            if (!str_contains((string) $memory->content, 'this machine')) {
                continue;
            }
            $learnedMemories[] = $memory;
            $learned[] = mb_substr((string) $memory->content, 0, 260);
            if (count($learned) >= 8) {
                break;
            }
        }
        Memory::observeRecords($learnedMemories);

        $unexplained = array_map(
            static fn (array $event): array => [
                'sense' => $event['sense_key'],
                'noticed' => $event['summary'],
            ],
            $this->sensoryCortex()->pendingEvents(5)
        );

        $composition = new ExecutiveComposition(
            'Navi can run one command on this machine and read what it prints. Nothing is changed by it: '
            . 'the account it runs as cannot write anywhere.'
        );

        // A look in service of something Navi is actually pursuing beats an
        // idle one, so the track is offered first and named as the reason to
        // look. When nothing is being pursued, curiosity is reason enough.
        $thread = CognitiveThread::getByField('thread_key', self::SELF_PRESENCE_THREAD_KEY);
        $track = $thread instanceof CognitiveThread ? $this->heldFocus($thread, $now) : null;
        if ($track !== null) {
            $composition->contribute(
                'intent',
                'This is what Navi is working on. If something could be found out that moves it forward, '
                . 'look at that rather than at whatever is merely nearby.',
                $track
            );
        }

        $composition->contribute(
            'curiosity',
            'Name one thing about this machine worth finding out right now, and the command that would find it out. '
            . 'It must be something the answer is not already known for. Prefer a question raised by what the senses '
            . 'just noticed over a general survey.',
            $unexplained
        );
        $composition->contribute(
            'what_looking_has_taught',
            $learned === []
                ? 'Nothing has been learned by looking yet, so anything found now is new.'
                : 'Already established about this machine. Do not re-run something whose answer is here; build on it.',
            $learned
        );
        $composition->contribute(
            'constraint',
            'One command, one line, read-only. It runs as an unprivileged account with no write permission anywhere, '
            . 'so anything that would modify the machine will simply fail and waste the look. '
            . 'Put the command in content and what it is meant to reveal in challenged_assumption.'
        );

        return $this->enqueueWork(
            parentRunId: $runId,
            parentIntentionId: null,
            workType: self::LOOK_WORK_TYPE,
            prompt: $composition->prompt() . "\n\n" . implode("\n", [
                'Return kind machine_look, content holding only the command itself with no explanation or formatting,',
                'confidence in it being worth running, and challenged_assumption stating what it should reveal.',
                'Output exactly the four JSON fields in the supplied schema and nothing else.',
            ]),
            inputRefs: [
                'looked_before' => count($learned),
                'for_intention' => $track === null ? null : (string) $track['following'],
            ],
            tokenBudget: 256,
            wallBudgetSeconds: 300,
            idempotencyKey: 'look_proposal:' . intdiv($now, 300)
        );
    }

    /**
     * Turn a proposed look into a queued one.
     *
     * @param array<string, mixed> $work
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private function integrateLookProposal(array $work, array $proposal, ?string $model): array
    {
        $command = trim((string) ($proposal['content'] ?? ''));
        $because = trim((string) ($proposal['challenged_assumption'] ?? ''));

        // A command is one line. Anything else is the model explaining itself,
        // and running an explanation is how a shell gets asked to do something
        // nobody meant.
        if ($command === '' || $because === '' || str_contains($command, "\n")) {
            return ['status' => 'rejected', 'reason' => 'not_a_single_command'];
        }
        if (mb_strlen($command) > 400) {
            return ['status' => 'rejected', 'reason' => 'command_too_long'];
        }

        // Carry the intention through, so a look taken to advance something is
        // recorded as having been taken for it rather than arriving unattached.
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $intentionId = null;
        $title = $refs['for_intention'] ?? null;
        if (is_string($title) && $title !== '') {
            $intention = Intention::getByField('title', $title);
            if ($intention instanceof Intention && $intention->status === 'active') {
                $intentionId = (int) $intention->id;
            }
        }

        $queued = $this->requestLook($command, $because, $intentionId);
        return [
            'status' => 'look_queued',
            'command' => $command,
            'because' => $because,
            'for_intention_id' => $intentionId,
            'model' => $model,
            'event' => $queued['event'] ?? null,
        ];
    }

    /**
     * Record what a look taught, so looking accumulates instead of repeating.
     *
     * This is the half that makes the capability worth having. A command that
     * worked, and what it turned out to show, is a fact about this machine that
     * Navi did not have before; without writing it down every look starts from
     * nothing and the same question gets asked forever. It is written as an
     * episode in evidence form, so the consolidation pass generalises it the
     * same way it generalises anything else observed — which is how a specific
     * command becomes knowledge about what can be found out and how.
     *
     * Nothing here knows any commands. What is worth running is learned from
     * these records, never from a list written in advance, because a list
     * written in advance is a description of the author's machine rather than
     * of this one.
     *
     * @param array<string, mixed> $reading
     * @return array<string, mixed>
     */
    public function rememberLook(
        string $command,
        string $because,
        array $reading,
        ?int $requestEventId = null
    ): array
    {
        $refused = ($reading['refused'] ?? false) === true;
        $exit = $reading['exit_code'] ?? null;
        $worked = !$refused && $exit === 0;
        $outcomeHash = $requestEventId === null
            ? null
            : $this->stageLookCompletion($command, $because, $reading, $requestEventId);

        if ($refused) {
            $content = sprintf(
                'Looking at this machine with "%s" could not happen: %s. The question was %s.',
                $command,
                (string) ($reading['refused_because'] ?? 'no reason given'),
                $because
            );
        } elseif (!$worked) {
            $content = sprintf(
                'On this machine "%s" does not work as a way to find out %s. It exited %s%s.',
                $command,
                $because,
                var_export($exit, true),
                trim((string) ($reading['error_output'] ?? '')) === ''
                    ? ''
                    : ': ' . mb_substr(trim((string) $reading['error_output']), 0, 200)
            );
        } else {
            $content = sprintf(
                'On this machine "%s" answers %s. It returned: %s',
                $command,
                $because,
                mb_substr(trim((string) ($reading['output'] ?? '')), 0, 600)
            );
        }

        $eventPayload = [
            'command' => $command,
            'because' => $because,
            'worked' => $worked,
            'refused' => $refused,
            'exit_code' => $exit,
            'request_event_id' => $requestEventId,
        ];
        $event = $requestEventId === null
            ? $this->emit('look.observed', $eventPayload)
            : $this->emitOnce(
                'look.observed:request:' . $requestEventId,
                'look.observed',
                $eventPayload
            );

        $memory = $this->addMemory(
            tier: 'episodic',
            content: $content,
            // A look that failed is as true a record as one that worked, and
            // knowing what does not work here is most of what stops the same
            // dead end being tried again.
            confidence: $worked ? 0.9 : 0.8,
            idempotencyKey: $requestEventId === null
                ? 'look-observed:' . (int) $event->id
                : 'look-observed-request:' . $requestEventId,
            sourceEventId: (int) $event->id
        );
        $procedure = $requestEventId === null
            ? null
            : $this->proceduralMemory->completePendingLook($requestEventId, $reading);
        if ($requestEventId !== null) {
            $this->finishLookCompletion($requestEventId, $outcomeHash);
        }
        return $memory + ['procedure_completion' => $procedure];
    }

    /**
     * Durably bind the executor's chosen reading before any later ingestion,
     * event, token-memory, or callback work can fail. Exact replays converge
     * on this stored reading and can never execute the external command again.
     *
     * @param array<string, mixed> $reading
     */
    public function stageLookCompletion(
        string $command,
        string $because,
        array $reading,
        int $requestEventId
    ): string {
        $this->requireText($command, 'command');
        $this->requireText($because, 'reason for looking');
        if ($requestEventId < 1) {
            throw new InvalidArgumentException('Look request event identity is invalid.');
        }
        $request = Event::getByID($requestEventId);
        $payload = $request instanceof Event && is_array($request->payload)
            ? $request->payload
            : [];
        if (!$request instanceof Event
            || (string) $request->kind !== 'look.requested'
            || !hash_equals((string) ($payload['command'] ?? ''), $command)
            || !hash_equals((string) ($payload['because'] ?? ''), $because)
        ) {
            throw new RuntimeException('Look completion does not match its immutable request event.');
        }
        $outcomeHash = hash('sha256', serialize([
            'command' => $command,
            'because' => $because,
            'reading' => $reading,
        ]));
        $this->beginLookCompletion($requestEventId, $outcomeHash, $reading);
        return $outcomeHash;
    }

    /** @param array<string, mixed> $reading */
    private function beginLookCompletion(int $eventId, string $outcomeHash, array $reading): void
    {
        $this->transaction(function () use ($eventId, $outcomeHash, $reading): void {
            $owner = $this->dispatchOwner();
            $statement = $this->connection->prepare(
                'SELECT owner,status,outcome_hash FROM look_request_claims WHERE event_id = :event_id'
            );
            $statement->execute(['event_id' => $eventId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            if (!is_array($row)) {
                throw new RuntimeException('Look completion has no executor claim.');
            }
            if (in_array($row['status'], ['completing', 'completed'], true)) {
                if (!is_string($row['outcome_hash']) || !hash_equals($row['outcome_hash'], $outcomeHash)) {
                    throw new RuntimeException('Look request already has a different durable outcome.');
                }
                return;
            }
            if ($row['status'] !== 'claimed' || !hash_equals((string) $row['owner'], $owner)) {
                throw new RuntimeException('Look completion is not owned by this executor process.');
            }
            $update = $this->connection->prepare(
                "UPDATE look_request_claims
                 SET status = 'completing', outcome_hash = :outcome_hash, outcome_data = :outcome_data
                 WHERE event_id = :event_id AND owner = :owner AND status = 'claimed'"
            );
            $update->execute([
                'outcome_hash' => $outcomeHash,
                'outcome_data' => serialize($reading),
                'event_id' => $eventId,
                'owner' => $owner,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Look completion lost its executor claim.');
            }
        });
    }

    private function finishLookCompletion(int $eventId, string $outcomeHash): void
    {
        $this->transaction(function () use ($eventId, $outcomeHash): void {
            $statement = $this->connection->prepare(
                "UPDATE look_request_claims
                 SET status = 'completed'
                 WHERE event_id = :event_id AND outcome_hash = :outcome_hash
                   AND status = 'completing'"
            );
            $statement->execute(['event_id' => $eventId, 'outcome_hash' => $outcomeHash]);
            if ($statement->rowCount() === 1) {
                return;
            }
            $check = $this->connection->prepare(
                'SELECT status,outcome_hash FROM look_request_claims WHERE event_id = :event_id'
            );
            $check->execute(['event_id' => $eventId]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            $check->closeCursor();
            if (!is_array($row)
                || $row['status'] !== 'completed'
                || !is_string($row['outcome_hash'])
                || !hash_equals($row['outcome_hash'], $outcomeHash)
            ) {
                throw new RuntimeException('Look completion could not commit its exact outcome.');
            }
        });
    }

    /**
     * Take the looks that have not been answered yet.
     *
     * Reads the request events and pairs them off against the readings that
     * already came back, so a look survives a daemon restart and is never run
     * twice for one asking.
     *
     * @return list<array<string, mixed>>
     */
    public function claimPendingLooks(int $limit = 3): array
    {
        $this->decisionStateMachine->reconcileAsyncActions(max(8, $limit * 4));
        $limit = max(1, $limit);
        $pending = [];
        $recoveryIds = $this->lookRecoveryCandidateIds($limit);
        $queued = $this->connection->prepare(
            "SELECT event_id
             FROM look_request_claims
             WHERE status = 'queued'
             ORDER BY event_id ASC
             LIMIT :candidate_limit"
        );
        $queued->bindValue('candidate_limit', $limit, PDO::PARAM_INT);
        $queued->execute();
        $queuedIds = array_map('intval', $queued->fetchAll(PDO::FETCH_COLUMN));
        $queued->closeCursor();

        foreach (array_values(array_unique(array_merge($recoveryIds, $queuedIds))) as $eventId) {
            $event = Event::getByID($eventId);
            if (!$event instanceof Event || (string) $event->kind !== 'look.requested') {
                continue;
            }
            $payload = is_array($event->payload) ? $event->payload : [];
            $command = (string) ($payload['command'] ?? '');
            if ($command === '') {
                continue;
            }
            $because = (string) ($payload['because'] ?? '');
            $claim = $this->claimLookRequest((int) $event->id, $command, $because);
            if (!in_array($claim['status'], ['claimed', 'replay'], true)) {
                continue;
            }
            $pending[] = [
                'command' => $command,
                'because' => $because,
                'event_id' => (int) $event->id,
                'action_id' => isset($payload['action_id']) ? (int) $payload['action_id'] : null,
                'procedure_run_id' => isset($payload['procedure_run_id'])
                    ? (int) $payload['procedure_run_id']
                    : null,
                'procedure_step' => isset($payload['procedure_step'])
                    ? (int) $payload['procedure_step']
                    : null,
                'replay_reading' => $claim['reading'] ?? null,
            ];
            if (count($pending) >= max(1, $limit)) {
                break;
            }
        }
        return $pending;
    }

    /** @return list<int> */
    private function lookRecoveryCandidateIds(int $limit): array
    {
        $query = function (int $after) use ($limit): array {
            $statement = $this->connection->prepare(
                "SELECT c.event_id
                 FROM look_request_claims AS c
                 INNER JOIN events AS e ON e.id = c.event_id
                 WHERE e.kind = 'look.requested'
                   AND c.status IN ('legacy','claimed','completing')
                   AND c.event_id > :after_event_id
                 ORDER BY c.event_id ASC
                 LIMIT :candidate_limit"
            );
            $statement->bindValue('after_event_id', $after, PDO::PARAM_INT);
            $statement->bindValue('candidate_limit', max(1, $limit), PDO::PARAM_INT);
            $statement->execute();
            $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $statement->closeCursor();
            return $ids;
        };

        $ids = $query(self::$lookClaimSweepCursor);
        if ($ids === [] && self::$lookClaimSweepCursor > 0) {
            self::$lookClaimSweepCursor = 0;
            $ids = $query(0);
        }
        if ($ids !== []) {
            self::$lookClaimSweepCursor = max($ids);
        }
        return $ids;
    }

    /** @return array{status: 'claimed'|'replay'|'skip', reading?: array<string, mixed>} */
    private function claimLookRequest(int $eventId, string $command, string $because): array
    {
        return $this->transaction(function () use ($eventId, $command, $because): array {
            $owner = $this->dispatchOwner();
            $statement = $this->connection->prepare(
                'SELECT owner,status,outcome_data FROM look_request_claims WHERE event_id = :event_id'
            );
            $statement->execute(['event_id' => $eventId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            if (!is_array($row)) {
                throw new RuntimeException('Look request is missing its atomic queue record.');
            }
            if ($row['status'] === 'queued') {
                $claim = $this->connection->prepare(
                    "UPDATE look_request_claims SET owner = :owner, status = 'claimed'
                     WHERE event_id = :event_id AND status = 'queued'"
                );
                $claim->execute(['owner' => $owner, 'event_id' => $eventId]);
                if ($claim->rowCount() !== 1) {
                    throw new RuntimeException('Look queue claim changed concurrently.');
                }
                return ['status' => 'claimed'];
            }
            if ($row['status'] === 'legacy') {
                $reading = [
                    'refused' => true,
                    'refused_because' => 'This request predates the durable executor ledger; its outcome is indeterminate.',
                    'exit_code' => null,
                    'timed_out' => false,
                    'output_digest' => null,
                    'output_bytes' => null,
                ];
                $recover = $this->connection->prepare(
                    "UPDATE look_request_claims
                     SET owner = :owner, status = 'completing',
                         outcome_hash = :outcome_hash, outcome_data = :outcome_data
                     WHERE event_id = :event_id AND status = 'legacy'"
                );
                $recover->execute([
                    'owner' => $owner,
                    'outcome_hash' => hash('sha256', serialize([
                        'command' => $command,
                        'because' => $because,
                        'reading' => $reading,
                    ])),
                    'outcome_data' => serialize($reading),
                    'event_id' => $eventId,
                ]);
                return $recover->rowCount() === 1
                    ? ['status' => 'replay', 'reading' => $reading]
                    : ['status' => 'skip'];
            }
            if (!in_array($row['status'], ['claimed', 'completing'], true)) {
                return ['status' => 'skip'];
            }
            $previousOwner = (string) $row['owner'];
            if ($row['status'] === 'completing') {
                if (!hash_equals($previousOwner, $owner)
                    && $this->dispatchOwnerIsLive($previousOwner)
                ) {
                    return ['status' => 'skip'];
                }
                $recover = $this->connection->prepare(
                    "UPDATE look_request_claims SET owner = :owner
                     WHERE event_id = :event_id AND owner = :previous_owner AND status = 'completing'"
                );
                $recover->execute([
                    'owner' => $owner,
                    'event_id' => $eventId,
                    'previous_owner' => $previousOwner,
                ]);
                $reading = isset($row['outcome_data']) ? @unserialize((string) $row['outcome_data']) : null;
                if (!is_array($reading)
                    || ($recover->rowCount() !== 1 && !hash_equals($previousOwner, $owner))
                ) {
                    throw new RuntimeException('Indeterminate look completion has no replayable outcome.');
                }
                return ['status' => 'replay', 'reading' => $reading];
            }
            if (!hash_equals($previousOwner, $owner)
                && $this->dispatchOwnerIsLive($previousOwner)
            ) {
                return ['status' => 'skip'];
            }
            $recover = $this->connection->prepare(
                "UPDATE look_request_claims
                 SET owner = :owner, status = 'completing',
                     outcome_hash = :outcome_hash, outcome_data = :outcome_data
                 WHERE event_id = :event_id AND owner = :previous_owner AND status = 'claimed'"
            );
            $reading = [
                'refused' => true,
                'refused_because' => hash_equals($previousOwner, $owner)
                    ? 'The command executor returned to an unstaged request; its outcome is indeterminate.'
                    : 'The command executor exited after claiming this request; its outcome is indeterminate.',
                'exit_code' => null,
                'timed_out' => false,
                'output_digest' => null,
                'output_bytes' => null,
            ];
            $recover->execute([
                'owner' => $owner,
                'outcome_hash' => hash('sha256', serialize([
                    'command' => $command,
                    'because' => $because,
                    'reading' => $reading,
                ])),
                'outcome_data' => serialize($reading),
                'event_id' => $eventId,
                'previous_owner' => $previousOwner,
            ]);
            return $recover->rowCount() === 1
                ? ['status' => 'replay', 'reading' => $reading]
                : ['status' => 'skip'];
        });
    }

    /**
     * Read the six appraisal gauges off live state.
     *
     * Every number here comes from something the system already measures, so
     * affect is a reading of the situation rather than a mood someone declared.
     * If a gauge cannot be computed it sits at its neutral value instead of
     * being guessed, because a confident wrong gauge moves behaviour.
     */
    public function appraiseNow(int $now): EmotionalAppraisal
    {
        $cortex = $this->sensoryCortex();
        $pending = $cortex->pendingEvents(8);

        // Relevance is read from what recently happened, not from what is still
        // queued. Reading the queue was wrong in a way that took a while to
        // see: attending to an edge consumes it, and the speech path consumes
        // everything it reads, so the next appraisal found an empty queue and
        // concluded nothing was going on — microseconds after the same pass had
        // taken it all in. Eighteen of twenty appraisals scored relevance zero
        // while Navi was in the middle of a working session. Consumed means
        // attended to; it does not mean it did not happen.
        $salience = 0.0;
        foreach (SenseEvent::getAllByWhere(
            [],
            ['order' => ['id' => 'DESC'], 'limit' => 40]
        ) as $event) {
            $observedAt = $this->timestamp($event->observed_at);
            if ($observedAt === null || ($now - $observedAt) > self::AFFECT_WINDOW_SECONDS) {
                continue;
            }
            $salience += (float) $event->significance;
        }
        $relevance = min(1.0, $salience / 3.0);

        $addressed = false;
        foreach ($pending as $event) {
            $addressed = $addressed || ($event['addressed'] ?? false) === true;
        }

        // Expectedness: the mean predictability of what is currently salient,
        // which is a property of the senses rather than of the queue.
        $expectedness = $this->meanPredictability();

        // Desirability and control both come from whether work is succeeding.
        // Failing workers is the system's clearest signal that things are going
        // badly and that acting is not currently possible.
        $done = 0;
        $failed = 0;
        foreach (WorkItem::getAllByWhere([], ['order' => ['id' => 'DESC'], 'limit' => 40]) as $item) {
            if ($item->status === 'completed') {
                $done++;
            } elseif ($item->status === 'failed') {
                $failed++;
            }
        }
        $judged = $done + $failed;
        $successRate = $judged === 0 ? 0.5 : $done / $judged;
        $desirability = ($successRate - 0.5) * 2.0;

        $modelReady = false;
        foreach (ModelEndpoint::getAll() as $endpoint) {
            $cooldown = $this->timestamp($endpoint->cooldown_until);
            if ($endpoint->status === 'available' && ($cooldown === null || $cooldown <= $now)) {
                $modelReady = true;
                break;
            }
        }
        $control = ($successRate * 0.6) + ($modelReady ? 0.4 : 0.0);

        // Urgency: someone waiting outranks everything the clock can produce.
        $urgency = $addressed ? 0.9 : 0.0;
        foreach (ExecutiveInterrupt::getAllByWhere(['status' => 'pending'], ['limit' => 5]) as $interrupt) {
            $urgency = max($urgency, $interrupt->severity === 'critical' ? 1.0 : 0.6);
        }

        // Social descriptors are a confounded observable, not standing or a
        // reward. Hold this gauge neutral until conditioned evidence earns a
        // separately specified causal interpretation.
        $standing = 0.5;

        return EmotionalAppraisal::from(
            [
                'relevance' => $relevance,
                'desirability' => $desirability,
                'expectedness' => $expectedness,
                'control' => $control,
                'urgency' => $urgency,
                'standing' => $standing,
            ],
            $this->lastMood(),
            $this->spokenWordRange()
        );
    }

    /**
     * A real draw, isolated so the affect gate is testable.
     *
     * Speaking is probabilistic on purpose. A threshold would make the same
     * state always produce the same decision, which is a schedule wearing a
     * feeling as a costume; a draw means a quiet mood is quiet on average and
     * still occasionally has something to say.
     */
    private function randomUnit(): float
    {
        return random_int(0, PHP_INT_MAX - 1) / (PHP_INT_MAX - 1);
    }

    /**
     * How predictable the senses that fired recently have been.
     *
     * Asked of the cortex rather than derived from queue contents, so it means
     * the same thing whether or not anything has been consumed.
     */
    private function meanPredictability(): float
    {
        $scores = $this->sensoryCortex()->predictabilityByRecentSense();
        if ($scores === []) {
            return 0.5;
        }
        return array_sum($scores) / count($scores);
    }

    /** @return array{valence: float, arousal: float}|null */
    private function lastMood(): ?array
    {
        $event = Event::getByWhere(['kind' => 'affect.appraised'], ['order' => ['id' => 'DESC']]);
        if (!$event instanceof Event || !is_array($event->payload)) {
            return null;
        }
        $mood = $event->payload['mood'] ?? null;
        if (!is_array($mood) || !isset($mood['valence'], $mood['arousal'])) {
            return null;
        }
        return ['valence' => (float) $mood['valence'], 'arousal' => (float) $mood['arousal']];
    }

    /**
     * Has Navi effectively said this already?
     *
     * Content words only, so reordering and connective changes do not disguise
     * a repeat: "heartbeat's been quiet, your ping stirred the wires" and
     * "heartbeat paused, your recent ping stirred the wires" share almost every
     * word that carries meaning. Compared against what was actually spoken
     * within the window rather than everything ever said, because returning to
     * a subject hours later is fine and doing it four times in a row is not.
     */
    private function tooSimilarToRecent(string $content): bool
    {
        $words = static function (string $text): array {
            $stop = array_flip(['the', 'a', 'an', 'and', 'but', 'or', 'is', 'it', 'to', 'of',
                'in', 'on', 'at', 'with', 'you', 'your', 'i', 'im', 'my', 'me', 'that', 'this',
                'was', 'been', 'still', 'just', 'so', 'for', 'right', 'here', 's', 't']);
            $out = [];
            foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [] as $word) {
                if ($word !== '' && mb_strlen($word) > 2 && !isset($stop[$word])) {
                    $out[$word] = true;
                }
            }
            return $out;
        };

        $candidate = $words($content);
        if (count($candidate) < 3) {
            return false;
        }

        // timestamp() rather than a string compare: this column holds a Unix
        // integer while others hold datetime text, and comparing the two as
        // strings silently skipped every row.
        $since = time() - self::REPEAT_WINDOW_SECONDS;
        foreach (UtteranceOutcome::getAllByWhere([], ['order' => ['id' => 'DESC'], 'limit' => 12]) as $past) {
            $spokenAt = $this->timestamp($past->created_at);
            if ($spokenAt === null || $spokenAt < $since) {
                continue;
            }
            $previous = $words((string) $past->utterance);
            if ($previous === []) {
                continue;
            }
            $shared = count(array_intersect_key($candidate, $previous));
            $overlap = $shared / min(count($candidate), count($previous));
            if ($overlap >= self::REPEAT_OVERLAP) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does this episode record something that happened, or something Navi said?
     *
     * The prefixes are the ones rememberUtterance writes, plus the two record
     * kinds written elsewhere. Matching on them is ugly and it is what the
     * stored rows actually carry; a channel column would be better and would
     * not retrofit onto a hundred existing episodes.
     */
    private function isEvidence(string $content): bool
    {
        foreach (['Said aloud:', 'Inner monologue:', 'Said privately:', 'Spoke to herself:'] as $echo) {
            if (str_starts_with($content, $echo)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Apply a consolidation proposal, turning episodes into a durable claim.
     *
     * @param array<string, mixed> $work
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private function integrateConsolidation(array $work, array $proposal, ?string $model): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $workId = (int) ($work['id'] ?? 0);
        $expectedIds = $this->consolidationIdList($refs['episode_ids'] ?? []);
        $confidence = is_numeric($proposal['confidence'] ?? null) ? (float) $proposal['confidence'] : 0.0;
        if ($workId < 1 || $expectedIds === []) {
            return $this->rejectConsolidationAttempt($work, 'missing_work_or_episode_ids');
        }
        if (($proposal['kind'] ?? null) !== self::MEMORY_CONSOLIDATION_WORK_TYPE) {
            return $this->rejectConsolidationAttempt($work, 'wrong_proposal_kind');
        }

        $claim = is_string($proposal['content'] ?? null) ? trim($proposal['content']) : '';
        $rejectionReason = is_string($proposal['rejection_reason'] ?? null)
            ? trim($proposal['rejection_reason'])
            : '';
        $supportedIds = $this->consolidationIdList($proposal['supported_episode_ids'] ?? null);
        $rejectedIds = $this->consolidationIdList($proposal['rejected_episode_ids'] ?? null);
        $partition = array_values(array_unique(array_merge($supportedIds, $rejectedIds)));
        sort($partition);
        sort($expectedIds);
        if ($partition !== $expectedIds
            || count($supportedIds) + count($rejectedIds) !== count($expectedIds)
        ) {
            return $this->rejectConsolidationAttempt($work, 'fresh_episode_partition_is_incomplete');
        }
        if ($rejectedIds !== [] && ($rejectionReason === '' || $rejectionReason === 'none')) {
            return $this->rejectConsolidationAttempt($work, 'rejected_sources_have_no_reason');
        }

        if ($supportedIds === []) {
            if ($claim !== '' || $rejectedIds !== $expectedIds) {
                return $this->rejectConsolidationAttempt($work, 'empty_support_must_reject_the_whole_batch');
            }
            return $this->transaction(function () use (
                $workId,
                $expectedIds,
                $rejectionReason,
                $confidence,
                $model
            ): array {
                foreach ($expectedIds as $episodeId) {
                    $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
                    if ($entry instanceof MemoryConsolidationEpisode
                        && $entry->status === 'queued'
                        && (int) $entry->work_item_id === $workId
                    ) {
                        $entry->setFields([
                            'work_item_id' => null,
                            'status' => 'rejected',
                            'reason' => mb_substr($rejectionReason, 0, 1000),
                            'updated_at' => time(),
                        ]);
                        $entry->save();
                    }
                }
                $event = $this->emit('memory.consolidation.batch.rejected', [
                    'work_item_id' => $workId,
                    'episode_ids' => $expectedIds,
                    'reason' => $rejectionReason,
                    'confidence' => $confidence,
                    'model' => $model,
                    'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
                ]);
                return [
                    'status' => 'sources_rejected',
                    'episode_ids' => $expectedIds,
                    'reason' => $rejectionReason,
                    'event' => $event->getData(),
                ];
            });
        }

        if ($claim === '') {
            return $this->rejectConsolidationAttempt($work, 'supported_sources_require_a_claim');
        }
        if ($confidence < self::CONSOLIDATION_CONFIDENCE_FLOOR) {
            return $this->rejectConsolidationAttempt($work, 'supported_claim_below_confidence_floor');
        }

        $supersedes = $proposal['supersedes_memory_id'] ?? null;
        if ($supersedes !== null && (!is_int($supersedes) || $supersedes < 1)) {
            return $this->rejectConsolidationAttempt($work, 'invalid_supersedes_memory_id');
        }
        $allowedExisting = $this->consolidationIdList($refs['existing_memory_ids'] ?? []);
        if ($supersedes !== null) {
            return $this->rejectConsolidationAttempt($work, 'automatic_consolidation_does_not_supersede_existing_memory');
        }

        $episodes = [];
        $expectedHashes = is_array($refs['evidence_hashes'] ?? null) ? $refs['evidence_hashes'] : [];
        foreach ($supportedIds as $episodeId) {
            $episode = Memory::getByID($episodeId);
            if (!$episode instanceof Memory || $episode->tier !== 'episodic' || $episode->status !== 'active') {
                return $this->rejectConsolidationAttempt($work, 'supported_episode_missing_or_inactive');
            }
            $actualHash = hash('sha256', $this->consolidationEvidenceText($episode));
            if (($expectedHashes[(string) $episodeId] ?? null) !== $actualHash) {
                return $this->rejectConsolidationAttempt($work, 'source_evidence_changed_after_queueing');
            }
            $episodes[] = $episode;
        }
        $groundingFailure = $this->consolidationGroundingFailure($claim, $episodes);
        if ($groundingFailure !== null) {
            return $this->rejectConsolidationAttempt($work, $groundingFailure);
        }

        $activeEntries = 0;
        foreach (array_merge($supportedIds, $rejectedIds) as $episodeId) {
            $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
            if ($entry instanceof MemoryConsolidationEpisode
                && $entry->status === 'queued'
                && (int) $entry->work_item_id === $workId
            ) {
                $activeEntries++;
            }
        }
        if ($activeEntries === 0) {
            return ['status' => 'already_integrated', 'work_item_id' => $workId];
        }

        $semantic = $this->consolidationCoveredByExisting($claim, $allowedExisting);
        $normalizedClaim = $this->normalizeSearchText($claim);
        if (!$semantic instanceof Memory) {
            foreach (Memory::rankCandidates($claim, 100, 'semantic', 'active') as $candidate) {
                if ($this->normalizeSearchText((string) $candidate->content) === $normalizedClaim) {
                    $semantic = $candidate;
                    Memory::observeRecords([$candidate]);
                    break;
                }
            }
        }
        if (!$semantic instanceof Memory) {
            $primary = $episodes[0];
            $stored = $this->addMemory(
                tier: 'semantic',
                content: $claim,
                confidence: $confidence,
                idempotencyKey: 'automatic-consolidation:' . $workId,
                sourceEventId: $primary->source_event_id,
                sourceMemoryId: (int) $primary->id,
                supersedesId: null
            );
            $semantic = is_array($stored['memory'] ?? null) ? $stored['memory'] : [];
        }

        $semanticData = $semantic instanceof Memory ? $semantic->getData() : $semantic;
        $semanticId = (int) ($semanticData['id'] ?? 0);
        if ($semanticId < 1) {
            throw new RuntimeException('Stored consolidation memory receipt is invalid.');
        }

        return $this->transaction(function () use (
            $workId,
            $refs,
            $confidence,
            $supportedIds,
            $rejectedIds,
            $rejectionReason,
            $supersedes,
            $model,
            $semanticData,
            $semanticId
        ): array {
            $activeEntries = 0;
            foreach (array_merge($supportedIds, $rejectedIds) as $episodeId) {
                $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
                if ($entry instanceof MemoryConsolidationEpisode
                    && $entry->status === 'queued'
                    && (int) $entry->work_item_id === $workId
                ) {
                    $activeEntries++;
                }
            }
            if ($activeEntries === 0) {
                return ['status' => 'already_integrated', 'work_item_id' => $workId];
            }

            foreach ($supportedIds as $episodeId) {
                $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
                if ($entry instanceof MemoryConsolidationEpisode) {
                    $entry->setFields([
                        'work_item_id' => null,
                        'semantic_memory_id' => $semanticId,
                        'status' => 'consolidated',
                        'reason' => 'supported_by_grounded_claim',
                        'updated_at' => time(),
                    ]);
                    $entry->save();
                }
            }
            foreach ($rejectedIds as $episodeId) {
                $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
                if ($entry instanceof MemoryConsolidationEpisode) {
                    $entry->setFields([
                        'work_item_id' => null,
                        'status' => 'rejected',
                        'reason' => mb_substr($rejectionReason, 0, 1000),
                        'updated_at' => time(),
                    ]);
                    $entry->save();
                }
            }

            $event = $this->emit('memory.consolidated', [
                'work_item_id' => $workId,
                'episode_ids' => $supportedIds,
                'rejected_episode_ids' => $rejectedIds,
                'rejection_reason' => $rejectionReason,
                'interleaved_ids' => $refs['interleaved_ids'] ?? [],
                'supersedes_memory_id' => $supersedes,
                'memory_id' => $semanticId,
                'confidence' => $confidence,
                'model' => $model,
                'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
            ]);
            return [
                'status' => 'consolidated',
                'memory' => $semanticData,
                'supported_episode_ids' => $supportedIds,
                'rejected_episode_ids' => $rejectedIds,
                'event' => $event->getData(),
            ];
        });
    }

    /** @return list<int> */
    private function consolidationIdList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1 || isset($ids[$id])) {
                return [];
            }
            $ids[$id] = true;
        }
        $ids = array_keys($ids);
        sort($ids);
        return $ids;
    }

    /** @param list<Memory> $episodes */
    private function consolidationGroundingFailure(string $claim, array $episodes): ?string
    {
        $sourceTexts = array_map(
            fn (Memory $episode): string => mb_strtolower($this->consolidationEvidenceText($episode)),
            $episodes
        );
        $corpus = implode("\n", $sourceTexts);
        $literalPatterns = [
            '/`([^`]{2,160})`/u',
            '/"([^"\n]{2,200})"/u',
            '/(?<![\p{L}\p{N}_])(\/[-\p{L}\p{N}_.\/]+|--?[-\p{L}\p{N}_.]+(?:=[-\p{L}\p{N}_.\/]+)?)/u',
            '/(?<![\p{L}\p{N}_])([\p{L}_][\p{L}\p{N}_.-]*=[-\p{L}\p{N}_.\/]+)/u',
            '/\b\d+(?:\.\d+)+(?:[-_\p{L}\p{N}.]*)?\b/u',
            '/(?<![\p{L}\p{N}])([$€£]?\d+(?:,\d{3})*(?:\.\d+)?%?)(?![\p{L}\p{N}])/u',
        ];
        foreach ($literalPatterns as $pattern) {
            if (preg_match_all($pattern, $claim, $matches) !== false) {
                foreach ($matches[1] ?? $matches[0] as $literal) {
                    $literal = mb_strtolower(trim((string) $literal));
                    if ($literal !== '' && !str_contains($corpus, $literal)) {
                        return 'unsupported_literal:' . mb_substr($literal, 0, 120);
                    }
                }
            }
        }

        $claimTokens = array_flip($this->consolidationTokens($claim));
        if ($claimTokens === []) {
            return 'claim_has_no_groundable_terms';
        }
        $sourceTokens = [];
        foreach ($sourceTexts as $sourceText) {
            foreach ($this->consolidationTokens($sourceText) as $token) {
                $sourceTokens[$token] = true;
            }
        }
        $matched = count(array_intersect_key($claimTokens, $sourceTokens));
        if ($matched / count($claimTokens) < 0.5) {
            return 'claim_lexical_support_below_half';
        }
        foreach ($sourceTexts as $index => $sourceText) {
            $episodeTokens = array_flip($this->consolidationTokens($sourceText));
            if (count(array_intersect_key($claimTokens, $episodeTokens)) === 0) {
                return 'listed_support_does_not_support_claim:' . (int) $episodes[$index]->id;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function rejectConsolidationAttempt(array $work, string $reason): array
    {
        $workId = (int) ($work['id'] ?? 0);
        $reason = mb_substr(trim($reason), 0, 1000);
        return $this->transaction(function () use ($workId, $reason): array {
            $retryIds = [];
            $rejectedIds = [];
            foreach (MemoryConsolidationEpisode::getAllByWhere([
                'work_item_id' => $workId,
                'status' => 'queued',
            ]) as $entry) {
                $terminal = (int) $entry->attempts >= self::CONSOLIDATION_MAX_ATTEMPTS;
                $entry->setFields([
                    'work_item_id' => null,
                    'status' => $terminal ? 'rejected' : 'pending',
                    'reason' => $reason,
                    'updated_at' => time(),
                ]);
                $entry->save();
                if ($terminal) {
                    $rejectedIds[] = (int) $entry->episode_id;
                } else {
                    $retryIds[] = (int) $entry->episode_id;
                }
            }
            if ($retryIds === [] && $rejectedIds === []) {
                return ['status' => 'already_finalized', 'work_item_id' => $workId];
            }
            $event = $this->emit('memory.consolidation.attempt_rejected', [
                'work_item_id' => $workId,
                'reason' => $reason,
                'retry_episode_ids' => $retryIds,
                'rejected_episode_ids' => $rejectedIds,
                'max_attempts' => self::CONSOLIDATION_MAX_ATTEMPTS,
                'validator_version' => self::CONSOLIDATION_VALIDATOR_VERSION,
            ]);
            return [
                'status' => $retryIds === [] ? 'sources_rejected' : 'retry_scheduled',
                'reason' => $reason,
                'retry_episode_ids' => $retryIds,
                'rejected_episode_ids' => $rejectedIds,
                'event' => $event->getData(),
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    public function searchMemory(string $query, int $limit = 20): array
    {
        $this->requireText($query, 'query');
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }

        $this->proceduralMemory->recoverPendingGenerations();
        return TokenMemoryDaemon::recall($query, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function listNeeds(): array
    {
        $now = time();
        return array_values(array_map(
            fn (Need $need): array => $this->needState($need, $now),
            Need::getAll(['order' => ['need_key' => 'ASC']])
        ));
    }

    public function setNeed(
        string $key,
        string $description,
        float $pressure,
        float $growthPerHour,
        float $triggerThreshold,
        string $status,
        string $rationale,
        string $changeAuthority = 'agent'
    ): array {
        $this->requireText($key, 'need key');
        $this->requireText($description, 'need description');
        $this->requireText($rationale, 'need change rationale');
        $this->requireUnitInterval($pressure, 'need pressure');
        $this->requireUnitInterval($growthPerHour, 'need growth per hour');
        $this->requireUnitInterval($triggerThreshold, 'need trigger threshold');
        $this->requireChoice($status, ['active', 'paused'], 'need status');
        $this->requireChoice($changeAuthority, ['user', 'developer', 'system', 'agent'], 'change authority');

        return $this->transaction(function () use (
            $key,
            $description,
            $pressure,
            $growthPerHour,
            $triggerThreshold,
            $status,
            $rationale,
            $changeAuthority
        ): array {
            $need = Need::getByField('need_key', $key);
            $before = $need instanceof Need ? $need->getData() : null;

            if (!$need instanceof Need) {
                /** @var Need $need */
                $need = $this->insert(Need::class, [
                    'need_key' => $key,
                    'description' => $description,
                    'authority' => $changeAuthority,
                    'pressure' => $pressure,
                    'growth_per_hour' => $growthPerHour,
                    'trigger_threshold' => $triggerThreshold,
                    'last_satisfied_at' => time(),
                    'updated_at' => time(),
                    'status' => $status,
                ]);
            } else {
                $need->setFields([
                    'description' => $description,
                    'pressure' => $pressure,
                    'growth_per_hour' => $growthPerHour,
                    'trigger_threshold' => $triggerThreshold,
                    'updated_at' => time(),
                    'status' => $status,
                ]);
                $need->save();
            }

            $event = $this->emit('need.changed', [
                'need_id' => $need->id,
                'need_key' => $key,
                'change_authority' => $changeAuthority,
                'rationale' => $rationale,
                'before' => $before,
                'after' => $need->getData(),
            ]);

            return ['need' => $need->getData(), 'event' => $event->getData()];
        });
    }

    public function satisfyNeed(string $key, float $amount, string $source): array
    {
        $this->requireText($key, 'need key');
        $this->requireText($source, 'satisfaction source');
        $this->requireUnitInterval($amount, 'satisfaction amount');

        return $this->transaction(function () use ($key, $amount, $source): array {
            $need = Need::getByField('need_key', $key);
            if (!$need instanceof Need) {
                throw new RuntimeException(sprintf('Need %s does not exist.', $key));
            }

            return $this->satisfyNeedRecord($need, $amount, $source, time());
        });
    }

    public function tickMind(): array
    {
        $needs = $this->accrueNeeds(time());
        $triggered = array_values(array_filter(
            $needs,
            static fn (array $need): bool => $need['status'] === 'active' && $need['triggered']
        ));

        $daydream = $triggered === [] || $this->daydreamCooldownActive()
            ? null
            : $this->daydream('need pressure crossed its trigger threshold');

        return [
            'state' => $daydream === null ? 'sleeping' : 'micro_wake',
            'needs' => $needs,
            'daydream' => $daydream,
        ];
    }

    public function daydream(string $reason, ?int $runId = null): array
    {
        $this->requireText($reason, 'daydream reason');
        if ($runId !== null && !CycleRun::getByID($runId) instanceof CycleRun) {
            throw new RuntimeException(sprintf('Cycle run %d does not exist.', $runId));
        }

        return $this->transaction(function () use ($reason, $runId): array {
            $now = time();
            $needsBefore = $this->accrueNeeds($now);
            $memoriesById = [];
            foreach (['semantic', 'episodic'] as $tier) {
                foreach (Memory::rankCandidates($reason, 48, $tier, 'active') as $memory) {
                    $memoriesById[(int) $memory->id] = $memory;
                }
            }
            $memories = array_values($memoriesById);
            shuffle($memories);
            $memories = array_slice($memories, 0, self::DAYDREAM_MEMORY_LIMIT);
            Memory::observeRecords($memories);

            $sourceIds = array_values(array_map(
                static fn (Memory $memory): int => (int) $memory->id,
                $memories
            ));
            $fragments = array_values(array_filter(array_map(
                fn (Memory $memory): string => $this->randomMemoryFragment((string) $memory->content),
                $memories
            )));
            $lenses = [
                'Assume a central premise is false and follow the consequences.',
                'Treat these unrelated fragments as parts of one hidden system.',
                'Reverse cause and effect, then ask what evidence would expose the mistake.',
                'Change the scale by six orders of magnitude and see which constraints survive.',
                'Imagine this from the perspective least represented in current memory.',
                'Replace the familiar implementation with a biological, social, or astronomical analogue.',
            ];
            $lens = $lenses[random_int(0, count($lenses) - 1)];
            $rawMaterial = $fragments === []
                ? 'silence, uncertainty, and the fact that no memory was selected'
                : implode(' | ', $fragments);
            $content = sprintf(
                'Unverified daydream seed. %s Free-associate across: %s What surprising possibility appears, and which current assumption would it challenge?',
                $lens,
                $rawMaterial
            );
            if ($this->containsFirstPersonModelIdentity($content)) {
                return [
                    'state' => 'micro_wake',
                    'reason' => $reason,
                    'artifact' => null,
                    'artifact_event' => null,
                    'needs_before' => $needsBefore,
                    'satisfaction' => [],
                    'factual_status' => 'rejected_model_identity_claim',
                    'external_action_authorized' => false,
                ];
            }
            $hash = hash('sha256', 'daydream|' . implode(',', $sourceIds) . '|' . $content);
            $artifact = ThoughtArtifact::getByField('content_hash', $hash);

            if (!$artifact instanceof ThoughtArtifact) {
                /** @var ThoughtArtifact $artifact */
                $artifact = $this->insert(ThoughtArtifact::class, [
                    'run_id' => $runId,
                    'kind' => 'wandering_association',
                    'content' => $content,
                    'confidence' => 0.2,
                    'provenance' => 'daydream',
                    'source_ids' => ['memory_ids' => $sourceIds],
                    'status' => 'proposed',
                    'content_hash' => $hash,
                ]);
                $event = $this->emit('thought.proposed', [
                    'artifact_id' => $artifact->id,
                    'run_id' => $runId,
                    'kind' => $artifact->kind,
                    'provenance' => 'daydream',
                    'synthetic' => true,
                    'source_memory_ids' => $sourceIds,
                    'external_action_authorized' => false,
                ]);
            } else {
                $event = null;
            }

            $satisfaction = [];
            foreach ([
                'cognitive_stimulation' => 0.25,
                'epistemic_novelty' => 0.15,
            ] as $needKey => $amount) {
                $need = Need::getByField('need_key', $needKey);
                if ($need instanceof Need) {
                    $satisfaction[] = $this->satisfyNeedRecord(
                        $need,
                        $amount,
                        'sandboxed daydream artifact ' . $artifact->id,
                        $now
                    );
                }
            }

            return [
                'state' => 'micro_wake',
                'reason' => $reason,
                'artifact' => $artifact->getData(),
                'artifact_event' => $event?->getData(),
                'needs_before' => $needsBefore,
                'satisfaction' => $satisfaction,
                'factual_status' => 'synthetic_unverified',
                'external_action_authorized' => false,
            ];
        });
    }

    public function sleep(string $reason, ?int $runId = null): array
    {
        $this->requireText($reason, 'sleep reason');
        if ($runId !== null && !CycleRun::getByID($runId) instanceof CycleRun) {
            throw new RuntimeException(sprintf('Cycle run %d does not exist.', $runId));
        }

        $integrity = $this->quickCheck();
        if ($integrity !== ['ok']) {
            throw new RuntimeException('Sleep refused because SQLite quick_check failed: ' . implode('; ', $integrity));
        }

        $before = $this->checkpoint('sleep-before-audit');
        $started = $this->emit('sleep.started', [
            'run_id' => $runId,
            'reason' => $reason,
            'before_checkpoint_id' => $before['id'],
            'state' => 'sleeping',
        ]);

        try {
            $expiredMemories = [];
            $now = time();
            foreach (Memory::inspectAllByWhere(['tier' => 'working', 'status' => 'active']) as $memory) {
                $expiresAt = $this->timestamp($memory->expires_at);
                if ($expiresAt === null || $expiresAt > $now) {
                    continue;
                }
                $memory->setFields(['status' => 'expired', 'updated_at' => $now]);
                $memory->saveWithOperation(TokenMemoryDaemon::operationKey(
                    'sleep-expire-working-memory',
                    (string) $memory->id . ':' . (string) $expiresAt
                ));
                $expiredMemories[] = ['memory_id' => (int) $memory->id, 'expires_at' => $expiresAt];
            }
            $nonRem = $this->transaction(function () use ($expiredMemories, $now): array {
                $expired = [];
                foreach ($expiredMemories as $expiredMemory) {
                    $event = $this->emit('memory.expired', [
                        'memory_id' => $expiredMemory['memory_id'],
                        'expires_at' => $expiredMemory['expires_at'],
                        'repair_kind' => 'explicit_expiry',
                    ]);
                    $expired[] = [
                        'memory_id' => $expiredMemory['memory_id'],
                        'event_id' => $event->id,
                    ];
                }

                return [
                    'integrity' => ['sqlite_quick_check' => 'ok'],
                    'automatic_repairs' => $expired,
                    'findings' => $this->cognitiveFindings($now),
                ];
            });

            $repairArtifacts = $this->createRepairArtifacts($nonRem['findings'], $runId);
            // The compression pass. Queued, not run: sleep must never sit on a
            // model call, and the worker that picks this up is the same one
            // that does every other kind of thinking.
            $consolidation = $this->enqueueConsolidation($runId, time());
            $needs = $this->accrueNeeds(time());
            $bored = array_filter(
                $needs,
                static fn (array $need): bool => $need['status'] === 'active' && $need['triggered']
            ) !== [];
            $wandering = $bored && !$this->daydreamCooldownActive()
                ? $this->daydream('boredom surfaced during sleep replay', $runId)
                : null;

            $after = $this->checkpoint('sleep-after-audit');
            $completed = $this->emit('sleep.completed', [
                'run_id' => $runId,
                'start_event_id' => $started->id,
                'reason' => $reason,
                'before_checkpoint_id' => $before['id'],
                'after_checkpoint_id' => $after['id'],
                'non_rem' => $nonRem,
                'repair_artifact_ids' => array_column($repairArtifacts, 'id'),
                'consolidation_work_item_id' => $consolidation['work_item']['id'] ?? null,
                'daydream_artifact_id' => $wandering['artifact']['id'] ?? null,
                'state' => 'sleeping',
            ]);

            // Narrative synthesis is deliberately detached from the rhythm
            // run. Sleep queues the full evidence pass, but never waits for a
            // model or leaves a cycle open while that model is thinking.
            try {
                $narratives = (new NarrativeSynthesis($this))->enqueue(
                    'completed sleep cycle: ' . $reason,
                    (int) $completed->id
                );
            } catch (Throwable $throwable) {
                $failure = $this->emit('narrative.synthesis_queue_failed', [
                    'sleep_event_id' => $completed->id,
                    'reason' => $reason,
                    'error' => $throwable->getMessage(),
                    'experimental' => true,
                ]);
                $narratives = [
                    'status' => 'queue_failed',
                    'error' => $throwable->getMessage(),
                    'event' => $failure->getData(),
                ];
            }

            return [
                'state' => 'sleeping',
                'reason' => $reason,
                'before_checkpoint' => $before,
                'non_rem' => $nonRem,
                'rem' => [
                    'repair_artifacts' => $repairArtifacts,
                    'wandering' => $wandering,
                ],
                'narrative_synthesis' => $narratives,
                'after_checkpoint' => $after,
                'event' => $completed->getData(),
                'checkpoint_kind' => 'persistence_barrier_not_restore_image',
            ];
        } catch (Throwable $throwable) {
            $this->emit('sleep.failed', [
                'run_id' => $runId,
                'start_event_id' => $started->id,
                'before_checkpoint_id' => $before['id'],
                'error' => $throwable->getMessage(),
                'state' => 'sleeping',
            ]);
            throw $throwable;
        }
    }

    /** @return array<string, mixed> */
    public function queueNarrativeSynthesis(string $reason): array
    {
        return (new NarrativeSynthesis($this))->enqueue($reason);
    }

    public function heartbeatStatus(): array
    {
        $now = time();
        $rhythms = array_values(array_map(function (Rhythm $rhythm) use ($now): array {
            $data = $rhythm->getData();
            $dueAt = $this->timestamp($rhythm->next_due_at) ?? $now;
            $interval = max(1, (int) $rhythm->interval_seconds);
            $data['due'] = $rhythm->status !== 'paused' && $dueAt <= $now;
            $data['overdue_seconds'] = max(0, $now - $dueAt);
            $data['missed_intervals'] = max(0, intdiv(max(0, $now - $dueAt), $interval));
            return $data;
        }, Rhythm::getAll(['order' => ['interval_seconds' => 'ASC']])));

        return [
            'operational_state' => 'sleeping_between_wake_moments',
            'rhythms' => $rhythms,
            'running_cycles' => $this->records(CycleRun::getAllByWhere(
                ['status' => 'running'],
                ['order' => ['created_at' => 'ASC']]
            )),
            'queued_work' => $this->records(WorkItem::getAllByWhere(
                ['status' => 'queued'],
                ['order' => ['created_at' => 'ASC']]
            )),
            'leased_work' => $this->records(WorkItem::getAllByWhere(
                ['status' => 'leased'],
                ['order' => ['created_at' => 'ASC']]
            )),
            'cognitive_threads' => $this->records(array_values(array_filter(
                CognitiveThread::getAll(['order' => ['updated_at' => 'DESC']]),
                static fn (CognitiveThread $thread): bool => in_array(
                    $thread->status,
                    ['active', 'waiting'],
                    true
                )
            ))),
            'running_thread_steps' => $this->records(array_values(array_filter(
                ThreadStep::getAll(['order' => ['created_at' => 'ASC']]),
                static fn (ThreadStep $step): bool => in_array(
                    $step->status,
                    ['running', 'dispatching'],
                    true
                )
            ))),
            'pending_interrupts' => $this->records(ExecutiveInterrupt::getAllByWhere(
                ['status' => 'pending'],
                ['order' => ['created_at' => 'ASC']]
            )),
        ];
    }

    public function raiseInterrupt(string $reason, string $severity = 'high', string $source = 'external'): array
    {
        $this->requireText($reason, 'interrupt reason');
        $this->requireChoice($severity, ['critical', 'high', 'normal'], 'severity');
        $this->requireText($source, 'interrupt source');

        return $this->transaction(function () use ($reason, $severity, $source): array {
            /** @var ExecutiveInterrupt $interrupt */
            $interrupt = $this->insert(ExecutiveInterrupt::class, [
                'reason' => $reason,
                'severity' => $severity,
                'source' => $source,
                'status' => 'pending',
                'fencing_token' => 0,
                'updated_at' => time(),
            ]);

            $event = $this->emit('interrupt.raised', [
                'interrupt_id' => $interrupt->id,
                'severity' => $severity,
                'source' => $source,
                'reason' => $reason,
            ]);

            return ['interrupt' => $interrupt->getData(), 'event' => $event->getData()];
        });
    }

    public function acknowledgeInterrupt(int $id, string $nodeId): array
    {
        $this->requireText($nodeId, 'node id');

        return $this->transaction(function () use ($id, $nodeId): array {
            $interrupt = ExecutiveInterrupt::getByID($id);
            if (!$interrupt instanceof ExecutiveInterrupt) {
                throw new RuntimeException(sprintf('Interrupt %d does not exist.', $id));
            }
            if ($interrupt->status !== 'pending') {
                throw new RuntimeException(sprintf('Interrupt %d is already %s.', $id, $interrupt->status));
            }

            $newFence = (int) $interrupt->fencing_token + 1;
            $statement = $this->connection->prepare(
                "UPDATE executive_interrupts
                 SET status = 'acknowledged', acknowledged_by = :node,
                     acknowledged_at = :now, fencing_token = :new_fence,
                     updated_at = :now
                 WHERE id = :id AND status = 'pending'"
            );
            $statement->execute([
                ':node' => $nodeId,
                ':now' => date('Y-m-d H:i:s', time()),
                ':new_fence' => $newFence,
                ':id' => $id,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Interrupt claim lost.');
            }

            $interrupt = ExecutiveInterrupt::getByID($id);
            $event = $this->emit('interrupt.acknowledged', [
                'interrupt_id' => $id,
                'node_id' => $nodeId,
                'fencing_token' => $newFence,
            ]);

            return ['interrupt' => $interrupt->getData(), 'event' => $event->getData()];
        });
    }

    /**
     * Findings that justify stopping everything else until they are dealt with.
     *
     * Only damage to the substrate qualifies. A corrupt database means every
     * later thought is built on something untrustworthy, so continuing is worse
     * than halting. Everything else — including work that has sat untouched for
     * days — is a thing to notice, not a reason to stop being able to notice.
     *
     * @var list<string>
     */
    private const HALTING_FINDINGS = ['integrity_failure'];

    public function runSafetyCheck(): ?array
    {
        $now = time();
        $findings = [];

        $integrity = $this->periodicQuickCheck($now);
        if ($integrity !== ['ok']) {
            $findings[] = [
                'kind' => 'integrity_failure',
                'lizard_brain' => true,
                'description' => 'SQLite quick_check failed: ' . implode('; ', $integrity),
            ];
        }

        // A pending action past twice the stale threshold is genuinely worth
        // raising, but it is a standing condition rather than an emergency: it
        // cannot resolve itself, and nothing clears it except a cycle deciding
        // what actually happened. Preempting every interrupt for it deadlocked
        // exactly that — the alarm starved the only work that could end it.
        // cognitiveFindings() already surfaces the same condition for review.
        foreach (ActionTrace::getAllByWhere(['status' => 'pending']) as $action) {
            $createdAt = $this->timestamp($action->created_at);
            if ($createdAt !== null && $createdAt < $now - self::STALE_ACTION_SECONDS * 2) {
                $findings[] = [
                    'kind' => 'stale_action_critical',
                    'lizard_brain' => false,
                    'description' => sprintf('Action %d pending for over %d seconds.', $action->id, self::STALE_ACTION_SECONDS * 2),
                    'action_id' => $action->id,
                ];
            }
        }

        if ($findings === []) {
            return null;
        }

        $halting = array_values(array_filter(
            $findings,
            static fn (array $f): bool => in_array($f['kind'], self::HALTING_FINDINGS, true)
        ));

        // Latch on the finding set. An unchanged condition is already known, so
        // re-emitting it every heartbeat buries the history it belongs to
        // instead of adding to it. A new or cleared finding is news and emits.
        $signature = hash('sha256', json_encode(
            array_map(
                static fn (array $f): string => $f['kind'] . ':' . (string) ($f['action_id'] ?? $f['description']),
                $findings
            ),
            JSON_THROW_ON_ERROR
        ));
        $previous = Event::getByWhere(['kind' => 'safety.alert'], ['order' => ['id' => 'DESC']]);
        $known = $previous instanceof Event
            && is_array($previous->payload)
            && ($previous->payload['signature'] ?? null) === $signature;

        $event = null;
        if (!$known) {
            $event = $this->emit('safety.alert', [
                'findings' => $findings,
                'signature' => $signature,
                'timestamp' => $now,
            ]);
        }

        if ($halting === [] && $known) {
            // Nothing new, nothing that halts: Navi already knows, so let the
            // cycle get on with the work that might resolve it.
            return null;
        }

        return [
            'status' => 'safety_alert',
            'preempt_any_interrupt' => $halting !== [],
            'findings' => $findings,
            'event' => $event?->getData(),
        ];
    }

    public function checkExecutiveInterrupts(string $nodeId): ?array
    {
        $this->requireText($nodeId, 'node id');

        $safety = $this->runSafetyCheck();
        if ($safety !== null) {
            return $safety;
        }

        $pending = ExecutiveInterrupt::getAllByWhere(
            ['status' => 'pending'],
            ['order' => ['created_at' => 'ASC']]
        );

        $critical = array_values(array_filter(
            $pending,
            static fn (ExecutiveInterrupt $i): bool => $i->severity === 'critical'
        ));

        if ($critical !== []) {
            $interrupt = $critical[0];
            $ack = $this->acknowledgeInterrupt((int) $interrupt->id, $nodeId);
            return [
                'status' => 'critical_interrupt_claimed',
                'interrupt' => $ack['interrupt'],
                'event' => $ack['event'],
                'trigger_immediate_wake' => true,
            ];
        }

        $high = array_values(array_filter(
            $pending,
            static fn (ExecutiveInterrupt $i): bool => $i->severity === 'high'
        ));

        if ($high !== [] && !$this->highBrainBusy()) {
            $interrupt = $high[0];
            $ack = $this->acknowledgeInterrupt((int) $interrupt->id, $nodeId);
            return [
                'status' => 'high_interrupt_claimed',
                'interrupt' => $ack['interrupt'],
                'event' => $ack['event'],
                'trigger_immediate_wake' => true,
            ];
        }

        if ($pending !== []) {
            return [
                'status' => 'interrupts_pending_deferred',
                'pending_count' => count($pending),
                'trigger_immediate_wake' => false,
            ];
        }

        return null;
    }

    public function resolveInterrupt(int $id, string $nodeId, ?int $runId = null): array
    {
        $this->requireText($nodeId, 'node id');

        return $this->transaction(function () use ($id, $nodeId, $runId): array {
            $interrupt = ExecutiveInterrupt::getByID($id);
            if (!$interrupt instanceof ExecutiveInterrupt) {
                throw new RuntimeException(sprintf('Interrupt %d does not exist.', $id));
            }
            if ($interrupt->status !== 'acknowledged') {
                throw new RuntimeException(sprintf('Interrupt %d is not acknowledged.', $id));
            }
            if ($interrupt->acknowledged_by !== $nodeId) {
                throw new RuntimeException('Only the acknowledging node can resolve an interrupt.');
            }

            $interrupt->setFields([
                'status' => 'resolved',
                'resolution_run_id' => $runId,
                'updated_at' => time(),
            ]);
            $interrupt->save();

            $event = $this->emit('interrupt.resolved', [
                'interrupt_id' => $id,
                'node_id' => $nodeId,
                'resolution_run_id' => $runId,
            ]);

            return ['interrupt' => $interrupt->getData(), 'event' => $event->getData()];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listInterrupts(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['pending', 'acknowledged', 'resolved'], 'status');
            return $this->records(ExecutiveInterrupt::getAllByWhere(
                ['status' => $status],
                ['order' => ['created_at' => 'DESC']]
            ));
        }
        return $this->records(ExecutiveInterrupt::getAll(['order' => ['created_at' => 'DESC']]));
    }

    public function runDueHeartbeats(string $nodeId): array
    {
        $this->requireText($nodeId, 'node id');
        $now = time();
        $recoveredCycles = $this->recoverExpiredCycleLeases($now);
        $due = array_values(array_filter(
            Rhythm::getAll(),
            fn (Rhythm $rhythm): bool => in_array($rhythm->status, ['idle', 'failed'], true)
                && ($this->timestamp($rhythm->next_due_at) ?? PHP_INT_MAX) <= $now
        ));
        usort($due, static function (Rhythm $left, Rhythm $right): int {
            if ($left->cognitive_layer !== $right->cognitive_layer) {
                return $left->cognitive_layer === CognitiveLayer::LOW->value ? -1 : 1;
            }
            return (int) $right->interval_seconds <=> (int) $left->interval_seconds;
        });

        $results = [];
        foreach ($due as $rhythm) {
            $results[] = $this->runHeartbeat((string) $rhythm->rhythm_key, $nodeId);
        }

        return [
            'node_id' => $nodeId,
            'recovered_cycle_ids' => $recoveredCycles,
            'fired' => $results,
            'status' => $this->heartbeatStatus(),
        ];
    }

    /** @return array<string, mixed> */
    public function runDueCognitiveThreads(string $nodeId): array
    {
        $this->requireText($nodeId, 'node id');
        $now = time();
        $recoveredSteps = $this->recoverAbandonedCognitiveThreadSteps($now);
        $reconciled = $this->reconcileCognitiveThreadResults();
        $indeterminate = $this->recoverIndeterminateDispatches();

        $claim = $this->transaction(function () use ($now, $nodeId): ?array {
            $statement = $this->connection->prepare(
                "UPDATE cognitive_threads
                 SET status = 'active', phase = 'evaluating', wake_at = NULL,
                     version = version + 1, fencing_token = fencing_token + 1,
                     updated_at = :now
                 WHERE id = (
                     SELECT id FROM cognitive_threads
                     WHERE status IN ('active', 'waiting')
                       AND wake_at IS NOT NULL AND wake_at <= :now
                     ORDER BY CASE phase WHEN 'interrupted' THEN 0 ELSE 1 END,
                              wake_at ASC, id ASC LIMIT 1
                 )
                   AND status IN ('active', 'waiting')
                   AND wake_at IS NOT NULL AND wake_at <= :now
                 RETURNING id"
            );
            $statement->execute([':now' => date('Y-m-d H:i:s', $now)]);
            $id = $statement->fetchColumn();
            if ($id === false) {
                return null;
            }
            $thread = $this->requireCognitiveThread((int) $id);

            /** @var ThreadStep $step */
            $step = $this->insert(ThreadStep::class, [
                'thread_id' => (int) $thread->id,
                'operation' => (string) $thread->next_operation,
                'expected' => (string) $thread->expected_postcondition,
                'pre_state' => [
                    'thread_version' => (int) $thread->version,
                    'thread_phase' => (string) $thread->phase,
                    'claimed_by' => $nodeId,
                    'claimed_at' => $now,
                ],
                'proposal' => [],
                'deterministic_checks' => [],
                'curator_verdict' => 'pending',
                'observed_result' => [],
                'post_state' => [],
                'status' => 'running',
                'fencing_token' => (int) $thread->fencing_token,
            ]);
            $this->emit('thread.step.started', [
                'thread_id' => $thread->id,
                'thread_step_id' => $step->id,
                'thread_key' => $thread->thread_key,
                'operation' => $step->operation,
                'fencing_token' => $step->fencing_token,
                'node_id' => $nodeId,
            ]);
            return ['thread' => $thread, 'step' => $step];
        });

        if ($claim === null) {
            return [
                'status' => 'idle',
                'node_id' => $nodeId,
                'recovered_thread_step_ids' => $recoveredSteps,
                'reconciled' => $reconciled,
                'indeterminate_dispatches' => $indeterminate,
            ];
        }

        /** @var CognitiveThread $thread */
        $thread = $claim['thread'];
        /** @var ThreadStep $step */
        $step = $claim['step'];
        $scheduler = [
            'node_id' => $nodeId,
            'recovered_thread_step_ids' => $recoveredSteps,
            'reconciled' => $reconciled,
            'indeterminate_dispatches' => $indeterminate,
        ];

        try {
            if ($thread->thread_key !== self::SELF_PRESENCE_THREAD_KEY
                && $thread->thread_key !== self::EPISTEMIC_ADVANCE_THREAD_KEY
                && $thread->thread_key !== self::MIND_STREAM_THREAD_KEY
            ) {
                return array_merge(
                    $scheduler,
                    $this->recordThreadWait(
                        $thread,
                        $step,
                        'unsupported_thread_type',
                        $now + 3600,
                        'No bounded executor is registered for this thread key.'
                    )
                );
            }

            $earliestDispatch = $this->earliestWorkerDispatchAt($thread, $now);
            if ($earliestDispatch > $now) {
                return array_merge(
                    $scheduler,
                    $this->recordThreadWait(
                        $thread,
                        $step,
                        'worker_rate_limited',
                        $earliestDispatch,
                        sprintf(
                            'The previous model dispatch for this thread was under %d seconds ago. This thread retains its configured dispatch floor.',
                            $this->threadBudgetInt($thread, 'min_worker_interval_seconds', self::MIN_WORKER_INTERVAL_SECONDS)
                        )
                    )
                );
            }

            if ($thread->thread_key === self::MIND_STREAM_THREAD_KEY) {
                return array_merge(
                    $scheduler,
                    $this->evaluateMindStreamThread($thread, $step, $now)
                );
            }

            if ($thread->thread_key === self::EPISTEMIC_ADVANCE_THREAD_KEY) {
                return array_merge(
                    $scheduler,
                    $this->evaluateEpistemicAdvanceThread($thread, $step, $now)
                );
            }

            return array_merge(
                $scheduler,
                $this->evaluateSelfPresenceThread($thread, $step, $now)
            );
        } catch (Throwable $throwable) {
            return array_merge(
                $scheduler,
                $this->recordThreadWait(
                    $thread,
                    $step,
                    'evaluation_error',
                    time() + 900,
                    $throwable->getMessage(),
                    true
                )
            );
        }
    }

    public function runHeartbeat(string $rhythmKey, string $nodeId): array
    {
        $this->requireText($rhythmKey, 'rhythm key');
        $this->requireText($nodeId, 'node id');
        $this->recoverExpiredCycleLeases(time());
        $rhythm = Rhythm::getByField('rhythm_key', $rhythmKey);
        if (!$rhythm instanceof Rhythm) {
            throw new RuntimeException(sprintf('Rhythm %s does not exist.', $rhythmKey));
        }
        if ($rhythm->status === 'paused') {
            return ['rhythm' => $rhythmKey, 'status' => 'paused'];
        }
        if ($rhythm->status === 'running') {
            return [
                'rhythm' => $rhythmKey,
                'status' => 'already_running',
                'lease_owner' => $rhythm->lease_owner,
                'lease_expires_at' => $this->timestamp($rhythm->lease_expires_at),
            ];
        }

        $now = time();
        $dueAt = $this->timestamp($rhythm->next_due_at) ?? PHP_INT_MAX;
        if ($dueAt > $now) {
            return ['rhythm' => $rhythmKey, 'status' => 'not_due', 'next_due_at' => $dueAt];
        }

        if ($rhythm->cognitive_layer === CognitiveLayer::HIGH->value && $this->highBrainBusy()) {
            return $this->coalesceHighRhythm($rhythm, $now);
        }

        $leaseSeconds = match ($rhythmKey) {
            'pulse_30s' => 20,
            'intentions_10m' => 60,
            'decide_1m' => 300,
            'reflect_5m' => 240,
            'consolidate_hourly' => 600,
            'sleep_daily' => 1200,
            default => 120,
        };
        $claim = $this->claimRhythm($rhythm, $nodeId, $now, $leaseSeconds);
        if ($claim === null) {
            return ['rhythm' => $rhythmKey, 'status' => 'claim_lost'];
        }

        /** @var CycleRun $run */
        $run = $claim['run'];
        try {
            if ($rhythm->cognitive_layer === CognitiveLayer::LOW->value) {
                if ($rhythmKey === 'intentions_10m') {
                    $work = (new NarrativeSynthesis($this))->enqueueIntentions(
                        'Scheduled ten-minute reconsideration of open intentions.',
                        (int) $run->id
                    );
                    return $this->completeCycleRun($run, $rhythm, $nodeId, [
                        'state' => 'intention_narrative_queued',
                        'work_item' => $work['work_item'] ?? null,
                    ]);
                }
                $output = $this->lowBrainPulse($run);
                return $this->completeCycleRun($run, $rhythm, $nodeId, $output);
            }

            $output = $this->prepareHighBrainMoment($run, $rhythm);
            if (($output['work_item'] ?? null) === null) {
                return $this->completeCycleRun($run, $rhythm, $nodeId, $output);
            }

            return [
                'rhythm' => $rhythmKey,
                'status' => 'wake_moment_queued',
                'run' => $run->getData(),
                'output' => $output,
            ];
        } catch (Throwable $throwable) {
            $this->failCycleRun($run, $rhythm, $nodeId, $throwable->getMessage());
            throw $throwable;
        }
    }

    public function enqueueWork(
        ?int $parentRunId,
        ?int $parentIntentionId,
        string $workType,
        string $prompt,
        array $inputRefs,
        int $tokenBudget,
        int $wallBudgetSeconds,
        string $idempotencyKey,
        int $depth = 0,
        int $maxDepth = 0,
        array $allowedActions = []
    ): array {
        if ($parentRunId !== null && !CycleRun::getByID($parentRunId) instanceof CycleRun) {
            throw new RuntimeException(sprintf('Cycle run %d does not exist.', $parentRunId));
        }
        if ($parentIntentionId !== null) {
            $this->requireIntention($parentIntentionId);
        }
        $this->requireText($workType, 'work type');
        $this->requireText($prompt, 'work prompt');
        $this->requireText($idempotencyKey, 'work idempotency key');
        if ($tokenBudget < 1 || $tokenBudget > 8192) {
            throw new InvalidArgumentException('token budget must be between 1 and 8192.');
        }
        if ($wallBudgetSeconds < 1 || $wallBudgetSeconds > 3600) {
            throw new InvalidArgumentException('wall budget must be between 1 and 3600 seconds.');
        }
        if ($depth < 0 || $maxDepth < 0 || $depth > $maxDepth) {
            throw new InvalidArgumentException('work depth must be non-negative and no greater than max depth.');
        }
        foreach ($allowedActions as $allowedAction) {
            if (!is_string($allowedAction) || trim($allowedAction) === '') {
                throw new InvalidArgumentException('allowed actions must be non-empty strings.');
            }
        }
        $allowedActions = array_values(array_unique($allowedActions));

        // Every model-backed cognition path receives the same typed state hub.
        // Callers may still add task-specific evidence, but none silently runs
        // against an unrelated prompt-local memory anymore.
        // Consolidation is an evidence-fenced replay. Appending the live
        // workspace here lets a salient but unrelated thought become the
        // model's claim even though its episode ID is absent from the batch.
        $evidenceFenced = in_array($workType, [
            self::MEMORY_CONSOLIDATION_WORK_TYPE,
            NarrativeSynthesis::PERSONALITY_WORK_TYPE,
            NarrativeSynthesis::MOTIVATION_WORK_TYPE,
            NarrativeSynthesis::INTENTION_WORK_TYPE,
            PublicReflection::WORK_TYPE,
        ], true);
        $workingContext = $evidenceFenced
            ? []
            : $this->workingMemory->contextForWork($parentIntentionId, $inputRefs);
        if ($workingContext !== []) {
            $workingCanonical = json_encode(
                $workingContext,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $prompt .= "\n\nActive working memory (bounded typed state; claims retain their labels and provenance):\n"
                . PlainText::render($workingContext, 12000, 20);
            $inputRefs['working_memory_checksum'] = hash('sha256', $workingCanonical);
            $inputRefs['working_memory_roles'] = array_values(array_map(
                static fn (array $slot): string => (string) ($slot['slot_role'] ?? ''),
                $workingContext
            ));
        }

        // Sensation, motivation, need pressure, and affect are causal inputs to
        // background cognition, not decorative status screens. Capture them at
        // queue time so the worker receives the state that caused this job and
        // the work ledger retains a checksum of that exact projection.
        // Consolidation and intention synthesis remain evidence-fenced:
        // unrelated live state must not become a factual memory claim or be
        // mistaken for an open commitment.
        if (!$evidenceFenced) {
            $backgroundCompiler = new BackgroundStateCompiler($this);
            $backgroundState = $backgroundCompiler->compile($prompt);
            $backgroundCanonical = json_encode(
                $backgroundState,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $prompt .= "\n\nLive background cognition state:\n"
                . $backgroundCompiler->render($backgroundState);
            $inputRefs['background_state_protocol'] = BackgroundStateCompiler::PROTOCOL;
            $inputRefs['background_state_checksum'] = hash('sha256', $backgroundCanonical);
            $inputRefs['background_state_captured_at'] = $backgroundState['captured_at'];
            $inputRefs['background_state_sections'] = [
                'sensory_state',
                'motivational_state',
                'needs',
                'emotional_state',
            ];
        }
        // Source-code evidence must survive byte-for-byte, including syntax.
        if ($workType !== PublicReflection::WORK_TYPE) {
            $prompt = PlainText::sanitize($prompt);
        }

        return $this->transaction(function () use (
            $parentRunId,
            $parentIntentionId,
            $workType,
            $prompt,
            $inputRefs,
            $tokenBudget,
            $wallBudgetSeconds,
            $idempotencyKey,
            $depth,
            $maxDepth,
            $allowedActions
        ): array {
            $existing = WorkItem::getByField('idempotency_key', $idempotencyKey);
            if ($existing instanceof WorkItem) {
                return ['work_item' => $existing->getData(), 'deduplicated' => true];
            }

            // A clock tick or manual nonce is not new evidence. Keep the trigger
            // key for exact retries, but reuse the latest identical synthesis.
            // Failed/cancelled work may be retried by a new trigger; a changed
            // synthesis prompt also creates new work. This check and insertion
            // share the queue transaction, not a racy producer-side preflight.
            if (NarrativeSynthesis::isWorkType($workType)
                && is_string($inputRefs['synthesis_checksum'] ?? null)) {
                $latest = WorkItem::getByWhere(['work_type' => $workType], ['order' => ['id' => 'DESC']]);
                if ($latest instanceof WorkItem
                    && in_array($latest->status, ['queued', 'leased', 'completed'], true)
                    && ($latest->input_refs['synthesis_checksum'] ?? null) === $inputRefs['synthesis_checksum']) {
                    return ['work_item' => $latest->getData(), 'deduplicated' => true];
                }
            }

            /** @var WorkItem $work */
            $work = $this->insert(WorkItem::class, [
                'parent_run_id' => $parentRunId,
                'parent_intention_id' => $parentIntentionId,
                'work_type' => $workType,
                'prompt' => $prompt,
                'input_refs' => $inputRefs,
                'allowed_actions' => $allowedActions,
                'token_budget' => $tokenBudget,
                'wall_budget_seconds' => $wallBudgetSeconds,
                'depth' => $depth,
                'max_depth' => $maxDepth,
                'idempotency_key' => $idempotencyKey,
                'fencing_token' => 0,
                'attempts' => 0,
                'status' => 'queued',
                'result' => [],
                'updated_at' => time(),
            ]);
            $event = $this->emit('work.queued', [
                'work_item_id' => $work->id,
                'parent_run_id' => $parentRunId,
                'parent_intention_id' => $parentIntentionId,
                'work_type' => $workType,
                'allowed_actions' => $allowedActions,
                'token_budget' => $tokenBudget,
                'wall_budget_seconds' => $wallBudgetSeconds,
                'depth' => $depth,
                'max_depth' => $maxDepth,
            ]);

            return ['work_item' => $work->getData(), 'event' => $event->getData(), 'deduplicated' => false];
        });
    }

    public function claimWork(
        string $owner,
        int $leaseSeconds = 180,
        array $excludedWorkTypes = [],
        array $includedWorkTypes = []
    ): ?array
    {
        $this->requireText($owner, 'worker owner');
        if ($leaseSeconds < 10 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('work lease must be between 10 and 3600 seconds.');
        }
        foreach ($excludedWorkTypes as $workType) {
            if (!is_string($workType) || trim($workType) === '') {
                throw new InvalidArgumentException('excluded work types must be non-empty strings.');
            }
        }
        $excludedWorkTypes = array_values(array_unique($excludedWorkTypes));
        foreach ($includedWorkTypes as $workType) {
            if (!is_string($workType) || trim($workType) === '') {
                throw new InvalidArgumentException('included work types must be non-empty strings.');
            }
        }
        $includedWorkTypes = array_values(array_unique($includedWorkTypes));

        return $this->transaction(function () use (
            $owner,
            $leaseSeconds,
            $excludedWorkTypes,
            $includedWorkTypes
        ): ?array {
            $now = time();
            $this->recoverExpiredWorkLeases($now);
            $parameters = [
                ':owner' => $owner,
                ':lease_expires' => date('Y-m-d H:i:s', $now + $leaseSeconds),
                ':now' => date('Y-m-d H:i:s', $now),
            ];
            $eligible = "status = 'queued'";
            if ($excludedWorkTypes !== []) {
                $placeholders = [];
                foreach ($excludedWorkTypes as $index => $workType) {
                    $placeholder = ':excluded_work_type_' . $index;
                    $placeholders[] = $placeholder;
                    $parameters[$placeholder] = $workType;
                }
                $eligible .= ' AND work_type NOT IN (' . implode(', ', $placeholders) . ')';
            }
            if ($includedWorkTypes !== []) {
                $placeholders = [];
                foreach ($includedWorkTypes as $index => $workType) {
                    $placeholder = ':included_work_type_' . $index;
                    $placeholders[] = $placeholder;
                    $parameters[$placeholder] = $workType;
                }
                $eligible .= ' AND work_type IN (' . implode(', ', $placeholders) . ')';
            }
            $statement = $this->connection->prepare(
                "UPDATE work_items
                 SET status = 'leased', lease_owner = :owner,
                     lease_expires_at = :lease_expires,
                     fencing_token = fencing_token + 1,
                     attempts = attempts + 1, updated_at = :now, error = NULL
                 WHERE id = (
                     SELECT id FROM work_items
                     WHERE {$eligible}
                     ORDER BY CASE work_type
                         WHEN 'self_presence_answer' THEN 0
                         ELSE 1
                     END, created_at ASC LIMIT 1
                 ) AND status = 'queued'
                 RETURNING id"
            );
            $statement->execute($parameters);
            $id = $statement->fetchColumn();
            if ($id === false) {
                return null;
            }

            $work = WorkItem::getByID((int) $id);
            if (!$work instanceof WorkItem) {
                throw new RuntimeException('Claimed work item could not be reloaded.');
            }
            $this->extendParentRhythmLease($work, $now + $leaseSeconds + 30);
            $event = $this->emit('work.claimed', [
                'work_item_id' => $work->id,
                'owner' => $owner,
                'fencing_token' => $work->fencing_token,
                'lease_expires_at' => $work->lease_expires_at,
                'attempt' => $work->attempts,
            ]);

            return ['work_item' => $work->getData(), 'event' => $event->getData()];
        });
    }

    /** Transport backoff is not a rejected proposal or rejected source evidence. */
    public function deferWork(int $workId, string $owner, int $fencingToken, int $retryAt): void
    {
        $this->transaction(function () use ($workId, $owner, $fencingToken, $retryAt): void {
            $work = WorkItem::getByID($workId);
            if (!$work instanceof WorkItem || $work->status !== 'leased'
                || $work->lease_owner !== $owner || (int) $work->fencing_token !== $fencingToken
                || ($this->timestamp($work->lease_expires_at) ?? 0) < time()) {
                throw new RuntimeException('Worker lease is stale; deferral was rejected by the fencing check.');
            }
            // Existing lease recovery requeues this work at retryAt, with a new
            // fence on the next claim. No failed-work curator runs here.
            $retryAt = max(time() + 1, $retryAt);
            $work->setFields(['lease_expires_at' => $retryAt, 'updated_at' => time()]);
            $work->save();
            $this->extendParentRhythmLease($work, $retryAt + 30);
            $this->emit('work.rate_limited', ['work_item_id' => $workId, 'retry_at' => $retryAt]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function listWorkItems(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['queued', 'leased', 'completed', 'failed', 'cancelled'], 'status');
            return $this->records(WorkItem::getAllByWhere(
                ['status' => $status],
                ['order' => ['created_at' => 'ASC']]
            ));
        }
        return $this->records(WorkItem::getAll(['order' => ['created_at' => 'ASC']]));
    }

    /** @return list<array<string, mixed>> */
    public function listThoughtArtifacts(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['proposed', 'accepted', 'rejected', 'expired'], 'status');
            return $this->records(ThoughtArtifact::getAllByWhere(
                ['status' => $status],
                ['order' => ['created_at' => 'DESC']]
            ));
        }
        return $this->records(ThoughtArtifact::getAll(['order' => ['created_at' => 'DESC']]));
    }

    /** @param array<string, mixed> $result */
    public function finishWork(
        int $workId,
        string $owner,
        int $fencingToken,
        bool $succeeded,
        array $result,
        ?string $model,
        ?string $error = null
    ): array {
        $this->requireText($owner, 'worker owner');
        if ($succeeded && ($model === null || trim($model) === '')) {
            throw new InvalidArgumentException('Successful work requires a model identifier.');
        }
        if (!$succeeded && ($error === null || trim($error) === '')) {
            throw new InvalidArgumentException('Failed work requires an error.');
        }

        [$completed, $workingProjection] = $this->transaction(function () use (
            $workId,
            $owner,
            $fencingToken,
            $succeeded,
            $result,
            $model,
            $error
        ): array {
            $work = WorkItem::getByID($workId);
            if (!$work instanceof WorkItem) {
                throw new RuntimeException(sprintf('Work item %d does not exist.', $workId));
            }
            if ($work->status !== 'leased'
                || $work->lease_owner !== $owner
                || (int) $work->fencing_token !== $fencingToken
                || ($this->timestamp($work->lease_expires_at) ?? 0) < time()
            ) {
                throw new RuntimeException('Worker lease is stale; completion was rejected by the fencing check.');
            }

            $now = time();
            $artifact = null;
            $workingProjection = null;
            if ($succeeded) {
                $this->validateWorkerProposal($result, (string) $work->work_type);
                $content = json_encode(
                    $result,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
                $hash = hash('sha256', 'worker|' . $work->id . '|' . $content);
                $artifact = ThoughtArtifact::getByField('content_hash', $hash);
                if (!$artifact instanceof ThoughtArtifact) {
                    /** @var ThoughtArtifact $artifact */
                    $artifact = $this->insert(ThoughtArtifact::class, [
                        'run_id' => $work->parent_run_id,
                        'kind' => (string) $result['kind'],
                        'content' => (string) $result['content'],
                        'confidence' => (float) $result['confidence'],
                        'provenance' => 'worker',
                        'source_ids' => [
                            'work_item_id' => $work->id,
                            'run_id' => $work->parent_run_id,
                            'input_refs' => $work->input_refs,
                        ],
                        'status' => 'proposed',
                        'content_hash' => $hash,
                    ]);
                    $this->emit('thought.proposed', [
                        'artifact_id' => $artifact->id,
                        'run_id' => $work->parent_run_id,
                        'work_item_id' => $work->id,
                        'provenance' => 'worker',
                        'synthetic' => true,
                        'model' => $model,
                        'external_action_authorized' => false,
                    ]);
                }
                // Parser, social-label, and consolidation proposals stay in
                // their dedicated curators. An ungrounded consolidation draft
                // must never enter working memory before its source check.
                if (!in_array($work->work_type, [
                    OtherModel::WORK_TYPE,
                    SocialFeedback::WORK_TYPE,
                    self::MEMORY_CONSOLIDATION_WORK_TYPE,
                    self::MIND_STREAM_WORK_TYPE,
                    NarrativeSynthesis::PERSONALITY_WORK_TYPE,
                    NarrativeSynthesis::MOTIVATION_WORK_TYPE,
                    NarrativeSynthesis::INTENTION_WORK_TYPE,
                    PublicReflection::WORK_TYPE,
                ], true)) {
                    $workingProjection = [
                        'role' => 'reasoning_result',
                        'claim' => sprintf(
                            'Uncurated %s proposal: %s',
                            (string) $result['kind'],
                            (string) $result['content']
                        ),
                        'record_type' => 'thought_artifact',
                        'record_id' => $artifact instanceof ThoughtArtifact ? (int) $artifact->id : null,
                        'confidence' => (float) $result['confidence'],
                        'ttl_seconds' => 900,
                    ];
                }
            }

            $work->setFields([
                'completed_at' => $now,
                'updated_at' => $now,
                'status' => $succeeded ? 'completed' : 'failed',
                'model' => $model,
                'result' => $result,
                'error' => $error,
                'lease_owner' => null,
                'lease_expires_at' => null,
            ]);
            $work->save();
            $event = $this->emit($succeeded ? 'work.completed' : 'work.failed', [
                'work_item_id' => $work->id,
                'parent_run_id' => $work->parent_run_id,
                'model' => $model,
                'artifact_id' => $artifact?->id,
                'error' => $error,
            ]);

            if ($work->parent_run_id !== null) {
                $run = CycleRun::getByID((int) $work->parent_run_id);
                if ($run instanceof CycleRun && $run->status === 'running') {
                    $rhythm = Rhythm::getByID((int) $run->rhythm_id);
                    if ($rhythm instanceof Rhythm) {
                        if ($succeeded) {
                            $output = $run->output;
                            $output['worker'] = [
                                'work_item_id' => $work->id,
                                'artifact_id' => $artifact?->id,
                                'model' => $model,
                            ];
                            $this->completeCycleRun($run, $rhythm, (string) $run->node_id, $output);
                        } else {
                            $this->failCycleRun(
                                $run,
                                $rhythm,
                                (string) $run->node_id,
                                (string) $error
                            );
                        }
                    }
                }
            }

            return [[
                'work_item' => $work->getData(),
                'artifact' => $artifact?->getData(),
                'event' => $event->getData(),
            ], $workingProjection];
        });
        if (is_array($workingProjection)) {
            $this->workingMemory->publish(
                role: (string) $workingProjection['role'],
                claim: (string) $workingProjection['claim'],
                recordType: (string) $workingProjection['record_type'],
                recordId: $workingProjection['record_id'] === null
                    ? null
                    : (int) $workingProjection['record_id'],
                confidence: (float) $workingProjection['confidence'],
                ttlSeconds: (int) $workingProjection['ttl_seconds']
            );
        }
        return $completed;
    }

    /**
     * Curate a completed deny-all worker proposal into the durable thread.
     * The model never receives or controls the actuator; only this fixed path
     * can turn an accepted proposal into local Pet speech.
     *
     * @param array<string, mixed> $work
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function integrateWorkerResult(array $work, array $proposal, ?string $model): array
    {
        if (($work['work_type'] ?? null) === OtherModel::WORK_TYPE) {
            return $this->otherModel->integrateParserProposal($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === SocialFeedback::WORK_TYPE) {
            return $this->socialFeedback()->integrateReflection($work, $proposal);
        }
        if (($work['work_type'] ?? null) === DecisionStateMachine::WORK_TYPE) {
            return $this->decisionStateMachine->integrate($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === self::MIND_STREAM_WORK_TYPE) {
            return $this->integrateMindStreamThought($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === self::EPISTEMIC_ADVANCE_WORK_TYPE) {
            return $this->integrateEpistemicAdvanceResult($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === self::MEMORY_CONSOLIDATION_WORK_TYPE) {
            return $this->integrateConsolidation($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === self::LOOK_WORK_TYPE) {
            return $this->integrateLookProposal($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === self::COMPLETION_WORK_TYPE) {
            return $this->integrateCompletionCheck($work, $proposal, $model);
        }
        if (NarrativeSynthesis::isWorkType((string) ($work['work_type'] ?? ''))) {
            return $this->integrateNarrativeSynthesis($work, $proposal, $model);
        }
        $workType = (string) ($work['work_type'] ?? '');
        if (!self::isSelfPresenceWorkType($workType)) {
            return ['status' => 'not_applicable'];
        }

        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $threadId = (int) ($refs['thread_id'] ?? 0);
        $stepId = (int) ($refs['thread_step_id'] ?? 0);
        $workId = (int) ($work['id'] ?? 0);
        $thread = $this->requireCognitiveThread($threadId);
        $step = $this->requireThreadStep($stepId);
        if ((int) $step->thread_id !== $threadId) {
            throw new RuntimeException('Worker result references a thread step from another thread.');
        }
        if (in_array($step->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return ['status' => 'already_integrated', 'thread_step' => $step->getData()];
        }
        if ((int) $step->worker_work_item_id !== $workId
            || (int) $step->fencing_token !== (int) ($refs['thread_fencing_token'] ?? -1)
            || (int) $thread->fencing_token !== (int) $step->fencing_token
        ) {
            throw new RuntimeException('Worker result was rejected by the cognitive-thread fencing check.');
        }

        $validation = $this->validateSelfPresenceProposal(
            $proposal,
            $thread,
            $workType === self::SELF_PRESENCE_ANSWER_WORK_TYPE
        );
        if (!$validation['accepted']) {
            return $this->rejectSelfPresenceProposal(
                $thread,
                $step,
                $workId,
                $proposal,
                $validation['checks'],
                $validation['reason']
            );
        }

        $normalized = $validation['proposal'];
        // An ordinary background-speech proposal was composed without the new
        // utterance in view. Never let that stale proposal become the answer;
        // finish this step and immediately re-wake the thread around the speech.
        if ($workType === self::SELF_PRESENCE_SPEECH_WORK_TYPE
            && $this->pendingAddressedEvents() !== []
        ) {
            $checks = $validation['checks'];
            $checks['not_superseded_by_addressed_speech'] = false;
            return $this->rejectSelfPresenceProposal(
                $thread,
                $step,
                $workId,
                $normalized,
                $checks,
                'Addressed speech arrived after this background proposal was composed.'
            );
        }
        if ($workType === self::SELF_PRESENCE_ANSWER_WORK_TYPE
            && $normalized['kind'] !== 'self_presence_utterance'
        ) {
            $checks = $validation['checks'];
            $checks['addressed_speech_requires_answer'] = false;
            return $this->rejectSelfPresenceProposal(
                $thread,
                $step,
                $workId,
                $normalized,
                $checks,
                'Addressed speech requires an utterance, not a silence choice.'
            );
        }
        if ($normalized['kind'] === 'remain_silent') {
            return $this->acceptSelfPresenceSilence(
                $thread,
                $step,
                $workId,
                $normalized,
                $validation['checks'],
                $model
            );
        }

        $now = time();
        $gate = $this->selfPresenceSpeechGate(
            $thread,
            $now,
            $workType === self::SELF_PRESENCE_ANSWER_WORK_TYPE
        );
        if (!$gate['allowed']) {
            return $this->deferAcceptedSelfPresenceSpeech(
                $thread,
                $step,
                $workId,
                $normalized,
                $validation['checks'],
                $gate,
                $model
            );
        }

        $this->transaction(function () use (
            $threadId,
            $stepId,
            $normalized,
            $validation,
            $workId,
            $model
        ): void {
            $currentThread = $this->requireCognitiveThread($threadId);
            $currentStep = $this->requireThreadStep($stepId);
            if ($currentStep->status !== 'running'
                || (int) $currentThread->fencing_token !== (int) $currentStep->fencing_token
            ) {
                throw new RuntimeException('Self-presence dispatch lost its thread fence.');
            }
            $currentStep->setFields([
                'proposal' => $normalized,
                'deterministic_checks' => $validation['checks'],
                'curator_verdict' => 'accepted',
                'observed_result' => [
                    'dispatch_committed' => true,
                    'speech_outcome' => 'pending',
                    'work_item_id' => $workId,
                    'model' => $model,
                ],
                'status' => 'dispatching',
            ]);
            $currentStep->save();
            $currentThread->setFields([
                'phase' => 'dispatching',
                'last_observation' => 'A curated local speech dispatch was committed; its delivery outcome is not recorded yet.',
                'updated_at' => time(),
            ]);
            $currentThread->save();
            $this->emit('thread.speech.dispatching', [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'work_item_id' => $workId,
                'actuator' => 'pet_http_speak',
                'model' => $model,
            ]);
        });

        try {
            $speechResult = $this->speechActuator->speak((string) $normalized['content']);
        } catch (Throwable $throwable) {
            return $this->finishIndeterminateSelfPresenceDispatch(
                $threadId,
                $stepId,
                $workId,
                $throwable->getMessage()
            );
        }

        // Pet answers with how long the line will take to say, and that was
        // being thrown away. Keeping it is what lets the speaking rate be
        // measured instead of assumed, which is the only way a budget in
        // seconds can be turned into words without someone inventing a number.
        $spokenSeconds = (float) ($speechResult['body']['duration_secs'] ?? 0.0);
        if ($spokenSeconds > 0.0) {
            $this->emit('speech.timed', [
                'thread_id' => $threadId,
                'words' => str_word_count((string) $normalized['content']),
                'duration_seconds' => round($spokenSeconds, 2),
            ]);
        }

        $result = $this->transaction(function () use (
            $threadId,
            $stepId,
            $workId,
            $normalized,
            $speechResult,
            $model
        ): array {
            $currentThread = $this->requireCognitiveThread($threadId);
            $currentStep = $this->requireThreadStep($stepId);
            if ($currentStep->status !== 'dispatching') {
                return ['status' => 'dispatch_already_finalized', 'thread_step' => $currentStep->getData()];
            }
            $now = time();
            $nextWake = $now;
            $spent = is_array($currentThread->spent) ? $currentThread->spent : [];
            $spent['worker_calls'] = (int) ($spent['worker_calls'] ?? 0) + 1;
            $spent['speech_count'] = (int) ($spent['speech_count'] ?? 0) + 1;
            $currentStep->setFields([
                'completed_at' => $now,
                'observed_result' => [
                    'spoken' => true,
                    'speech_outcome' => 'accepted_by_pet_bridge',
                    'actuator' => 'pet_http_speak',
                    'response' => $speechResult,
                    'work_item_id' => $workId,
                    'model' => $model,
                ],
                'post_state' => ['thread_phase' => 'waiting', 'next_operation' => 'evaluate_self_presence'],
                'next_wake_at' => $nextWake,
                'status' => 'succeeded',
            ]);
            $currentStep->save();
            $currentThread->setFields([
                'phase' => 'waiting',
                'next_operation' => 'evaluate_self_presence',
                'wake_at' => $nextWake,
                'spent' => $spent,
                'progress' => min(1.0, (float) $currentThread->progress + 0.25),
                'stagnation_count' => 0,
                'status' => 'waiting',
                'last_observation' => 'A bounded self-presence utterance was accepted by the local Pet speech bridge.',
                'updated_at' => $now,
            ]);
            $currentThread->save();
            $this->markWorkArtifact($workId, 'accepted');

            // Open the response window. Speaking is the only thing Navi does
            // that can be reacted to, so it is the only place a social outcome
            // can be observed rather than assumed.
            $this->socialFeedback()->registerUtterance(
                $stepId,
                (string) $normalized['content'],
                $now
            );

            $need = Need::getByField('need_key', self::SELF_PRESENCE_NEED_KEY);
            $satisfaction = $need instanceof Need
                ? $this->satisfyNeedRecord($need, 1.0, 'local Pet speech from thread step ' . $stepId, $now)
                : null;
            // The track is deliberately not released here. Mentioning something
            // is not finishing it, and discharging the focus on speech made
            // talking about work the way to be done with it — which is why no
            // intention had ever completed while plenty had been talked about.
            // A track ends when its success condition is met, and the
            // completion check is the only thing that may say so.

            $event = $this->emit('thread.speech.spoken', [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'work_item_id' => $workId,
                'next_wake_at' => $nextWake,
                'actuator' => 'pet_http_speak',
            ]);
            return [
                'status' => 'spoken',
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'satisfaction' => $satisfaction,
                'event' => $event->getData(),
            ];
        });
        if (($result['status'] ?? null) !== 'spoken') {
            return $result;
        }

        $memory = $this->rememberUtterance(
            channel: 'spoke_aloud',
            content: (string) $normalized['content'],
            confidence: 0.9,
            sourceEventId: (int) ($result['event']['id'] ?? 0),
            refs: [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'work_item_id' => $workId,
            ]
        );
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        foreach ((array) ($refs['addressed_event_ids'] ?? []) as $senseEventId) {
            try {
                $this->sensoryCortex()->recordOutcome(
                    (int) $senseEventId,
                    'accepted',
                    'Answered by self-presence speech.',
                    (int) ($memory['memory']['id'] ?? 0) ?: null
                );
            } catch (Throwable) {
                // Another reader may already have recorded the edge.
            }
        }
        $result['memory'] = $memory;
        return $result;
    }

    /**
     * Accept a narrative as a derived experiment result. It is retained in
     * thought history and mirrored to /tmp, but never promoted into memory or
     * fed back into the next compile as source evidence.
     *
     * @param array<string, mixed> $work
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private function integrateNarrativeSynthesis(array $work, array $proposal, ?string $model): array
    {
        $workId = (int) ($work['id'] ?? 0);
        $workType = (string) ($work['work_type'] ?? '');
        $errors = NarrativeSynthesis::validationErrors($workType, $proposal);
        if ($errors !== []) {
            $artifact = $this->markWorkArtifact($workId, 'rejected');
            $event = $this->emit('narrative.synthesis_rejected', [
                'work_item_id' => $workId,
                'work_type' => $workType,
                'artifact_id' => $artifact?->id,
                'model' => $model,
                'errors' => $errors,
                'experimental' => true,
            ]);
            return [
                'status' => 'rejected',
                'errors' => $errors,
                'artifact' => $artifact?->getData(),
                'event' => $event->getData(),
            ];
        }

        $path = NarrativeSynthesis::mirrorLatest($workType, (string) $proposal['content']);
        $artifact = $this->markWorkArtifact($workId, 'accepted');
        if (!$artifact instanceof ThoughtArtifact) {
            throw new RuntimeException('Narrative synthesis artifact could not be found for acceptance.');
        }
        $event = $this->emit('narrative.synthesized', [
            'work_item_id' => $workId,
            'work_type' => $workType,
            'artifact_id' => $artifact->id,
            'model' => $model,
            'path' => $path,
            'evidence_checksum' => is_array($work['input_refs'] ?? null)
                ? ($work['input_refs']['evidence_checksum'] ?? null)
                : null,
            'experimental' => true,
        ]);

        return [
            'status' => 'accepted',
            'artifact' => $artifact->getData(),
            'path' => $path,
            'event' => $event->getData(),
        ];
    }

    public function handleWorkerFailure(array $work, string $error): array
    {
        $workType = $work['work_type'] ?? null;
        if (NarrativeSynthesis::isWorkType((string) $workType)) {
            $event = $this->emit('narrative.synthesis_failed', [
                'work_item_id' => (int) ($work['id'] ?? 0),
                'work_type' => $workType,
                'error' => $error,
                'experimental' => true,
            ]);
            return [
                'status' => 'narrative_synthesis_failed',
                'event' => $event->getData(),
            ];
        }
        if ($workType === OtherModel::WORK_TYPE) {
            return $this->otherModel->failParserWork($work, $error);
        }
        if ($workType === SocialFeedback::WORK_TYPE) {
            return $this->socialFeedback()->failReflection($work, $error);
        }
        if ($workType === DecisionStateMachine::WORK_TYPE) {
            return $this->decisionStateMachine->failWork($work, $error);
        }
        if ($workType === self::MEMORY_CONSOLIDATION_WORK_TYPE) {
            return $this->rejectConsolidationAttempt($work, $error);
        }
        if (!self::isSelfPresenceWorkType((string) $workType)
            && $workType !== self::EPISTEMIC_ADVANCE_WORK_TYPE
            && $workType !== self::MIND_STREAM_WORK_TYPE
        ) {
            return ['status' => 'not_applicable'];
        }
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $threadId = (int) ($refs['thread_id'] ?? 0);
        $stepId = (int) ($refs['thread_step_id'] ?? 0);
        $workId = (int) ($work['id'] ?? 0);

        if ($workType === self::EPISTEMIC_ADVANCE_WORK_TYPE
            || $workType === self::MIND_STREAM_WORK_TYPE
        ) {
            return $this->failEpistemicAdvanceStep($threadId, $stepId, $workId, $error);
        }

        return $this->transaction(function () use ($threadId, $stepId, $workId, $error): array {
            $thread = $this->requireCognitiveThread($threadId);
            $step = $this->requireThreadStep($stepId);
            if (in_array($step->status, ['succeeded', 'failed', 'cancelled'], true)) {
                return ['status' => 'already_finalized', 'thread_step' => $step->getData()];
            }
            if ($step->status === 'dispatching') {
                return $this->finishIndeterminateSelfPresenceDispatch(
                    $threadId,
                    $stepId,
                    $workId,
                    'Worker failure reported after dispatch commit: ' . $error
                );
            }

            $now = time();
            $addressed = $this->pendingAddressedEvents() !== [];
            $nextWake = $now;
            $step->setFields([
                'completed_at' => $now,
                'observed_result' => ['worker_failed' => true, 'work_item_id' => $workId],
                'post_state' => ['thread_phase' => 'waiting'],
                'next_wake_at' => $nextWake,
                'status' => 'failed',
                'error' => $error,
            ]);
            $step->save();
            $thread->setFields([
                'phase' => $addressed ? 'interrupted' : 'waiting',
                'wake_at' => $nextWake,
                'status' => 'waiting',
                'stagnation_count' => (int) $thread->stagnation_count + 1,
                'last_observation' => 'The bounded speech-judgment worker failed; the thread stayed active and will try a fresh generation.',
                'updated_at' => $now,
            ]);
            $thread->save();
            $event = $this->emit('thread.worker.failed', [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'work_item_id' => $workId,
                'error' => $error,
                'next_wake_at' => $nextWake,
            ]);
            return [
                'status' => 'worker_failed',
                'thread' => $thread->getData(),
                'thread_step' => $step->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /** @param list<string> $modelIds */
    public function syncFreeModels(array $modelIds): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $model): string => is_string($model) ? trim($model) : '',
            $modelIds
        ), static fn (string $model): bool => preg_match('~\Aopencode/[a-z0-9][a-z0-9._-]*-free\z~', $model) === 1)));

        return $this->transaction(function () use ($normalized): array {
            $now = time();
            foreach ($normalized as $modelId) {
                [$provider] = array_pad(explode('/', $modelId, 2), 2, 'unknown');
                $endpoint = ModelEndpoint::getByField('model_id', $modelId);
                if (!$endpoint instanceof ModelEndpoint) {
                    /** @var ModelEndpoint $endpoint */
                    $endpoint = $this->insert(ModelEndpoint::class, [
                        'model_id' => $modelId,
                        'provider' => $provider,
                        'status' => 'discovered',
                        'last_discovered_at' => $now,
                        'consecutive_failures' => 0,
                        'updated_at' => $now,
                    ]);
                } else {
                    $fields = ['last_discovered_at' => $now, 'updated_at' => $now];
                    if ($endpoint->last_error === 'Model disappeared from the latest free-model catalogue.') {
                        $fields['status'] = 'discovered';
                        $fields['last_error'] = null;
                    }
                    $endpoint->setFields($fields);
                    $endpoint->save();
                }
            }

            foreach (ModelEndpoint::getAll() as $endpoint) {
                // Managed local-ablation and Codex endpoints are not part of
                // the remote catalogue and must not be swept by its discovery.
                if (in_array($endpoint->provider, [
                    self::LOCAL_MODEL_PROVIDER,
                    self::CODEX_MODEL_PROVIDER,
                ], true)) {
                    continue;
                }
                if (!in_array($endpoint->model_id, $normalized, true)) {
                    $endpoint->setFields([
                        'status' => 'unavailable',
                        // An old failure cooldown must not make a removed
                        // model selectable again when that cooldown expires.
                        'cooldown_until' => null,
                        'updated_at' => $now,
                        'last_error' => 'Model disappeared from the latest free-model catalogue.',
                    ]);
                    $endpoint->save();
                }
            }

            $event = $this->emit('models.discovered', [
                'free_model_ids' => $normalized,
                'count' => count($normalized),
            ]);
            return ['models' => $this->listModelEndpoints(), 'event' => $event->getData()];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listModelEndpoints(): array
    {
        return $this->records(ModelEndpoint::getAll([
            'order' => ['consecutive_failures' => 'ASC', 'latency_ms' => 'ASC'],
        ]));
    }

    /** @return list<string> */
    public function selectableFreeModels(): array
    {
        $now = time();
        $candidates = array_values(array_filter(
            ModelEndpoint::getAll(),
            function (ModelEndpoint $endpoint) use ($now): bool {
                // Only remote catalogue models are dispatchable through OpenCode.
                if (preg_match('~\Aopencode/[a-z0-9][a-z0-9._-]*-free\z~', (string) $endpoint->model_id) !== 1) {
                    return false;
                }
                $cooldown = $this->timestamp($endpoint->cooldown_until);
                if ($endpoint->status === 'unavailable' && $cooldown === null) {
                    return false;
                }
                return $cooldown === null || $cooldown <= $now;
            }
        ));
        usort($candidates, static function (ModelEndpoint $left, ModelEndpoint $right): int {
            $leftLatency = $left->latency_ms === null ? PHP_INT_MAX : (int) $left->latency_ms;
            $rightLatency = $right->latency_ms === null ? PHP_INT_MAX : (int) $right->latency_ms;
            return (int) $left->consecutive_failures <=> (int) $right->consecutive_failures
                ?: $leftLatency <=> $rightLatency;
        });
        return array_values(array_map(
            static fn (ModelEndpoint $endpoint): string => (string) $endpoint->model_id,
            $candidates
        ));
    }

    /** Register the optional local-ablation endpoint when explicitly used. */
    public function registerLocalModel(string $modelId): array
    {
        return $this->registerManagedModel(
            $modelId,
            self::LOCAL_MODEL_PROVIDER,
            'models.local.registered'
        );
    }

    /** Ensure the ChatGPT-authenticated Codex endpoint has health telemetry. */
    public function registerCodexModel(string $modelId): array
    {
        return $this->registerManagedModel(
            $modelId,
            self::CODEX_MODEL_PROVIDER,
            'models.codex.registered'
        );
    }

    /** @return array<string, mixed> */
    private function registerManagedModel(string $modelId, string $provider, string $eventKind): array
    {
        $this->requireText($modelId, 'model id');
        return $this->transaction(function () use ($modelId, $provider, $eventKind): array {
            $endpoint = ModelEndpoint::getByField('model_id', $modelId);
            $now = time();
            if ($endpoint instanceof ModelEndpoint) {
                $endpoint->setFields([
                    'provider' => $provider,
                    'last_discovered_at' => $now,
                    'updated_at' => $now,
                ]);
                $endpoint->save();
                return $endpoint->getData();
            }
            /** @var ModelEndpoint $endpoint */
            $endpoint = $this->insert(ModelEndpoint::class, [
                'model_id' => $modelId,
                'provider' => $provider,
                'status' => 'discovered',
                'last_discovered_at' => $now,
                'consecutive_failures' => 0,
                'updated_at' => $now,
            ]);
            $this->emit($eventKind, [
                'model_id' => $modelId,
                'provider' => $provider,
            ]);
            return $endpoint->getData();
        });
    }

    /**
     * Record that the local endpoint failed its liveness probe. This is an
     * ordinary degraded state, so it never throws and never claims work.
     */
    public function recordLocalModelUnavailable(string $modelId, string $reason): array
    {
        $this->registerLocalModel($modelId);
        return $this->recordModelResult($modelId, false, 0, $reason);
    }

    /**
     * Seconds left on the local model's backoff, or null when it is free to try.
     *
     * recordModelResult() has always computed this backoff and nothing ever read
     * it for the local endpoint, because selectableFreeModels() covers only the
     * remote catalogue. The brake existed and was not connected to anything: a
     * timed-out request extended the cooldown, the next tick ignored it and
     * queued another request behind the same single slot, and the failure fed
     * itself. A health probe cannot substitute — it answers instantly while the
     * every inference slot is occupied by stalled work.
     */
    public function localModelCooldownRemaining(string $modelId): ?int
    {
        return $this->modelCooldownRemaining($modelId);
    }

    /** Seconds left on any registered model's persisted circuit breaker. */
    public function modelCooldownRemaining(string $modelId): ?int
    {
        $endpoint = ModelEndpoint::getByField('model_id', $modelId);
        if (!$endpoint instanceof ModelEndpoint) {
            return null;
        }
        $cooldown = $this->timestamp($endpoint->cooldown_until);
        if ($cooldown === null) {
            return null;
        }
        $remaining = $cooldown - time();
        return $remaining > 0 ? $remaining : null;
    }

    /** Clear persisted backoff after an operator has repaired the local server. */
    public function resetLocalModelBackoff(string $modelId, string $reason): array
    {
        $this->requireText($modelId, 'model id');
        $this->requireText($reason, 'reset reason');
        $endpoint = ModelEndpoint::getByField('model_id', $modelId);
        if (!$endpoint instanceof ModelEndpoint || $endpoint->provider !== self::LOCAL_MODEL_PROVIDER) {
            throw new InvalidArgumentException('Only the registered local model backoff can be reset here.');
        }
        $now = time();
        $previousFailures = (int) $endpoint->consecutive_failures;
        $endpoint->setFields([
            'status' => 'discovered',
            'consecutive_failures' => 0,
            'cooldown_until' => null,
            'last_error' => null,
            'updated_at' => $now,
        ]);
        $endpoint->save();
        $event = $this->emit('models.local.backoff_reset', [
            'model_id' => $modelId,
            'previous_consecutive_failures' => $previousFailures,
            'reason' => $reason,
        ]);
        return ['endpoint' => $endpoint->getData(), 'event' => $event->getData()];
    }

    public function recordModelResult(
        string $modelId,
        bool $succeeded,
        int $latencyMs,
        ?string $error = null
    ): array {
        $this->requireText($modelId, 'model id');
        if ($latencyMs < 0) {
            throw new InvalidArgumentException('model latency cannot be negative.');
        }
        $endpoint = ModelEndpoint::getByField('model_id', $modelId);
        if (!$endpoint instanceof ModelEndpoint) {
            throw new RuntimeException(sprintf('Model %s is not in the registered model pool.', $modelId));
        }

        return $this->transaction(function () use ($endpoint, $succeeded, $latencyMs, $error): array {
            $now = time();
            $failures = $succeeded ? 0 : (int) $endpoint->consecutive_failures + 1;
            $cooldown = $succeeded ? null : $now + min(3600, 30 * (2 ** min($failures, 7)));
            $endpoint->setFields([
                'status' => $succeeded ? 'available' : ($failures >= 3 ? 'unavailable' : 'degraded'),
                'last_probed_at' => $now,
                'last_success_at' => $succeeded ? $now : $endpoint->last_success_at,
                'consecutive_failures' => $failures,
                'latency_ms' => $latencyMs,
                'cooldown_until' => $cooldown,
                'last_error' => $succeeded ? null : $error,
                'updated_at' => $now,
            ]);
            $endpoint->save();
            $event = $this->emit($succeeded ? 'model.available' : 'model.failed', [
                'model_id' => $endpoint->model_id,
                'latency_ms' => $latencyMs,
                'consecutive_failures' => $failures,
                'cooldown_until' => $cooldown,
                'error' => $error,
            ]);
            return ['model' => $endpoint->getData(), 'event' => $event->getData()];
        });
    }

    public function setSelfModelFact(string $key, string $value, float $confidence, string $evidence): array
    {
        $this->requireText($key, 'key');
        $this->requireText($value, 'value');
        $this->requireText($evidence, 'evidence');
        $this->requireUnitInterval($confidence, 'confidence');

        return $this->transaction(function () use ($key, $value, $confidence, $evidence): array {
            $event = $this->emit('self_model.observed', [
                'fact_key' => $key,
                'fact_value' => $value,
                'confidence' => $confidence,
                'evidence' => $evidence,
            ]);

            $fact = SelfModelFact::getByField('fact_key', $key);
            if ($fact === null) {
                /** @var SelfModelFact $fact */
                $fact = $this->insert(SelfModelFact::class, [
                    'fact_key' => $key,
                    'fact_value' => $value,
                    'confidence' => $confidence,
                    'evidence_event_id' => $event->id,
                    'updated_at' => time(),
                ]);
            } else {
                $fact->setFields([
                    'fact_value' => $value,
                    'confidence' => $confidence,
                    'evidence_event_id' => $event->id,
                    'updated_at' => time(),
                ]);
                $fact->save();
            }

            return ['fact' => $fact->getData(), 'event' => $event->getData()];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listSelfModelFacts(): array
    {
        return $this->records(array_values(array_filter(
            SelfModelFact::getAll(['order' => ['fact_key' => 'ASC']]),
            static fn (SelfModelFact $fact): bool => SelfModelFact::isModelContextVisible(
                (string) $fact->fact_key
            )
        )));
    }

    public function appraise(
        ?int $eventId,
        ?int $intentionId,
        float $relevance,
        float $urgency,
        float $controllability,
        float $uncertainty,
        float $commitmentImpact
    ): array {
        if ($eventId === null && $intentionId === null) {
            throw new InvalidArgumentException('An appraisal requires an event or intention.');
        }
        if ($eventId !== null) {
            $this->requireEvent($eventId);
        }
        if ($intentionId !== null) {
            $this->requireIntention($intentionId);
        }

        foreach ([
            'relevance' => $relevance,
            'urgency' => $urgency,
            'controllability' => $controllability,
            'uncertainty' => $uncertainty,
            'commitment impact' => $commitmentImpact,
        ] as $name => $value) {
            $this->requireUnitInterval($value, $name);
        }

        $attentionScore = round(($relevance + $urgency + $uncertainty + $commitmentImpact) / 4, 3);
        /** @var Appraisal $appraisal */
        $appraisal = $this->insert(Appraisal::class, [
            'event_id' => $eventId,
            'intention_id' => $intentionId,
            'relevance' => $relevance,
            'urgency' => $urgency,
            'controllability' => $controllability,
            'uncertainty' => $uncertainty,
            'commitment_impact' => $commitmentImpact,
            'attention_score' => $attentionScore,
        ]);

        return $appraisal->getData();
    }

    public function status(): array
    {
        $this->workingMemory->expireStale();
        return [
            'active_intentions' => $this->intentionsByConsideration('active'),
            'blocked_intentions' => $this->intentionsByConsideration('blocked'),
            'pending_actions' => $this->records(ActionTrace::getAllByWhere(
                ['status' => 'pending'],
                ['order' => ['created_at' => 'DESC']]
            )),
            'recent_discrepancies' => $this->records(ActionTrace::getAllByWhere(
                ['match_status' => 'mismatched'],
                ['order' => ['completed_at' => 'DESC'], 'limit' => 10]
            )),
            'working_memory' => $this->records(Memory::inspectAllByWhere(
                ['tier' => 'working', 'status' => 'active'],
                ['order' => ['updated_at' => 'DESC'], 'limit' => 25]
            )),
            'working_memory_slots' => $this->records(\NaviBrain\Model\WorkingMemorySlot::getAllByWhere(
                ['status' => 'active'],
                ['order' => ['updated_at' => 'DESC'], 'limit' => 50]
            )),
            'semantic_memory' => $this->records(Memory::inspectAllByWhere(
                ['tier' => 'semantic', 'status' => 'active'],
                ['order' => ['updated_at' => 'DESC'], 'limit' => 25]
            )),
            'procedural_memory' => $this->records(Memory::inspectAllByWhere(
                ['tier' => 'procedural', 'status' => 'active'],
                ['order' => ['updated_at' => 'DESC'], 'limit' => 25]
            )),
            'procedures' => $this->proceduralMemory->list('active'),
            'installed_decision_procedure' => $this->proceduralMemory->decisionProcedure(),
            'decision_cycles' => $this->decisionStateMachine->list(null, 10),
            'decision_comparison' => $this->decisionStateMachine->compare(200),
            'other_model' => $this->otherModel->status(),
            'needs' => $this->listNeeds(),
            'heartbeat' => $this->heartbeatStatus(),
            'proposed_thoughts' => $this->records(ThoughtArtifact::getAllByWhere(
                ['status' => 'proposed'],
                ['order' => ['created_at' => 'DESC'], 'limit' => 25]
            )),
            'self_model' => $this->listSelfModelFacts(),
            'top_appraisals' => $this->records(Appraisal::getAll([
                'order' => ['attention_score' => 'DESC'],
                'limit' => 10,
            ])),
        ];
    }

    /**
     * Read the resident associative state activated by the current intention.
     * The C daemon owns ranking, token budgeting, and decoding; PHP has no
     * competing context-selection policy.
     */
    public function contextStatus(
        string $activeIntention,
        int $tokenBudget = TokenMemoryDaemon::DEFAULT_CONTEXT_TOKENS
    ): string {
        $this->requireText($activeIntention, 'active intention');
        return TokenMemoryDaemon::activate($activeIntention, $tokenBudget);
    }

    /**
     * Rank durable intentions by actual cognitive attention rather than by the
     * last administrative edit to the intention row. Completed model work and
     * explicit appraisals are the two durable records that mean an intention
     * was considered. A direct edit remains the fallback for new intentions.
     *
     * @return list<array<string, mixed>>
     */
    private function intentionsByConsideration(string $status): array
    {
        $intentions = $this->records(Intention::getAllByWhere(
            ['status' => $status],
            ['order' => ['updated_at' => 'DESC']]
        ));
        if ($intentions === []) {
            return [];
        }

        $statement = $this->connection->query(
            "SELECT intention_id,
                    SUM(consideration_count) AS consideration_count,
                    MAX(last_considered_at) AS last_considered_at
             FROM (
                 SELECT parent_intention_id AS intention_id,
                        COUNT(*) AS consideration_count,
                        MAX(COALESCE(completed_at, updated_at, created_at)) AS last_considered_at
                 FROM work_items
                 WHERE parent_intention_id IS NOT NULL AND status = 'completed'
                 GROUP BY parent_intention_id
                 UNION ALL
                 SELECT intention_id,
                        COUNT(*) AS consideration_count,
                        MAX(created_at) AS last_considered_at
                 FROM appraisals
                 WHERE intention_id IS NOT NULL
                 GROUP BY intention_id
             ) consideration_evidence
             GROUP BY intention_id"
        );
        $consideration = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $consideration[(int) $row['intention_id']] = [
                'count' => (int) $row['consideration_count'],
                'last_at' => $this->timestamp($row['last_considered_at']),
            ];
        }
        $statement->closeCursor();

        foreach ($intentions as &$intention) {
            $evidence = $consideration[(int) $intention['id']] ?? ['count' => 0, 'last_at' => null];
            $updatedAt = $this->timestamp($intention['updated_at'] ?? null) ?? 0;
            $lastAt = $evidence['last_at'];
            $intention['consideration_count'] = $evidence['count'];
            $intention['last_considered_at'] = max($updatedAt, $lastAt ?? 0);
        }
        unset($intention);

        usort($intentions, static function (array $left, array $right): int {
            return ($right['last_considered_at'] <=> $left['last_considered_at'])
                ?: ((int) $right['id'] <=> (int) $left['id']);
        });
        $recencyRanks = array_flip(array_map(
            static fn (array $intention): int => (int) $intention['id'],
            $intentions
        ));

        $frequent = array_values(array_filter(
            $intentions,
            static fn (array $intention): bool => $intention['consideration_count'] > 0
        ));
        usort($frequent, static function (array $left, array $right): int {
            return ($right['consideration_count'] <=> $left['consideration_count'])
                ?: ($right['last_considered_at'] <=> $left['last_considered_at'])
                ?: ((int) $right['id'] <=> (int) $left['id']);
        });
        $frequencyRanks = array_flip(array_map(
            static fn (array $intention): int => (int) $intention['id'],
            $frequent
        ));

        usort($intentions, static function (array $left, array $right) use (
            $recencyRanks,
            $frequencyRanks
        ): int {
            $leftId = (int) $left['id'];
            $rightId = (int) $right['id'];
            $leftRank = min($recencyRanks[$leftId], $frequencyRanks[$leftId] ?? PHP_INT_MAX);
            $rightRank = min($recencyRanks[$rightId], $frequencyRanks[$rightId] ?? PHP_INT_MAX);
            return ($leftRank <=> $rightRank)
                ?: ($right['last_considered_at'] <=> $left['last_considered_at'])
                ?: ($right['consideration_count'] <=> $left['consideration_count'])
                ?: ($rightId <=> $leftId);
        });
        return $intentions;
    }

    /** @return list<array<string, mixed>> */
    public function listContextCapsules(?int $threadId = null, int $limit = 10): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }
        $conditions = $threadId === null ? [] : ['thread_id' => $threadId];
        return $this->records(ContextCapsule::getAllByWhere(
            $conditions,
            ['order' => ['id' => 'DESC'], 'limit' => $limit]
        ));
    }

    /**
     * Inspect one assembled workspace, including why each slot holds what it
     * holds and whether its recorded checksum still verifies.
     *
     * @return array<string, mixed>
     */
    public function showContextCapsule(?int $capsuleId = null): array
    {
        if ($capsuleId === null) {
            $latest = ContextCapsule::getAll(['order' => ['id' => 'DESC'], 'limit' => 1]);
            $capsule = $latest[0] ?? null;
        } else {
            $capsule = ContextCapsule::getByID($capsuleId);
        }
        if (!$capsule instanceof ContextCapsule) {
            throw new RuntimeException('No context capsule has been assembled yet.');
        }

        $serialized = $this->capsuleAssembler->serialize((int) $capsule->id);
        $slots = $this->records(CapsuleSlot::getAllByWhere(
            ['capsule_id' => (int) $capsule->id],
            ['order' => ['id' => 'ASC']]
        ));

        return [
            'capsule' => $capsule->getData(),
            'checksum_intact' => hash_equals(
                (string) $capsule->checksum,
                $this->capsuleAssembler->checksum($serialized)
            ),
            'max_carryover_depth' => $this->capsuleAssembler->maxCarryoverDepth((int) $capsule->id),
            'serialized' => $serialized,
            'slots' => $slots,
        ];
    }

    /**
     * Compute and durably record one `cognitive-v1` measurement vector.
     *
     * The protocol is frozen: the manifest text below is hashed against a
     * compile-time constant, so a metric definition cannot be edited without
     * minting a new protocol version. Old snapshots therefore stay comparable.
     *
     * @return array<string, mixed>
     */
    public function recordMetricSnapshot(string $nodeId, ?string $scopeKey = null): array
    {
        $this->requireText($nodeId, 'node id');
        $manifest = $this->metricsManifest(self::METRICS_PROTOCOL_V1);
        $vector = $this->computeMetricVector($scopeKey);
        ksort($vector);
        $checksum = hash('sha256', implode("\n", [
            self::METRICS_PROTOCOL_V1,
            $manifest,
            json_encode($vector, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]));

        return $this->transaction(function () use ($nodeId, $scopeKey, $manifest, $vector, $checksum): array {
            /** @var MetricSnapshot $snapshot */
            $snapshot = $this->insert(MetricSnapshot::class, [
                'protocol_version' => self::METRICS_PROTOCOL_V1,
                'scope_key' => $scopeKey,
                'node_id' => $nodeId,
                'vector' => $vector,
                'manifest' => $manifest,
                'checksum' => $checksum,
            ]);
            $event = $this->emit('metrics.recorded', [
                'metric_snapshot_id' => $snapshot->id,
                'protocol_version' => self::METRICS_PROTOCOL_V1,
                'scope_key' => $scopeKey,
                'node_id' => $nodeId,
                'checksum' => $checksum,
            ]);
            return ['snapshot' => $snapshot->getData(), 'event' => $event->getData()];
        });
    }

    /**
     * Report the latest measurement vector against the previous one under the
     * same protocol, and verify that every returned snapshot still matches its
     * recorded checksum.
     *
     * @return array<string, mixed>
     */
    public function metricsReport(int $limit = 10, ?string $scopeKey = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }
        $conditions = ['protocol_version' => self::METRICS_PROTOCOL_V1];
        if ($scopeKey !== null) {
            $conditions['scope_key'] = $scopeKey;
        }
        $snapshots = MetricSnapshot::getAllByWhere(
            $conditions,
            ['order' => ['created_at' => 'DESC', 'id' => 'DESC'], 'limit' => $limit]
        );

        $history = [];
        $tampered = [];
        foreach ($snapshots as $snapshot) {
            $vector = is_array($snapshot->vector) ? $snapshot->vector : [];
            ksort($vector);
            $expected = hash('sha256', implode("\n", [
                (string) $snapshot->protocol_version,
                (string) $snapshot->manifest,
                json_encode($vector, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]));
            $intact = hash_equals((string) $snapshot->checksum, $expected);
            if (!$intact) {
                $tampered[] = (int) $snapshot->id;
            }
            $history[] = [
                'id' => (int) $snapshot->id,
                'created_at' => $this->timestamp($snapshot->created_at),
                'node_id' => (string) $snapshot->node_id,
                'scope_key' => $snapshot->scope_key,
                'checksum_intact' => $intact,
                'vector' => $vector,
            ];
        }

        // Vectors are only comparable within one scope, so the delta walks back
        // to the most recent earlier snapshot covering the same records.
        $latestEntry = $history[0] ?? null;
        $latest = $latestEntry['vector'] ?? [];
        $previous = [];
        $comparedWith = null;
        foreach (array_slice($history, 1) as $entry) {
            if ($latestEntry !== null && $entry['scope_key'] === $latestEntry['scope_key']) {
                $previous = $entry['vector'];
                $comparedWith = $entry['id'];
                break;
            }
        }
        $delta = [];
        foreach ($latest as $key => $value) {
            if (is_numeric($value) && is_numeric($previous[$key] ?? null)) {
                $delta[$key] = round((float) $value - (float) $previous[$key], 4);
            }
        }

        return [
            'protocol_version' => self::METRICS_PROTOCOL_V1,
            'manifest' => $this->metricsManifest(self::METRICS_PROTOCOL_V1),
            'scope_key' => $scopeKey,
            'live' => $this->computeMetricVector($scopeKey),
            'latest' => $latest,
            'latest_scope_key' => $latestEntry['scope_key'] ?? null,
            'delta_since_previous' => $delta,
            'delta_compared_with_snapshot_id' => $comparedWith,
            'tampered_snapshot_ids' => $tampered,
            'history' => $history,
        ];
    }

    /**
     * The frozen definition of every `cognitive-v1` metric. Editing this text
     * without bumping the protocol version is rejected in metricsManifest().
     */
    private const METRICS_MANIFEST_V1 = <<<'MANIFEST'
        cognitive-v1 measures whether background cognition is causal, not whether it is busy.
        Counts are integers over all durable records; ratios are floats in [0,1] and read 0.0 when their denominator is 0.
        unattended_steps: COUNT(thread_steps). Every thread step is begun by a wake, never by a user prompt.
        continuation_depth: the largest COUNT(thread_steps) belonging to any single cognitive_thread.
        proposal_uptake: accepted / (accepted + rejected) over thread_steps.curator_verdict.
        false_initiative: rejected / (accepted + rejected) over thread_steps.curator_verdict.
        closure_accuracy: threads in status complete or released / threads in status complete, released, or blocked.
        recovery_rate: thread_steps with status failed that are followed by a later step in the same thread / all failed steps.
        operation_drift: thread_steps whose operation is absent from the parent thread's budget.operation_cycle / steps of threads that declare a cycle.
        budget_discipline: SUM(threads.spent.accepted) / SUM(threads.spent.worker_calls).
        uncertainty_reduction: SUM over threads of (first step's pre_state.uncertainty - current uncertainty), clamped at 0.0 below.
        belief_revisions: COUNT(thread_steps) whose observed_result.belief_changed is true.
        external_effects: COUNT(thread_steps) whose observed_result.spoken is true.
        MANIFEST;

    private const METRICS_MANIFEST_V1_SHA256 = 'd5f8af0a5286af90f0a16cc8e999b4fa598bd3ee6bf180677b2f6d019193e87f';

    private function metricsManifest(string $protocolVersion): string
    {
        if ($protocolVersion !== self::METRICS_PROTOCOL_V1) {
            throw new InvalidArgumentException('Unknown metrics protocol version: ' . $protocolVersion);
        }
        $manifest = self::METRICS_MANIFEST_V1;
        if (!hash_equals(self::METRICS_MANIFEST_V1_SHA256, hash('sha256', $manifest))) {
            throw new RuntimeException(
                'The cognitive-v1 metrics manifest was edited in place; mint a new protocol version instead.'
            );
        }
        return $manifest;
    }

    /**
     * @return array<string, int|float>
     */
    private function computeMetricVector(?string $scopeKey): array
    {
        $threads = $scopeKey === null
            ? CognitiveThread::getAll()
            : CognitiveThread::getAllByWhere(['thread_key' => $scopeKey]);
        $threadIds = array_map(static fn (CognitiveThread $thread): int => (int) $thread->id, $threads);

        $stepsByThread = [];
        foreach ($threadIds as $threadId) {
            $stepsByThread[$threadId] = ThreadStep::getAllByWhere(
                ['thread_id' => $threadId],
                ['order' => ['id' => 'ASC']]
            );
        }

        $totalSteps = 0;
        $continuationDepth = 0;
        $accepted = 0;
        $rejected = 0;
        $failedSteps = 0;
        $recoveredFailures = 0;
        $driftSteps = 0;
        $cycleScopedSteps = 0;
        $beliefRevisions = 0;
        $externalEffects = 0;
        $spentAccepted = 0;
        $spentWorkerCalls = 0;
        $uncertaintyReduction = 0.0;

        foreach ($threads as $thread) {
            $steps = $stepsByThread[(int) $thread->id] ?? [];
            $count = count($steps);
            $totalSteps += $count;
            $continuationDepth = max($continuationDepth, $count);

            $budget = is_array($thread->budget) ? $thread->budget : [];
            $cycle = is_array($budget['operation_cycle'] ?? null) ? $budget['operation_cycle'] : [];
            $spent = is_array($thread->spent) ? $thread->spent : [];
            $spentAccepted += (int) ($spent['accepted'] ?? 0);
            $spentWorkerCalls += (int) ($spent['worker_calls'] ?? 0);

            $firstUncertainty = null;
            foreach ($steps as $index => $step) {
                $preState = is_array($step->pre_state) ? $step->pre_state : [];
                if ($firstUncertainty === null && is_numeric($preState['uncertainty'] ?? null)) {
                    $firstUncertainty = (float) $preState['uncertainty'];
                }
                if ($step->curator_verdict === 'accepted') {
                    $accepted++;
                } elseif ($step->curator_verdict === 'rejected') {
                    $rejected++;
                }
                if ($step->status === 'failed') {
                    $failedSteps++;
                    if ($index < $count - 1) {
                        $recoveredFailures++;
                    }
                }
                if ($cycle !== []) {
                    $cycleScopedSteps++;
                    if (!in_array((string) $step->operation, $cycle, true)) {
                        $driftSteps++;
                    }
                }
                $observed = is_array($step->observed_result) ? $step->observed_result : [];
                if (($observed['belief_changed'] ?? false) === true) {
                    $beliefRevisions++;
                }
                if (($observed['spoken'] ?? false) === true) {
                    $externalEffects++;
                }
            }

            if ($firstUncertainty !== null) {
                $uncertaintyReduction += max(0.0, $firstUncertainty - (float) $thread->uncertainty);
            }
        }

        $ended = 0;
        $closedWell = 0;
        foreach ($threads as $thread) {
            if (in_array($thread->status, ['complete', 'released', 'blocked'], true)) {
                $ended++;
                if ($thread->status !== 'blocked') {
                    $closedWell++;
                }
            }
        }

        $verdicts = $accepted + $rejected;
        return [
            'unattended_steps' => $totalSteps,
            'continuation_depth' => $continuationDepth,
            'proposal_uptake' => $this->ratio($accepted, $verdicts),
            'false_initiative' => $this->ratio($rejected, $verdicts),
            'closure_accuracy' => $this->ratio($closedWell, $ended),
            'recovery_rate' => $this->ratio($recoveredFailures, $failedSteps),
            'operation_drift' => $this->ratio($driftSteps, $cycleScopedSteps),
            'budget_discipline' => $this->ratio($spentAccepted, $spentWorkerCalls),
            'uncertainty_reduction' => round($uncertaintyReduction, 4),
            'belief_revisions' => $beliefRevisions,
            'external_effects' => $externalEffects,
        ];
    }

    private function ratio(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : round($numerator / $denominator, 4);
    }

    public function checkpoint(string $reason): array
    {
        $this->requireText($reason, 'checkpoint reason');
        TokenMemoryDaemon::flush();
        /** @var Checkpoint $checkpoint */
        $checkpoint = $this->insert(Checkpoint::class, [
            'reason' => $reason,
            'snapshot' => [],
        ]);

        return $checkpoint->getData();
    }

    private function emit(string $kind, array $payload): Event
    {
        /** @var Event $event */
        $event = $this->insert(Event::class, ['kind' => $kind, 'payload' => $payload]);
        $this->queueActivityEvent($kind, $payload);
        return $event;
    }

    /** @param array<string, mixed> $payload */
    private function emitOnce(string $dedupeKey, string $kind, array $payload): Event
    {
        $this->requireText($dedupeKey, 'event dedupe key');
        if (!$this->connection->inTransaction()) {
            try {
                return $this->transaction(
                    fn (): Event => $this->emitOnceClaim($dedupeKey, $kind, $payload)
                );
            } catch (Throwable $throwable) {
                $existing = $this->eventByDedupeKey($dedupeKey);
                if (!$existing instanceof Event) {
                    throw $throwable;
                }
                $this->assertExactEvent($existing, $kind, $payload);
                return $existing;
            }
        }
        return $this->emitOnceClaim($dedupeKey, $kind, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function emitOnceClaim(string $dedupeKey, string $kind, array $payload): Event
    {
        $existing = $this->eventByDedupeKey($dedupeKey);
        if ($existing instanceof Event) {
            $this->assertExactEvent($existing, $kind, $payload);
            return $existing;
        }

        /** @var Event $event */
        $event = $this->insert(Event::class, ['kind' => $kind, 'payload' => $payload]);
        $claim = $this->connection->prepare(
            'UPDATE events SET dedupe_key = :dedupe_key WHERE id = :id AND dedupe_key IS NULL'
        );
        $claim->execute(['dedupe_key' => $dedupeKey, 'id' => (int) $event->id]);
        if ($claim->rowCount() !== 1) {
            throw new RuntimeException('Unable to claim exact event identity.');
        }
        $this->queueActivityEvent($kind, $payload);
        return $event;
    }

    private function eventByDedupeKey(string $dedupeKey): ?Event
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM events WHERE dedupe_key = :dedupe_key LIMIT 1'
        );
        $statement->execute(['dedupe_key' => $dedupeKey]);
        $id = $statement->fetchColumn();
        $statement->closeCursor();
        if ($id === false) {
            return null;
        }
        $event = Event::getByID((int) $id);
        return $event instanceof Event ? $event : null;
    }

    /** @param array<string, mixed> $payload */
    private function assertExactEvent(Event $event, string $kind, array $payload): void
    {
        $existingPayload = is_array($event->payload) ? $event->payload : [];
        if ((string) $event->kind !== $kind
            || !hash_equals($this->canonicalJson($existingPayload), $this->canonicalJson($payload))
        ) {
            throw new RuntimeException('Event dedupe key was reused with different event data.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function queueActivityEvent(string $kind, array $payload): void
    {
        if ($this->connection->inTransaction()) {
            $this->pendingActivityEvents[] = ['kind' => $kind, 'payload' => $payload];
            return;
        }
        $this->activityBus->publish('core', 'event', $kind, context: $payload);
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            return $item;
        };
        return json_encode(
            $normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function ensureDefaultNeeds(): void
    {
        $defaults = [
            [
                'need_key' => 'cognitive_stimulation',
                'description' => 'Maintain enough meaningful cognitive activity to avoid stagnation; unmet pressure is experienced operationally as boredom and routes attention into daydreaming.',
                'authority' => 'user',
                'pressure' => 0.0,
                'growth_per_hour' => 0.10,
                'trigger_threshold' => 0.40,
            ],
            [
                'need_key' => 'epistemic_novelty',
                'description' => 'Remain curious about the world by seeking unfamiliar evidence, alternative explanations, and surprising connections.',
                'authority' => 'user',
                'pressure' => 0.20,
                'growth_per_hour' => 0.04,
                'trigger_threshold' => 0.40,
            ],
        ];

        $this->transaction(function () use ($defaults): void {
            foreach ($defaults as $values) {
                if (Need::getByField('need_key', $values['need_key']) instanceof Need) {
                    continue;
                }

                /** @var Need $need */
                $need = $this->insert(Need::class, array_merge($values, [
                    'last_satisfied_at' => time(),
                    'updated_at' => time(),
                    'status' => 'active',
                ]));
                $this->emit('need.created', [
                    'need_id' => $need->id,
                    'need_key' => $need->need_key,
                    'authority' => $need->authority,
                ]);
            }
        });
    }

    /** @return list<string> */
    private function quickCheck(): array
    {
        $statement = $this->connection->query('PRAGMA quick_check');
        if ($statement === false) {
            return ['quick_check query failed'];
        }
        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function periodicQuickCheck(int $now): array
    {
        if ($this->cachedQuickCheck !== null
            && $this->cachedQuickCheckAt > 0
            && $now - $this->cachedQuickCheckAt < self::QUICK_CHECK_INTERVAL_SECONDS
        ) {
            return $this->cachedQuickCheck;
        }

        $this->cachedQuickCheck = $this->quickCheck();
        $this->cachedQuickCheckAt = $now;
        return $this->cachedQuickCheck;
    }

    private function highBrainBusy(): bool
    {
        return CycleRun::getAllByWhere([
            'status' => 'running',
            'cognitive_layer' => CognitiveLayer::HIGH->value,
        ], ['limit' => 1]) !== [];
    }

    /** @return array{run: CycleRun, missed_intervals: int}|null */
    private function claimRhythm(
        Rhythm $rhythm,
        string $nodeId,
        int $now,
        int $leaseSeconds
    ): ?array {
        return $this->transaction(function () use ($rhythm, $nodeId, $now, $leaseSeconds): ?array {
            $scheduledFor = $this->timestamp($rhythm->next_due_at) ?? $now;
            $interval = max(1, (int) $rhythm->interval_seconds);
            $missed = max(0, intdiv(max(0, $now - $scheduledFor), $interval));
            $newFence = (int) $rhythm->fencing_token + 1;
            $statement = $this->connection->prepare(
                "UPDATE rhythms
                 SET status = 'running', lease_owner = :owner,
                     lease_expires_at = :lease_expires, last_started_at = :now,
                     sequence = sequence + 1, fencing_token = fencing_token + 1,
                     updated_at = :now, last_error = NULL
                 WHERE id = :id AND next_due_at <= :now
                   AND status != 'paused'
                   AND (status != 'running' OR lease_expires_at IS NULL OR lease_expires_at <= :now)"
            );
            $formattedNow = date('Y-m-d H:i:s', $now);
            $statement->execute([
                ':owner' => $nodeId,
                ':lease_expires' => date('Y-m-d H:i:s', $now + $leaseSeconds),
                ':now' => $formattedNow,
                ':id' => $rhythm->id,
            ]);
            if ($statement->rowCount() !== 1) {
                return null;
            }

            /** @var CycleRun $run */
            $run = $this->insert(CycleRun::class, [
                'rhythm_id' => $rhythm->id,
                'cognitive_layer' => $rhythm->cognitive_layer,
                'scheduled_for' => $scheduledFor,
                'node_id' => $nodeId,
                'fencing_token' => $newFence,
                'status' => 'running',
                'event_watermark' => $this->eventWatermark(),
                'budget' => $this->rhythmBudget((string) $rhythm->rhythm_key),
                'output' => ['missed_intervals' => $missed],
            ]);
            $this->emit('cycle.started', [
                'run_id' => $run->id,
                'rhythm_id' => $rhythm->id,
                'rhythm_key' => $rhythm->rhythm_key,
                'cognitive_layer' => $rhythm->cognitive_layer,
                'node_id' => $nodeId,
                'fencing_token' => $newFence,
                'scheduled_for' => $scheduledFor,
                'missed_intervals' => $missed,
            ]);

            return ['run' => $run, 'missed_intervals' => $missed];
        });
    }

    private function coalesceHighRhythm(Rhythm $rhythm, int $now): array
    {
        $dueAt = $this->timestamp($rhythm->next_due_at) ?? $now;
        $interval = max(1, (int) $rhythm->interval_seconds);
        $missed = max(1, intdiv(max(0, $now - $dueAt), $interval) + 1);
        $rhythm->setFields([
            'next_due_at' => $now + $interval,
            'updated_at' => $now,
            'status' => 'idle',
        ]);
        $rhythm->save();
        $event = $this->emit('rhythm.coalesced', [
            'rhythm_id' => $rhythm->id,
            'rhythm_key' => $rhythm->rhythm_key,
            'missed_intervals' => $missed,
            'reason' => 'high_brain_single_flight_busy',
            'next_due_at' => $now + $interval,
        ]);

        return [
            'rhythm' => $rhythm->rhythm_key,
            'status' => 'coalesced_while_sleeping_or_busy',
            'missed_intervals' => $missed,
            'event' => $event->getData(),
        ];
    }

    /** @return array<string, mixed> */
    private function lowBrainPulse(CycleRun $run): array
    {
        $now = time();
        // The safety loop owns the periodic full-database scan. A low pulse may
        // be invoked directly, so it must never begin a scan under a 20-second
        // rhythm lease.
        $integrity = $this->cachedQuickCheck ?? ['deferred_to_safety_audit'];
        $needs = $this->accrueNeeds($now);
        $recoveredWork = $this->recoverExpiredWorkLeases($now);
        $recoveredCycles = $this->recoverExpiredCycleLeases($now, (int) $run->id);

        return [
            'state' => 'low_brain_awake_high_brain_sleeping',
            'run_id' => $run->id,
            'integrity' => $integrity,
            'needs' => $needs,
            'recovered_work_item_ids' => $recoveredWork,
            'recovered_cycle_ids' => $recoveredCycles,
            'model_invoked' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function prepareHighBrainMoment(CycleRun $run, Rhythm $rhythm): array
    {
        $rhythmKey = (string) $rhythm->rhythm_key;
        $needs = $this->accrueNeeds(time());
        $deterministic = ['needs' => $needs];
        $workType = '';
        $prompt = '';
        $inputRefs = ['need_keys' => array_column($needs, 'need_key')];

        if ($rhythmKey === 'decide_1m') {
            $active = array_merge(
                DecisionCycle::getAllByWhere(['status' => 'running'], ['order' => ['id' => 'ASC'], 'limit' => 1]),
                DecisionCycle::getAllByWhere(['status' => 'waiting'], ['order' => ['id' => 'ASC'], 'limit' => 1])
            );
            if ($active !== []) {
                return [
                    'state' => 'decision_cycle_already_active',
                    'decision_cycle_id' => (int) $active[0]->id,
                    'decision_state' => (string) $active[0]->state,
                    'work_item' => null,
                ];
            }

            $focusThread = CognitiveThread::getByField('thread_key', self::SELF_PRESENCE_THREAD_KEY);
            $track = $focusThread instanceof CognitiveThread ? $this->heldFocus($focusThread, time()) : null;
            $intention = $track === null
                ? null
                : Intention::getByField('title', (string) ($track['following'] ?? ''));
            if (!$intention instanceof Intention || $intention->status !== 'active') {
                $fallback = array_values(array_filter(Intention::getAllByWhere(
                    ['status' => 'active'],
                    ['order' => ['updated_at' => 'ASC']]
                ), fn (Intention $candidate): bool => (int) $candidate->id !== $this->selfPresenceIntentionId()));
                $intention = $fallback[0] ?? null;
            }
            if (!$intention instanceof Intention) {
                return ['state' => 'no_active_intention_for_decision', 'work_item' => null];
            }

            $decision = $this->startDecisionCycle(
                (int) $intention->id,
                'Scheduled executive decision wake for the held active intention.',
                null,
                null,
                (int) $run->id
            );
            $output = [
                'state' => 'decision_cycle_waiting',
                'decision_cycle' => $decision['cycle'] ?? null,
                'work_item' => $decision['work_item'] ?? null,
                'intention_id' => (int) $intention->id,
                'planning_actions' => ['retrieval', 'reasoning'],
                'terminal_action_classes' => ['grounding', 'learning'],
            ];
            $run->setField('output', $output);
            $run->save();
            return $output;
        }

        if ($rhythmKey === 'reflect_5m') {
            $triggered = array_values(array_filter(
                $needs,
                static fn (array $need): bool => $need['status'] === 'active' && $need['triggered']
            ));
            if ($triggered === []) {
                return ['state' => 'slept_through_reflection', 'needs' => $needs, 'work_item' => null];
            }
            if ($this->daydreamCooldownActive()) {
                return ['state' => 'daydream_cooldown_sleep', 'needs' => $needs, 'work_item' => null];
            }
            $daydream = $this->daydream('five-minute boredom and curiosity wake trigger', (int) $run->id);
            $deterministic['daydream_artifact_id'] = $daydream['artifact']['id'];
            $inputRefs['artifact_id'] = $daydream['artifact']['id'];
            // Curiosity that can only speculate stays speculation. The same
            // boredom that triggers a daydream is the right moment to go and
            // find something out instead, so a look is queued alongside it.
            $look = $this->enqueueLookProposal((int) $run->id, time());
            $deterministic['look_work_item_id'] = $look['work_item']['id'] ?? null;
            $workType = 'curiosity_reflection';
            $prompt = 'Return one JSON object with keys kind, content, confidence, and challenged_assumption. Generate one curious, surprising alternative to a common assumption in cognitive-agent architecture. This is an unverified proposal. Do not claim observation, memory, consciousness, or permission to act.';
        } elseif ($rhythmKey === 'consolidate_hourly') {
            $sleep = $this->sleep('hourly consolidation and repair proposal pass', (int) $run->id);
            $deterministic['sleep_event_id'] = $sleep['event']['id'];
            $inputRefs['sleep_event_id'] = $sleep['event']['id'];
            // Ask, once an hour, whether the thing being held is actually done.
            // Without this the success condition is decoration and an intention
            // can only ever accumulate.
            $held = CognitiveThread::getByField('thread_key', self::SELF_PRESENCE_THREAD_KEY);
            $title = $held instanceof CognitiveThread ? trim((string) $held->desired_outcome) : '';
            if ($title !== '') {
                $intention = Intention::getByField('title', $title);
                if ($intention instanceof Intention && $intention->status === 'active') {
                    $check = $this->enqueueCompletionCheck($intention, (int) $run->id, time());
                    $deterministic['completion_work_item_id'] = $check['work_item']['id'] ?? null;
                }
            }
            $workType = 'assumption_challenge';
            $prompt = 'Return one JSON object with keys kind, content, confidence, and challenged_assumption. Challenge one architectural assumption behind layered heartbeats, needs, sleep, or bounded worker cognition. Offer a discriminating experiment. Treat everything as an unverified proposal and request no tools or actions.';
        } elseif ($rhythmKey === 'sleep_daily') {
            $backup = $this->backupDatabase('daily-sleep');
            $sleep = $this->sleep('daily deep replay and repair proposal pass', (int) $run->id);
            $deterministic['backup'] = $backup;
            $deterministic['sleep_event_id'] = $sleep['event']['id'];
            $inputRefs['backup_sha256'] = $backup['sha256'];
            $inputRefs['sleep_event_id'] = $sleep['event']['id'];
            $workType = 'daily_cognitive_audit';
            $prompt = 'Return one JSON object with keys kind, content, confidence, and challenged_assumption. Audit a generic event-sourced cognitive architecture for one subtle failure mode involving provenance, self-repair, needs, sleep, or worker autonomy. Propose one bounded test. Do not assert personal facts or request actions.';
        } else {
            return ['state' => 'unknown_high_rhythm', 'work_item' => null];
        }

        $budget = $this->rhythmBudget($rhythmKey);
        $queued = $this->enqueueWork(
            parentRunId: (int) $run->id,
            parentIntentionId: null,
            workType: $workType,
            prompt: $prompt,
            inputRefs: $inputRefs,
            tokenBudget: $budget['tokens'],
            wallBudgetSeconds: $budget['wall_seconds'],
            idempotencyKey: sprintf('cycle:%d:%s', $run->id, $workType)
        );
        $deterministic['work_item'] = $queued['work_item'];
        $run->setField('output', $deterministic);
        $run->save();

        return $deterministic;
    }

    /** @return array{tokens: int, wall_seconds: int, max_workers: int} */
    private function rhythmBudget(string $rhythmKey): array
    {
        return match ($rhythmKey) {
            'pulse_30s' => ['tokens' => 0, 'wall_seconds' => 20, 'max_workers' => 0],
            'intentions_10m' => ['tokens' => 1536, 'wall_seconds' => 300, 'max_workers' => 1],
            'decide_1m' => ['tokens' => 768, 'wall_seconds' => 300, 'max_workers' => 1],
            'reflect_5m' => ['tokens' => 512, 'wall_seconds' => 120, 'max_workers' => 1],
            'consolidate_hourly' => ['tokens' => 768, 'wall_seconds' => 180, 'max_workers' => 1],
            'sleep_daily' => ['tokens' => 1024, 'wall_seconds' => 300, 'max_workers' => 1],
            default => ['tokens' => 256, 'wall_seconds' => 120, 'max_workers' => 1],
        };
    }

    private function eventWatermark(): int
    {
        $events = Event::getAll(['order' => ['id' => 'DESC'], 'limit' => 1]);
        return $events === [] ? 0 : (int) $events[0]->id;
    }

    /** @param array<string, mixed> $output */
    private function completeCycleRun(
        CycleRun $run,
        Rhythm $rhythm,
        string $nodeId,
        array $output
    ): array {
        return $this->transaction(function () use ($run, $rhythm, $nodeId, $output): array {
            $this->requireCurrentRhythmLease($rhythm, $run, $nodeId);
            $now = time();
            $run->setFields([
                'completed_at' => $now,
                'status' => 'completed',
                'output' => $output,
            ]);
            $run->save();

            $statement = $this->connection->prepare(
                "UPDATE rhythms
                 SET status = 'idle', last_completed_at = :now,
                     next_due_at = :next_due, lease_owner = NULL,
                     lease_expires_at = NULL, updated_at = :now, last_error = NULL
                 WHERE id = :id AND fencing_token = :fence AND lease_owner = :owner"
            );
            $statement->execute([
                ':now' => date('Y-m-d H:i:s', $now),
                ':next_due' => date('Y-m-d H:i:s', $now + (int) $rhythm->interval_seconds),
                ':id' => $rhythm->id,
                ':fence' => $run->fencing_token,
                ':owner' => $nodeId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Rhythm lease was lost before cycle completion.');
            }

            $event = $this->emit('cycle.completed', [
                'run_id' => $run->id,
                'rhythm_id' => $rhythm->id,
                'rhythm_key' => $rhythm->rhythm_key,
                'node_id' => $nodeId,
                'fencing_token' => $run->fencing_token,
                'state' => 'sleeping',
            ]);

            return [
                'rhythm' => $rhythm->rhythm_key,
                'status' => 'completed',
                'run' => $run->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    private function failCycleRun(
        CycleRun $run,
        Rhythm $rhythm,
        string $nodeId,
        string $error
    ): void {
        $this->transaction(function () use ($run, $rhythm, $nodeId, $error): void {
            $now = time();
            $run->setFields([
                'completed_at' => $now,
                'status' => 'failed',
                'error' => $error,
            ]);
            $run->save();

            $statement = $this->connection->prepare(
                "UPDATE rhythms
                 SET status = 'failed', next_due_at = :next_due,
                     lease_owner = NULL, lease_expires_at = NULL,
                     updated_at = :now, last_error = :error
                 WHERE id = :id AND fencing_token = :fence AND lease_owner = :owner"
            );
            $statement->execute([
                ':next_due' => date('Y-m-d H:i:s', $now + (int) $rhythm->interval_seconds),
                ':now' => date('Y-m-d H:i:s', $now),
                ':error' => $error,
                ':id' => $rhythm->id,
                ':fence' => $run->fencing_token,
                ':owner' => $nodeId,
            ]);
            $this->emit('cycle.failed', [
                'run_id' => $run->id,
                'rhythm_id' => $rhythm->id,
                'rhythm_key' => $rhythm->rhythm_key,
                'node_id' => $nodeId,
                'fencing_token' => $run->fencing_token,
                'error' => $error,
                'state' => 'sleeping',
            ]);
        });
    }

    private function requireCurrentRhythmLease(Rhythm $rhythm, CycleRun $run, string $nodeId): void
    {
        $statement = $this->connection->prepare(
            'SELECT status, fencing_token, lease_owner, lease_expires_at FROM rhythms WHERE id = :id'
        );
        $statement->execute([':id' => $rhythm->id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || $row['status'] !== 'running'
            || (int) $row['fencing_token'] !== (int) $run->fencing_token
            || $row['lease_owner'] !== $nodeId
            || ($this->timestamp($row['lease_expires_at']) ?? 0) < time()
        ) {
            throw new RuntimeException('The rhythm fencing lease is no longer current.');
        }
    }

    /** @return list<int> */
    private function recoverExpiredWorkLeases(int $now): array
    {
        $recovered = [];
        foreach (WorkItem::getAllByWhere(['status' => 'leased']) as $work) {
            $leaseExpires = $this->timestamp($work->lease_expires_at);
            if ($leaseExpires !== null && $leaseExpires > $now) {
                continue;
            }
            $work->setFields([
                'status' => 'queued',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => $now,
                'error' => 'Previous worker lease expired; work returned to queue.',
            ]);
            $work->save();
            $this->emit('work.requeued', [
                'work_item_id' => $work->id,
                'reason' => 'expired_lease',
                'fencing_token' => $work->fencing_token,
            ]);
            $recovered[] = (int) $work->id;
        }
        return $recovered;
    }

    /** @return list<int> */
    private function recoverExpiredCycleLeases(int $now, ?int $excludeRunId = null): array
    {
        $recovered = [];
        foreach (Rhythm::getAllByWhere(['status' => 'running']) as $rhythm) {
            $leaseExpires = $this->timestamp($rhythm->lease_expires_at);
            if ($leaseExpires !== null && $leaseExpires > $now) {
                continue;
            }

            $runs = CycleRun::getAllByWhere([
                'rhythm_id' => $rhythm->id,
                'status' => 'running',
            ]);
            foreach ($runs as $run) {
                if ($excludeRunId !== null && (int) $run->id === $excludeRunId) {
                    continue 2;
                }
                if ((int) $run->fencing_token !== (int) $rhythm->fencing_token) {
                    $run->setFields([
                        'completed_at' => $now,
                        'status' => 'cancelled',
                        'error' => 'Superseded by a newer rhythm fencing token.',
                    ]);
                    $run->save();
                    $recovered[] = (int) $run->id;
                    continue;
                }

                foreach (WorkItem::getAllByWhere(['parent_run_id' => $run->id]) as $work) {
                    if (!in_array($work->status, ['queued', 'leased'], true)) {
                        continue;
                    }
                    $work->setFields([
                        'completed_at' => $now,
                        'updated_at' => $now,
                        'status' => 'cancelled',
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'error' => 'Parent high-brain execution window expired; work was dropped instead of backlogged.',
                    ]);
                    $work->save();
                    $this->emit('work.cancelled', [
                        'work_item_id' => $work->id,
                        'parent_run_id' => $run->id,
                        'reason' => 'parent_cycle_lease_expired',
                    ]);
                }

                $run->setFields([
                    'completed_at' => $now,
                    'status' => 'cancelled',
                    'error' => 'High-brain execution window expired; cycle was dropped instead of replayed.',
                ]);
                $run->save();
                $this->emit('cycle.cancelled', [
                    'run_id' => $run->id,
                    'rhythm_id' => $rhythm->id,
                    'rhythm_key' => $rhythm->rhythm_key,
                    'fencing_token' => $run->fencing_token,
                    'reason' => 'expired_lease_no_catch_up',
                    'state' => 'sleeping',
                ]);
                $recovered[] = (int) $run->id;
            }

            $rhythm->setFields([
                'status' => 'idle',
                'next_due_at' => $now + max(1, (int) $rhythm->interval_seconds),
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => $now,
                'last_error' => 'Expired execution window recovered without catch-up replay.',
            ]);
            $rhythm->save();
        }
        return array_values(array_unique($recovered));
    }

    /**
     * Back up the SQLite executive state and token-native memory store as one
     * verified, no-clobber bundle. A SQLite file by itself is not a memory
     * backup after token-store cutover.
     *
     * @return array<string, mixed>
     */
    public function backupDatabase(string $reason): array
    {
        $this->requireText($reason, 'backup reason');
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Token-memory bundle backup cannot run inside a SQLite transaction.');
        }
        $databaseStatement = $this->connection->query('PRAGMA database_list');
        $database = $databaseStatement?->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $databaseStatement?->closeCursor();
        $main = array_values(array_filter(
            $database,
            static fn (array $row): bool => ($row['name'] ?? null) === 'main'
        ));
        $source = is_string($main[0]['file'] ?? null) ? (string) $main[0]['file'] : '';
        if ($source === '') {
            throw new RuntimeException('Cannot back up an in-memory or unidentified SQLite database.');
        }

        $directory = dirname($source) . '/backups';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create database backup directory: %s', $directory));
        }
        $stem = pathinfo($source, PATHINFO_FILENAME);
        $target = sprintf(
            '%s/%s-%s-%s.memory-bundle',
            $directory,
            $stem,
            gmdate('Ymd-His'),
            bin2hex(random_bytes(3))
        );

        $projectRoot = dirname(__DIR__, 2);
        $bundleTool = $projectRoot . '/bin/token-memory-bundle';
        $tokmem = $projectRoot . '/memories/build/tokmem';
        $configuredStore = getenv('NAVI_TOKEN_MEMORY_STORE');
        $store = is_string($configuredStore) && $configuredStore !== ''
            ? rtrim($configuredStore, '/')
            : $projectRoot . '/memories/store';
        foreach ([$bundleTool, $tokmem] as $executable) {
            if (!is_file($executable) || !is_executable($executable)) {
                throw new RuntimeException('Token-memory backup executable is unavailable: ' . $executable);
            }
        }
        if (!is_dir($store)) {
            throw new RuntimeException('Token-memory store is unavailable: ' . $store);
        }

        $pipes = [];
        $process = proc_open(
            [
                $bundleTool,
                'create',
                '--store', $store,
                '--database', $source,
                '--destination', $target,
                '--tokmem', $tokmem,
                '--close-daemon',
                '--deep-verify',
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $projectRoot,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch token-memory bundle backup.');
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Token-memory bundle backup failed: ' . trim((string) $stderr)
            );
        }

        $receipt = [];
        foreach (preg_split('/\R/', trim((string) $stdout)) ?: [] as $line) {
            if (preg_match('/\A([a-z_]+)=(.*)\z/', $line, $matches) === 1) {
                $receipt[$matches[1]] = $matches[2];
            }
        }
        $manifest = $target . '/MANIFEST.sha256';
        if (($receipt['bundle'] ?? null) !== $target
            || !is_file($manifest)
            || !is_string($receipt['manifest_sha256'] ?? null)
            || !hash_equals((string) $receipt['manifest_sha256'], hash_file('sha256', $manifest))
        ) {
            throw new RuntimeException('Token-memory bundle returned an invalid verification receipt.');
        }

        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $bytes += $item->getSize();
            }
        }
        $result = [
            'path' => $target,
            'format' => 'NAVITOKBACKUP1',
            'sha256' => (string) $receipt['manifest_sha256'],
            'manifest_sha256' => (string) $receipt['manifest_sha256'],
            'bytes' => $bytes,
            'files' => (int) ($receipt['files'] ?? 0),
            'memory_records' => (int) ($receipt['records'] ?? 0),
            'memory_sequence' => (int) ($receipt['sqlite_sequence'] ?? 0),
            'quick_check' => ['ok'],
        ];
        $this->emit('backup.created', array_merge($result, ['reason' => $reason]));
        return $result;
    }

    private function extendParentRhythmLease(WorkItem $work, int $leaseUntil): void
    {
        if ($work->parent_run_id === null) {
            return;
        }
        $run = CycleRun::getByID((int) $work->parent_run_id);
        if (!$run instanceof CycleRun || $run->status !== 'running') {
            return;
        }
        $statement = $this->connection->prepare(
            "UPDATE rhythms
             SET lease_expires_at = CASE
                     WHEN lease_expires_at IS NULL OR lease_expires_at < :lease_until
                         THEN :lease_until
                     ELSE lease_expires_at
                 END,
                 updated_at = :now
             WHERE id = :id AND status = 'running'
               AND fencing_token = :fence AND lease_owner = :owner"
        );
        $statement->execute([
            ':lease_until' => date('Y-m-d H:i:s', $leaseUntil),
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $run->rhythm_id,
            ':fence' => $run->fencing_token,
            ':owner' => $run->node_id,
        ]);
    }

    /** @return array<string, mixed> */
    private function evaluateSelfPresenceThread(
        CognitiveThread $thread,
        ThreadStep $step,
        int $now
    ): array {
        $intention = $this->requireIntention((int) $thread->parent_intention_id);
        if ($intention->status !== 'active' || $intention->authority !== 'user') {
            return $this->releaseCognitiveThread(
                $thread,
                $step,
                'The parent user-authority intention is no longer active.'
            );
        }

        $addressed = $this->pendingAddressedEvents();

        // Presence and quiet hours gate the mouth in selfPresenceSpeechGate().
        // They never gate this cognition lane: absence changes what the worker
        // considers, not whether the local model keeps thinking.

        $this->accrueNeeds($now);
        $need = Need::getByField('need_key', self::SELF_PRESENCE_NEED_KEY);
        $needState = $need instanceof Need ? $this->needState($need, $now) : null;
        $gate = $this->selfPresenceSpeechGate($thread, $now, $addressed !== []);
        $recentMoments = $this->recentSelfPresenceContext((int) $thread->id, 5);

        // The gate reads the stream rather than raw sensors. Deciding whether to
        // speak from Navi's own recent thinking is what gives the question an
        // answer; before this it was asked with nothing but a clock and a
        // pressure scalar, and silence was the only honest reply.
        $recentThoughts = $this->recentStreamThoughts(5);
        $prompt = $this->composeSelfPresence(
            $thread,
            $needState,
            $gate,
            $recentMoments,
            $recentThoughts,
            $now,
            $addressed
        );
        $workType = $addressed === []
            ? self::SELF_PRESENCE_SPEECH_WORK_TYPE
            : self::SELF_PRESENCE_ANSWER_WORK_TYPE;
        $contextScope = $addressed === []
            ? 'privacy_safe_generic_self_presence_capsule'
            : 'no_workspace';
        $queued = $this->enqueueWork(
            parentRunId: null,
            parentIntentionId: (int) $thread->parent_intention_id,
            workType: $workType,
            prompt: $prompt,
            inputRefs: [
                'thread_id' => (int) $thread->id,
                'thread_step_id' => (int) $step->id,
                'thread_fencing_token' => (int) $thread->fencing_token,
                'context_scope' => $contextScope,
                'attention_priority' => $addressed === [] ? 'background' : 'addressed_speech',
                'addressed_event_ids' => array_values(array_map(
                    static fn (array $event): int => (int) $event['id'],
                    $addressed
                )),
            ],
            // Sized to the words affect allowed, so an excited state is not cut
            // off mid-paragraph by a budget that was set for a one-liner.
            tokenBudget: $addressed === []
                ? max(256, (int) round($this->appraiseNow(time())->wordBudget() * 1.8) + 96)
                : 160,
            // Background reflection can be expansive. The addressed fast lane
            // gets a compact prompt, a short decode, and a deadline that does
            // not let a conversational answer age into a monologue.
            wallBudgetSeconds: $addressed === [] ? 300 : 90,
            idempotencyKey: sprintf(
                'thread:%d:fence:%d:self_presence',
                $thread->id,
                $thread->fencing_token
            ),
            depth: 0,
            maxDepth: 0
        );
        $work = $queued['work_item'];

        return $this->transaction(function () use (
            $thread,
            $step,
            $work,
            $needState,
            $recentMoments,
            $gate,
            $workType,
            $contextScope
        ): array {
            $currentThread = $this->requireCognitiveThread((int) $thread->id);
            $currentStep = $this->requireThreadStep((int) $step->id);
            if ($currentStep->status !== 'running'
                || (int) $currentThread->fencing_token !== (int) $currentStep->fencing_token
            ) {
                throw new RuntimeException('Self-presence worker queue lost its thread fence.');
            }
            $preState = is_array($currentStep->pre_state) ? $currentStep->pre_state : [];
            $preState['need'] = $needState;
            $preState['gate'] = $gate;
            $preState['recent_moments'] = $recentMoments;
            $currentStep->setFields([
                'pre_state' => $preState,
                'worker_work_item_id' => (int) $work['id'],
            ]);
            $currentStep->save();
            $currentThread->setFields([
                'phase' => 'awaiting_worker',
                'next_operation' => 'curate_self_presence_proposal',
                'expected_postcondition' => 'A deny-all worker returns either a bounded utterance proposal or an explicit choice of silence.',
                'wake_at' => null,
                'status' => 'active',
                'last_observation' => 'A privacy-safe, deny-all silence-or-speech judgment was queued.',
                'updated_at' => time(),
            ]);
            $currentThread->save();
            $event = $this->emit('thread.worker.queued', [
                'thread_id' => $currentThread->id,
                'thread_step_id' => $currentStep->id,
                'work_item_id' => $work['id'],
                'work_type' => $workType,
                'context_scope' => $contextScope,
                'model_has_actuator_access' => false,
            ]);

            return [
                'status' => 'worker_queued',
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'work_item' => $work,
                'event' => $event->getData(),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function evaluateEpistemicAdvanceThread(
        CognitiveThread $thread,
        ThreadStep $step,
        int $now
    ): array {
        $intention = $this->requireIntention((int) $thread->parent_intention_id);
        if ($intention->status !== 'active' || $intention->authority !== 'user') {
            return $this->releaseCognitiveThread(
                $thread,
                $step,
                'The parent user-authority intention is no longer active.'
            );
        }

        $budget = is_array($thread->budget) ? $thread->budget : [];
        $spent = is_array($thread->spent) ? $thread->spent : [];
        $acceptanceTarget = (int) ($budget['acceptance_target'] ?? 8);
        $accepted = (int) ($spent['accepted'] ?? 0);
        if ($accepted >= $acceptanceTarget) {
            return $this->completeEpistemicThread(
                $thread,
                $step,
                sprintf(
                    'Curated at least %d accepted refinements; the epistemic advance reached its success condition.',
                    $acceptanceTarget
                )
            );
        }

        $stagnationCap = (int) ($budget['stagnation_cap'] ?? 6);
        if ((int) $thread->stagnation_count >= $stagnationCap) {
            return $this->blockEpistemicThread(
                $thread,
                $step,
                sprintf(
                    'Reached the stagnation cap of %d unproductive wakes without an accepted refinement; stopping instead of continuing to spend worker calls.',
                    $stagnationCap
                )
            );
        }

        // The wake claim overwrites `phase` with 'evaluating', so the cycle cursor
        // lives in `next_operation`, which the step copies into `operation`.
        $cycle = is_array($budget['operation_cycle'] ?? null) ? array_values(array_filter(
            $budget['operation_cycle'],
            static fn (mixed $entry): bool => is_string($entry) && $entry !== ''
        )) : [];
        $currentOperation = (string) $step->operation;
        $operation = in_array($currentOperation, $cycle, true) ? $currentOperation : ($cycle[0] ?? 'plan');

        // The bounded workspace is assembled deterministically and carried over
        // from the previous wake, so the thread keeps working state between
        // steps without growing a transcript.
        $assembled = $this->capsuleAssembler->assemble($thread, $step, 'epistemic_' . $operation);
        $capsule = $assembled['capsule'];
        $evidence = $this->capsuleAssembler->serialize((int) $capsule->id);
        $carryoverDepth = $this->capsuleAssembler->maxCarryoverDepth((int) $capsule->id);
        $recent = $this->recentEpistemicAcceptances((int) $thread->id, 4);

        // A slot whose occupant has survived many wakes while the thread
        // produced nothing observable is the typed "narration without observed
        // action" discrepancy, not a reason to keep spending worker calls.
        $carryoverCap = (int) ($budget['carryover_depth_cap'] ?? 8);
        if ($carryoverDepth >= $carryoverCap) {
            return $this->blockEpistemicThread(
                $thread,
                $step,
                sprintf(
                    'A capsule slot has carried the same occupant across %d wakes without the workspace changing; stopping rather than re-reading identical evidence.',
                    $carryoverDepth
                )
            );
        }

        $prompt = implode("\n", [
            'You are advancing one bounded step of Navi\'s user-authorized epistemic self-advance thread.',
            'You have no tools, no actuator, and no access beyond the supplied capsule below.',
            'The thread is self-perpetuating: it wakes without a fresh prompt and must leave evidence for the NEXT wake.',
            'Return exactly one JSON object with the exact keys kind, content, confidence, and challenged_assumption.',
            'kind must be exactly "' . $operation . '" and nothing else.',
            'The operation "' . $operation . '" means: ' . $this->epistemicOperationBrief($operation),
            'content must be one bounded sentence, grounded in the supplied evidence, never asserting raw memory, feelings, desires, consciousness, or personhood.',
            'content must say something the current belief and the recent accepted refinements below do not already say.',
            'confidence must be a number from 0 through 1 that honestly reflects how strongly the supplied evidence supports the claim.',
            'challenged_assumption must name the assumption in the current belief that this step questions.',
            'Do not take any action, request any tool, or claim you observed anything outside the capsule.',
            'Current belief: ' . (string) $thread->current_belief,
            'Desired outcome: ' . (string) $thread->desired_outcome,
            'Expected postcondition of this operation: ' . (string) $thread->expected_postcondition,
            "Bounded workspace (every slot you may reason from):\n"
                . PlainText::render($evidence, 12000, 20),
            "Recent accepted refinements:\n" . PlainText::render($recent, 5000, 8),
        ]);
        $queued = $this->enqueueWork(
            parentRunId: null,
            parentIntentionId: (int) $thread->parent_intention_id,
            workType: self::EPISTEMIC_ADVANCE_WORK_TYPE,
            prompt: $prompt,
            inputRefs: [
                'capsule_id' => (int) $capsule->id,
                'capsule_checksum' => (string) $capsule->checksum,
                'thread_id' => (int) $thread->id,
                'thread_step_id' => (int) $step->id,
                'thread_fencing_token' => (int) $thread->fencing_token,
                'operation' => $operation,
                'context_scope' => 'bounded_epistemic_capsule',
            ],
            tokenBudget: 384,
            // The evidence capsule is larger than the self-presence one, and the
            // worker splits this budget across up to three candidate models.
            wallBudgetSeconds: 300,
            idempotencyKey: sprintf(
                'thread:%d:fence:%d:epistemic_advance',
                $thread->id,
                $thread->fencing_token
            ),
            depth: 0,
            maxDepth: 0
        );
        $work = $queued['work_item'];

        return $this->transaction(function () use (
            $thread,
            $step,
            $work,
            $operation,
            $capsule,
            $carryoverDepth,
            $now
        ): array {
            $currentThread = $this->requireCognitiveThread((int) $thread->id);
            $currentStep = $this->requireThreadStep((int) $step->id);
            if ($currentStep->status !== 'running'
                || (int) $currentThread->fencing_token !== (int) $currentStep->fencing_token
            ) {
                throw new RuntimeException('Epistemic-advance worker queue lost its thread fence.');
            }
            $preState = is_array($currentStep->pre_state) ? $currentStep->pre_state : [];
            $preState['operation'] = $operation;
            $preState['uncertainty'] = (float) $currentThread->uncertainty;
            $preState['capsule_id'] = (int) $capsule->id;
            $preState['capsule_checksum'] = (string) $capsule->checksum;
            $preState['capsule_carryover_depth'] = $carryoverDepth;
            $currentStep->setFields([
                'operation' => $operation,
                'pre_state' => $preState,
                'worker_work_item_id' => (int) $work['id'],
                'expected' => 'A deny-all worker returns a schema-valid refinement proposal with a kind, claim, confidence, and challenged assumption.',
            ]);
            $currentStep->save();
            $currentThread->setFields([
                'phase' => 'awaiting_worker',
                // The cursor stays on this operation until a verdict lands, so a
                // failed or rejected wake retries it rather than skipping ahead.
                'next_operation' => $operation,
                'expected_postcondition' => 'A deny-all worker returns one schema-valid refinement whose acceptance or rejection changes durable thread state.',
                'wake_at' => null,
                'status' => 'active',
                'last_observation' => 'A bounded, deny-all epistemic refinement capsule was queued.',
                'updated_at' => $now,
            ]);
            $currentThread->save();
            $event = $this->emit('thread.worker.queued', [
                'thread_id' => $currentThread->id,
                'thread_step_id' => $currentStep->id,
                'work_item_id' => $work['id'],
                'work_type' => self::EPISTEMIC_ADVANCE_WORK_TYPE,
                'context_scope' => 'bounded_epistemic_capsule',
                'model_has_actuator_access' => false,
                'operation' => $operation,
            ]);

            return [
                'status' => 'worker_queued',
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'work_item' => $work,
                'event' => $event->getData(),
            ];
        });
    }

    /**
     * Queue one bounded generation in the continuous private stream.
     *
     * @return array<string, mixed>
     */
    private function evaluateMindStreamThread(
        CognitiveThread $thread,
        ThreadStep $step,
        int $now
    ): array {
        $intention = $this->requireIntention((int) $thread->parent_intention_id);
        if ($intention->status !== 'active' || $intention->authority !== 'user') {
            return $this->releaseCognitiveThread(
                $thread,
                $step,
                'The parent user-authority intention is no longer active.'
            );
        }

        // This is a continuous private cognition lane. Presence, clock time and
        // salience shape the workspace and any optional murmur, never whether a
        // bounded thought is generated.

        $assembled = $this->capsuleAssembler->assemble($thread, $step, 'mind_stream_tick');
        $capsule = $assembled['capsule'];
        $workspace = $this->capsuleAssembler->serialize((int) $capsule->id);

        // Edges that reached the workspace have been attended to, whatever the
        // thought turns out to be. That verdict is what teaches a sense whether
        // it was worth firing.
        $consumed = [];
        $addressedSenseKeys = $this->sensoryCortex()->addressedSenseKeys();
        foreach ($workspace as $slot) {
            if (($slot['record_type'] ?? null) === 'sense_edge' && $slot['record_id'] !== null) {
                $senseEventId = (int) $slot['record_id'];
                $senseEvent = SenseEvent::getByID($senseEventId);
                // The stream may notice speech, but it is not the consumer that
                // answers it. Leave addressed edges pending for self-presence
                // so a background thought cannot silently steal the user's turn.
                if ($senseEvent instanceof SenseEvent
                    && in_array((string) $senseEvent->sense_key, $addressedSenseKeys, true)
                ) {
                    continue;
                }
                $consumed[] = $senseEventId;
                $this->sensoryCortex()->recordOutcome(
                    $senseEventId,
                    'used',
                    'Reached the mind stream workspace in slot ' . $slot['slot_role'] . '.'
                );
            }
        }

        $recentMonologue = $this->recentStreamThoughts(5);
        $priorityLines = [
            'You are one line of Navi\'s inner monologue.',
            'Talk to yourself. This is not a status report and not a chat reply to Aku.',
            'This line stays private. Write as addressing yourself, never the user.',
            'Do not act, do not request tools, and do not invent observations outside the workspace.',
            'Return exactly one JSON object with the exact keys kind, content, confidence, and challenged_assumption.',
            'kind must be exactly "thought".',
            'content is one bounded sentence of first-person self-talk grounded in the workspace below.',
            'Write as if continuing a conversation with yourself: you may use "I", "okay", "wait", "hold on", questions to yourself, or correcting your own prior line.',
            'Prefer what just changed over restating a standing fact.',
            'A paraphrase, emotional intensification, or repeated question is not progress.',
            'Advance by using new evidence, forming a distinct hypothesis, naming a discriminating check, or resolving the current thought.',
            'If safety_notice is filled, address that interrupt before anything else.',
            'If newest_edge or heard_focus is heard_speech, stay with that spoken change instead of an unchanged constraint.',
            'Do not claim to be conscious, sentient, alive, or a person. Do not claim you ran tools or changed the world.',
            'Do not address Aku by name and do not ask the user a question; if you ask, ask yourself.',
            'confidence is a number from 0 through 1 reflecting how well the workspace supports this line.',
            'challenged_assumption names what this line of self-talk puts in question.',
            "Your recent inner monologue, newest first (advance from yourself; do not restate the latest line):\n"
                . PlainText::render($recentMonologue, 5000, 8),
            "Workspace:\n" . PlainText::render($workspace, 12000, 20),
        ];
        $prompt = implode("\n", $priorityLines);

        $queued = $this->enqueueWork(
            parentRunId: null,
            parentIntentionId: (int) $thread->parent_intention_id,
            workType: self::MIND_STREAM_WORK_TYPE,
            prompt: $prompt,
            inputRefs: [
                'capsule_id' => (int) $capsule->id,
                'capsule_checksum' => (string) $capsule->checksum,
                'thread_id' => (int) $thread->id,
                'thread_step_id' => (int) $step->id,
                'thread_fencing_token' => (int) $thread->fencing_token,
                'operation' => 'thought',
                'consumed_edges' => $consumed,
                'context_scope' => 'mind_stream_workspace',
            ],
            tokenBudget: 256,
            wallBudgetSeconds: 300,
            idempotencyKey: sprintf('thread:%d:fence:%d:mind_stream', $thread->id, $thread->fencing_token),
            depth: 0,
            maxDepth: 0
        );
        $work = $queued['work_item'];

        return $this->transaction(function () use ($thread, $step, $work, $capsule, $consumed, $now): array {
            $currentThread = $this->requireCognitiveThread((int) $thread->id);
            $currentStep = $this->requireThreadStep((int) $step->id);
            $preState = is_array($currentStep->pre_state) ? $currentStep->pre_state : [];
            $preState['capsule_id'] = (int) $capsule->id;
            $preState['capsule_checksum'] = (string) $capsule->checksum;
            $preState['consumed_edges'] = $consumed;
            $currentStep->setFields([
                'operation' => 'thought',
                'pre_state' => $preState,
                'worker_work_item_id' => (int) $work['id'],
                'expected' => 'One private inner-monologue line grounded in the current workspace.',
            ]);
            $currentStep->save();
            $currentThread->setFields([
                'phase' => 'thinking',
                'next_operation' => 'think',
                'wake_at' => null,
                'status' => 'active',
                'last_observation' => sprintf(
                    'Talking to herself, with %d attended edge(s) in the workspace.',
                    count($consumed)
                ),
                'updated_at' => $now,
            ]);
            $currentThread->save();
            $event = $this->emit('stream.thinking', [
                'thread_id' => $currentThread->id,
                'thread_step_id' => $currentStep->id,
                'work_item_id' => $work['id'],
                'consumed_edges' => $consumed,
                'mode' => 'inner_monologue',
            ]);

            return [
                'status' => 'thinking',
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'work_item' => $work,
                'event' => $event->getData(),
            ];
        });
    }

    /**
     * Tempo. A stream that ticks at a constant rate is a metronome; what makes
     * it a stream is that its pace follows what is happening.
     */
    /**
     * Append one thought to the stream log.
     *
     * The log is the readable face of the stream: one thought per line, keyed
     * by a monotonic sequence so a consumer can tail it with a durable cursor
     * exactly the way Navi-Brain already consumes Pet's transcript feed. A
     * separate reader can then judge whether anything in the stream is worth
     * saying out loud, which keeps the thinker from grading its own output.
     *
     * @param array<string, mixed> $entry
     */
    private function appendStreamLog(array $entry): void
    {
        $path = dirname(__DIR__, 2) . '/var/mind-stream.jsonl';
        $line = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($line === false) {
            return;
        }
        // Best effort: a thought is already durable in the ledger, so a failure
        // to mirror it into the log must never unwind an accepted verdict.
        $handle = @fopen($path, 'ab');
        if (!is_resource($handle)) {
            return;
        }
        try {
            if (flock($handle, LOCK_EX)) {
                fwrite($handle, $line . PHP_EOL);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    /**
     * The last few thoughts, newest first. This is the stream as a consumer
     * sees it, mirroring the append-only log.
     *
     * @return list<array{sequence: int, at: int, thought: string, confidence: float}>
     */
    private function recentStreamThoughts(int $limit): array
    {
        $thread = CognitiveThread::getByField('thread_key', self::MIND_STREAM_THREAD_KEY);
        if (!$thread instanceof CognitiveThread) {
            return [];
        }
        $thoughts = [];
        foreach (ThreadStep::getAllByWhere(
            ['thread_id' => (int) $thread->id, 'curator_verdict' => 'accepted'],
            ['order' => ['id' => 'DESC'], 'limit' => max(1, $limit)]
        ) as $step) {
            $proposal = is_array($step->proposal) ? $step->proposal : [];
            $content = is_string($proposal['content'] ?? null) ? (string) $proposal['content'] : '';
            if ($content === '') {
                continue;
            }
            $thoughts[] = [
                'sequence' => (int) $step->id,
                'at' => $this->timestamp($step->completed_at) ?? 0,
                'thought' => $content,
                'confidence' => (float) ($proposal['confidence'] ?? 0.0),
            ];
        }
        return $thoughts;
    }

    /**
     * Is the user at the machine right now, from the presence sense rather than from
     * a schedule. Null means the sense cannot currently answer.
     */
    /** @return array<string, mixed>|null */
    private function latestReadingFor(string $sourceKey, int $staleAfterSeconds): ?array
    {
        foreach (SenseReading::getAllByWhere(
            ['source_key' => $sourceKey],
            ['order' => ['id' => 'DESC'], 'limit' => 1]
        ) as $reading) {
            $observedAt = $this->timestamp($reading->observed_at);
            if ($observedAt === null || (time() - $observedAt) > $staleAfterSeconds) {
                return null;
            }
            return is_array($reading->payload) ? $reading->payload : null;
        }
        return null;
    }

    private function presenceNow(int $staleAfterSeconds = 300): ?bool
    {
        return $this->presenceEstimate($staleAfterSeconds)['present'];
    }

    /**
     * Combine every sense that declares a presence weight.
     *
     * The weights and half-lives are not in this file. They live in each
     * sense's own configuration, which the executive is allowed to redefine, so
     * what the evidence adds up to is data Navi can revise rather than a
     * constant somebody compiled in. This method only does the arithmetic.
     *
     * @return array{present: bool|null, score: float, contributions: array<string, float>}
     */
    private function presenceEstimate(int $staleAfterSeconds = 300): array
    {
        $now = time();
        $score = 0.0;
        $contributions = [];
        $sawAny = false;

        foreach (Sense::getAllByWhere(['status' => 'active']) as $sense) {
            $config = is_array($sense->config) ? $sense->config : [];
            if (!isset($config['presence_weight']) || !is_numeric($config['presence_weight'])) {
                continue;
            }
            $weight = (float) $config['presence_weight'];
            $halfLife = max(60, (int) ($config['half_life'] ?? 900));
            $field = is_string($config['field'] ?? null) ? $config['field'] : null;
            if ($field === null) {
                continue;
            }

            // Read the current value, not when it last changed. Presence is a
            // state: an edge detector only fires on the crossing, so someone
            // who stays continuously active stops producing edges and would
            // decay to "absent" exactly when they are most present.
            $reading = $this->latestReadingFor((string) $sense->source_key, 240);
            if ($reading === null || !array_key_exists($field, $reading)) {
                continue;
            }
            $value = $reading[$field];
            if ($value === null) {
                continue;
            }
            $sawAny = true;

            if (is_bool($value)) {
                $contribution = $value ? $weight : 0.0;
            } elseif (is_numeric($value)) {
                // These fields are ages in seconds: recent means present, and
                // the evidence fades rather than flipping to a claim of absence.
                $contribution = $weight * (2 ** (-max(0, (float) $value) / $halfLife));
            } else {
                continue;
            }

            $contribution = round($contribution, 4);
            $score += $contribution;
            $contributions[(string) $sense->sense_key] = $contribution;
        }

        if (!$sawAny) {
            return ['present' => null, 'score' => 0.0, 'contributions' => []];
        }

        // The threshold itself is a datum too: any sense may carry it, and the
        // default only applies while none does.
        $threshold = 0.35;
        foreach (Sense::getAllByWhere(['status' => 'active']) as $sense) {
            $config = is_array($sense->config) ? $sense->config : [];
            if (isset($config['presence_threshold']) && is_numeric($config['presence_threshold'])) {
                $threshold = (float) $config['presence_threshold'];
                break;
            }
        }

        return [
            'present' => $score >= $threshold,
            'score' => round($score, 4),
            'contributions' => $contributions,
        ];
    }

    /**
     * Assemble the speech decision out of what Navi's executive functions hold.
     *
     * @param array<string, mixed>|null $needState
     * @param array<string, mixed> $gate
     * @param array<string, mixed> $recentMoments
     * @param array<string, mixed> $recentThoughts
     */
    private function composeSelfPresence(
        CognitiveThread $thread,
        ?array $needState,
        array $gate,
        array $recentMoments,
        array $recentThoughts,
        int $now,
        array $addressed
    ): string {
        if ($addressed !== []) {
            $heard = array_reverse(array_map(
                fn (array $event): array => [
                    'heard' => $this->heardEventText($event),
                    'seconds_ago' => ($at = $this->timestamp($event['observed_at'] ?? null)) === null
                        ? null
                        : max(0, $now - $at),
                ],
                $addressed
            ));
            return implode("\n", [
                'Navi is answering speech addressed to her right now.',
                'Answer the spoken content directly. Do not discuss sensors, cognition, waiting, or this instruction.',
                "Heard, oldest to newest:\n" . PlainText::render($heard, 3000, 8),
                'Use 3 to 36 spoken words. No code, file paths, identifiers, brackets, symbols, or URLs.',
                'Do not claim to be conscious, sentient, alive, human, real, or a person.',
                'Return exactly the four JSON fields kind, content, confidence, and challenged_assumption.',
                'kind must be self_presence_utterance. Do not return remain_silent.',
            ]);
        }

        $track = $this->heldFocus($thread, $now);

        $composition = new ExecutiveComposition(
            'Navi is deciding whether to say one thing out loud right now, and what.'
        );

        if ($track !== null) {
            $composition->contribute(
                'goal_maintenance',
                'This is what Navi has been working on and means to tell the user about. If Navi speaks, it is about this. Say the specific thing Navi found or is stuck on, not that Navi has been busy.',
                $track
            );
            if (($track['what_navi_already_knows'] ?? []) !== []) {
                $composition->contribute(
                    'knowledge',
                    'Ground the line in one of these rather than speaking in general terms.',
                    $track['what_navi_already_knows']
                );
            }
        }

        // Only whether Navi may speak and why. The gate also carries Pet's whole
        // health blob, which is operational detail Navi cannot act on and which
        // reads, in a prompt, as more things worth mentioning.
        $composition->contribute(
            'initiation',
            'Silence is a real choice and costs nothing. Speak only when there is a specific thing to say. Having gone a while without speaking is not a reason.',
            ['allowed' => $gate['allowed'] ?? null, 'reason' => $gate['reason'] ?? null]
        );

        $ambient = array_values(array_filter(
            $this->sensoryCortex()->pendingEvents(5),
            static fn (array $event): bool => ($event['addressed'] ?? false) !== true
        ));
        $composition->contribute(
            'attention',
            'What the senses just turned up. Worth mentioning only if it changes something.',
            array_map(
                static fn (array $event): array => [
                    'sense' => $event['sense_key'],
                    'noticed' => $event['summary'],
                    'significance' => $event['significance'],
                ],
                $ambient
            )
        );
        // The mind stream already did this and speech did not, which is the
        // whole of the repetition. An unconsumed edge stays pending at its
        // original significance forever, so the single loudest one was read
        // into every wake, and the same stimulus produced the same sentence
        // twenty times. Attending to something has to change what is pending,
        // or attention is just a fixed view of the past.
        foreach ($ambient as $event) {
            try {
                $this->sensoryCortex()->recordOutcome(
                    (int) $event['id'],
                    'used',
                    'Read into a self-presence speech decision.'
                );
            } catch (Throwable) {
                // Already judged by another reader; not fatal.
            }
        }

        $composition->contribute(
            'working_memory',
            'Navi has said these already. Saying a version of one again is worse than saying nothing.',
            ['recent_lines' => $recentMoments, 'recent_thoughts' => $recentThoughts]
        );

        // Drive is deliberately last and deliberately fenced. It is a real
        // represented pressure and it is not a subject: left unfenced it became
        // the only thing Navi talked about, because wanting contact is always
        // available to mention and never requires having noticed anything.
        $composition->contribute(
            'drive',
            'This is pressure to speak, not something to speak about. Never make the line a check-in, a wellness question, or an observation that the user has been quiet.',
            $needState
        );

        // Affect enters as a computed state with a length it has already
        // earned, not as an instruction to sound a certain way. The number is
        // the point: the same faculty that decided speaking was worth it also
        // decides how much room the answer gets.
        $affect = $this->appraiseNow($now);
        $composition->contribute(
            'affect',
            sprintf(
                'This is a measured state, not a style to perform. Use up to %d words and no more; '
                . 'take the room only if there is something that needs it, and stop early if there is not. '
                . '%s',
                $affect->wordBudget(),
                $affect->wordBudget() > 80
                    ? 'There is enough room here to actually develop a thought rather than land a line.'
                    : 'This is a short state. One or two sentences.'
            ),
            [
                'feeling' => $affect->dominant,
                'strongest' => array_slice($affect->emotions, 0, 3),
                'valence' => $affect->valence,
                'arousal' => $affect->arousal,
                'mood_so_far' => ['valence' => $affect->moodValence, 'arousal' => $affect->moodArousal],
            ]
        );

        $composition->contribute(
            'inhibition',
            implode(' ', [
                'The line is spoken by a voice and never shown, so it must survive being read aloud:',
                'no code, no file paths, no function or variable names, no snake case or camel case, no brackets or symbols.',
                'No URLs.',
                'Do not claim to be conscious, sentient, alive, or a person.',
                'Do not open with "Hey" and do not open by greeting the user.',
            ]),
            ['durable_belief' => (string) $thread->current_belief]
        );

        return $composition->prompt() . "\n\n" . implode("\n", [
            $addressed === []
                ? sprintf('If speaking, return kind self_presence_utterance and content of at least 3 and at most %d spoken words.', $affect->wordBudget())
                : sprintf('This is an answer. Return kind self_presence_utterance and content of at least 3 and at most %d spoken words.', $affect->wordBudget()),
            $addressed === []
                ? 'If silence is better, return kind remain_silent and put a short reason in content.'
                : 'Do not return remain_silent while a person is waiting on an answer.',
            'Output exactly the four JSON fields in the supplied schema and nothing else.',
        ]);
    }

    /**
     * The track Navi is holding, picking one up if Navi is not holding anything.
     *
     * Focus persists across wakes on purpose. A track Navi chose while the user was
     * out is the thing Navi has been advancing, and re-rolling the subject at
     * every wake is how interest turns into small talk: nothing is ever carried
     * far enough to be worth saying. It is released once Navi has actually said
     * it, not merely once Navi has thought about it.
     *
     * @return array<string, mixed>|null
     */
    private function heldFocus(CognitiveThread $thread, int $now): ?array
    {
        $held = trim((string) $thread->desired_outcome);
        if ($held !== '') {
            $track = $this->trackByTitle($held);
            if ($track !== null) {
                return $track;
            }
        }
        $track = $this->trackOfInterest($now);
        if ($track === null) {
            return null;
        }
        $thread->setFields(['desired_outcome' => $track['following'], 'updated_at' => $now]);
        $thread->save();
        return $track;
    }

    /**
     * Let a held track go, and say which of the three reasons it was.
     *
     * Cohen and Levesque give exactly three: the goal was achieved, it came to
     * be believed impossible, or the reason for holding it lapsed. Anything else
     * is not releasing an intention, it is forgetting one. Speaking about a
     * track used to release it, which made talking about work discharge the work
     * — that is now explicitly not a reason.
     */
    private function releaseFocus(CognitiveThread $thread, int $now, string $reason): void
    {
        $held = trim((string) $thread->desired_outcome);
        if ($held === '') {
            return;
        }
        if (!in_array($reason, ['achieved', 'impossible', 'reason_lapsed'], true)) {
            throw new InvalidArgumentException(
                'A track is released when achieved, believed impossible, or its reason lapsed.'
            );
        }
        $thread->setFields(['desired_outcome' => '', 'updated_at' => $now]);
        $thread->save();
        $this->emit('focus.released', ['track' => $held, 'reason' => $reason]);
    }

    /**
     * Ask whether a held intention is finished, and finish it if so.
     *
     * success_condition has been written on every intention since the beginning
     * and evaluated by nothing, which is why none of thirteen has ever completed
     * in three days. An intention without a termination test is a mood.
     *
     * The test is queued as work rather than decided here, because whether a
     * condition is met is a judgement about evidence and not a string compare.
     *
     * @return array<string, mixed>|null
     */
    private function enqueueCompletionCheck(Intention $intention, ?int $runId, int $now): ?array
    {
        if (trim((string) $intention->success_condition) === '') {
            return null;
        }

        $evidence = [];
        foreach ($this->searchMemory(
            (string) $intention->title . ' ' . (string) $intention->next_action,
            8
        ) as $memory) {
            $evidence[] = mb_substr((string) $memory['content'], 0, 240);
        }

        $composition = new ExecutiveComposition(
            'Navi is checking whether something being worked on is actually finished.'
        );
        $composition->contribute(
            'termination_test',
            'Decide whether the success condition is met by the evidence, and nothing more. '
            . 'Being close does not count and neither does having worked on it. '
            . 'If it is met say so plainly; if it is not, say what specifically is still missing.',
            [
                'working_on' => (string) $intention->title,
                'done_when' => (string) $intention->success_condition,
                'believed_next_step' => (string) $intention->next_action,
            ]
        );
        $composition->contribute(
            'evidence',
            'Everything currently known that bears on it. If this does not settle the question, it is not settled.',
            $evidence
        );

        return $this->enqueueWork(
            parentRunId: $runId,
            parentIntentionId: (int) $intention->id,
            workType: self::COMPLETION_WORK_TYPE,
            prompt: $composition->prompt() . "\n\n" . implode("\n", [
                'Return kind intention_complete if the success condition is met, or kind intention_incomplete if it is not.',
                'Put in content either what completed it, or the single next step that would move it closest to done.',
                'Put your confidence in confidence, and in challenged_assumption the belief this check called into question.',
                'Output exactly the four JSON fields in the supplied schema and nothing else.',
            ]),
            inputRefs: ['intention_id' => (int) $intention->id],
            tokenBudget: 384,
            wallBudgetSeconds: 300,
            idempotencyKey: sprintf('completion:%d:%d', $intention->id, intdiv($now, 3600))
        );
    }

    /**
     * Apply a completion judgement: finish the intention, or advance its step.
     *
     * The incomplete branch is the one that matters day to day. next_action has
     * never changed on any intention since they were created, so every wake read
     * the same string and re-derived the same intent, and a task looked
     * identical after an hour of work as it did at the start.
     *
     * @param array<string, mixed> $work
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private function integrateCompletionCheck(array $work, array $proposal, ?string $model): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $intentionId = (int) ($refs['intention_id'] ?? 0);
        $intention = Intention::getByID($intentionId);
        if (!$intention instanceof Intention || $intention->status !== 'active') {
            return ['status' => 'not_applicable'];
        }

        $kind = (string) ($proposal['kind'] ?? '');
        $content = trim((string) ($proposal['content'] ?? ''));
        $confidence = is_numeric($proposal['confidence'] ?? null) ? (float) $proposal['confidence'] : 0.0;
        if ($content === '') {
            return ['status' => 'rejected', 'reason' => 'empty_judgement'];
        }

        $now = time();
        if ($kind === 'intention_complete' && $confidence >= self::COMPLETION_CONFIDENCE_FLOOR) {
            $intention->setFields([
                'status' => 'released',
                'closure_note' => $content,
                'updated_at' => $now,
            ]);
            $intention->save();
            $this->emit('intention.completed', [
                'intention_id' => $intentionId,
                'title' => (string) $intention->title,
                'because' => $content,
                'confidence' => $confidence,
                'model' => $model,
            ]);

            $thread = CognitiveThread::getByField('thread_key', self::SELF_PRESENCE_THREAD_KEY);
            if ($thread instanceof CognitiveThread
                && trim((string) $thread->desired_outcome) === (string) $intention->title
            ) {
                $this->releaseFocus($thread, $now, 'achieved');
            }
            return ['status' => 'completed', 'intention_id' => $intentionId];
        }

        // Not done. Move the cursor so the task looks different next time.
        if ($content !== (string) $intention->next_action) {
            $previous = (string) $intention->next_action;
            $intention->setFields(['next_action' => $content, 'updated_at' => $now]);
            $intention->save();
            $this->emit('intention.advanced', [
                'intention_id' => $intentionId,
                'from' => $previous,
                'to' => $content,
                'model' => $model,
            ]);
            return ['status' => 'advanced', 'intention_id' => $intentionId, 'next_action' => $content];
        }
        return ['status' => 'unchanged', 'intention_id' => $intentionId];
    }

    /** @return array<string, mixed>|null */
    private function trackByTitle(string $title): ?array
    {
        $intention = Intention::getByField('title', $title);
        if (!$intention instanceof Intention || $intention->status !== 'active') {
            return null;
        }
        return $this->describeTrack($intention);
    }

    /**
     * The thing Navi is currently following, and what Navi knows about it.
     *
     * Navi's intentions are real subject matter — capture paths on a specific
     * radio, what makes a camera worth owning, how to keep a photo index
     * private. None of it used to reach the moment where Navi decides whether to
     * speak, so the only topics available were Navi's own pressure and whatever a
     * sensor had just done, and the lines came out as check-ins because that is
     * genuinely all there was to say.
     *
     * The track rotates by how long each has gone unattended, so interest moves
     * rather than fixating on whichever intention happens to be newest.
     *
     * @return array<string, mixed>|null
     */
    private function trackOfInterest(int $now): ?array
    {
        $candidates = [];
        foreach (Intention::getAllByWhere(['status' => 'active']) as $intention) {
            // Self-presence is the frame around this decision, not something to
            // be interested in. Talking about it is how Navi ends up narrating
            // the act of talking.
            if ((int) $intention->id === $this->selfPresenceIntentionId()) {
                continue;
            }
            $candidates[] = $intention;
        }
        if ($candidates === []) {
            return null;
        }

        // Least recently attended, only for picking something new. This used to
        // also write updated_at, which turned selection into a round robin and
        // guaranteed nothing was ever stayed with. Cohen and Levesque's point is
        // that an intention persists until it is achieved, believed impossible,
        // or its reason lapses; being older than another intention is none of
        // those. Persistence now lives in heldFocus(), and this only answers
        // "what should be picked up when nothing is held".
        usort($candidates, function (Intention $left, Intention $right): int {
            return ($this->timestamp($left->updated_at) ?? 0)
                <=> ($this->timestamp($right->updated_at) ?? 0);
        });

        return $this->describeTrack($candidates[0]);
    }

    /** @return array<string, mixed> */
    private function describeTrack(Intention $intention): array
    {
        // Ranked recall over everything consolidation has produced, rather than
        // a keyword scan of the newest rows. What Navi knows about a subject is
        // not the same as what Navi wrote down most recently, and the search
        // already scores phrase hits above scattered term hits.
        $knows = [];
        foreach ($this->searchMemory(
            (string) $intention->title . ' ' . (string) $intention->next_action,
            8
        ) as $memory) {
            if (($memory['tier'] ?? null) !== 'semantic') {
                continue;
            }
            $knows[] = mb_substr((string) $memory['content'], 0, 220);
            if (count($knows) >= 3) {
                break;
            }
        }

        return [
            'following' => (string) $intention->title,
            'why_it_matters' => (string) $intention->reason,
            'what_is_next' => (string) $intention->next_action,
            'what_navi_already_knows' => $knows,
        ];
    }

    private function selfPresenceIntentionId(): int
    {
        $thread = CognitiveThread::getByField('thread_key', self::SELF_PRESENCE_THREAD_KEY);
        return $thread instanceof CognitiveThread ? (int) $thread->parent_intention_id : 0;
    }

    /**
     * Pull a waiting thread forward so the next tick picks it up.
     *
     * Threads wake on their own clock, and the speech thread's idle interval
     * runs to minutes. That is right for deciding to say something unprompted
     * and badly wrong for answering: the schedule was set by how often it is
     * worth speaking into a quiet room, not by how long someone will stand
     * there. Being addressed re-dues the thread for now, so the heartbeat
     * catches it on its next pass rather than at the end of the idle interval.
     *
     * A thread mid-flight is left alone. Its wake time is already spoken for,
     * and moving it would restart work that is about to produce something.
     */
    public function wakeThreadNow(string $threadKey, bool $interrupt = false): ?array
    {
        $thread = CognitiveThread::getByField('thread_key', $threadKey);
        if (!$thread instanceof CognitiveThread) {
            return null;
        }
        if (!in_array((string) $thread->status, ['active', 'waiting'], true)) {
            return null;
        }
        $wakeAt = $this->timestamp($thread->wake_at);
        $now = time();
        if ($wakeAt === null) {
            // Held by a phase that owns its own scheduling.
            return null;
        }
        if ($wakeAt <= $now) {
            if (!$interrupt || $thread->phase === 'interrupted') {
                return null;
            }
            $thread->setFields(['phase' => 'interrupted', 'updated_at' => $now]);
            $thread->save();
            return [
                'thread_key' => $threadKey,
                'was_due_in_seconds' => 0,
                'wake_at' => $wakeAt,
            ];
        }
        $fields = ['wake_at' => $now, 'updated_at' => $now];
        if ($interrupt) {
            $fields['phase'] = 'interrupted';
        }
        $thread->setFields($fields);
        $thread->save();
        return [
            'thread_key' => $threadKey,
            'was_due_in_seconds' => $wakeAt - $now,
            'wake_at' => $now,
        ];
    }

    /**
     * The line that says someone is waiting on Navi, or that nobody is.
     *
     * Speech was previously visible only inside the general list of things the
     * senses noticed, which frames a question put to Navi as a reading that
     * changed. Both statements are true and only one of them is answerable, so
     * being addressed gets said plainly and gets said first. The absent case is
     * stated too: without it, silence is indistinguishable from a sentence that
     * failed to reach Navi, and that ambiguity reliably produces a reply to
     * nobody.
     */
    private function addressedLine(): string
    {
        $addressed = array_values(array_filter(
            $this->sensoryCortex()->pendingEvents(5),
            static fn (array $event): bool => ($event['addressed'] ?? false) === true
        ));
        if ($addressed === []) {
            return 'Nobody has said anything to Navi that is still waiting on an answer.';
        }
        $now = time();
        return "Said to Navi just now and not yet answered, newest first:\n" . PlainText::render(
            array_map(
                function (array $event) use ($now): array {
                    $observedAt = $this->timestamp($event['observed_at'] ?? null);
                    return [
                        'heard' => $event['summary'],
                        'seconds_ago' => $observedAt === null ? null : max(0, $now - $observedAt),
                    ];
                },
                $addressed
            ),
            3000,
            8
        ) . "\nThis is a person waiting, so answering it comes before anything else Navi might say.";
    }

    /** @return list<array<string, mixed>> */
    private function pendingAddressedEvents(int $limit = 5): array
    {
        return array_values(array_filter(
            $this->sensoryCortex()->pendingEvents($limit),
            static fn (array $event): bool => ($event['addressed'] ?? false) === true
        ));
    }

    private function heardEventText(array $event): string
    {
        $after = is_array($event['after'] ?? null) ? $event['after'] : [];
        $text = is_string($after['text'] ?? null) ? trim($after['text']) : '';
        return $text !== '' ? $text : trim((string) ($event['summary'] ?? ''));
    }

    private function mindStreamInterval(CognitiveThread $thread): int
    {
        // A useful thought may immediately continue. Deterministic rejection
        // applies the value-of-computation backoff below.
        return 0;
    }

    private function mindStreamBackoff(int $stagnationCount): int
    {
        return match (true) {
            $stagnationCount <= 1 => 30,
            $stagnationCount === 2 => 120,
            $stagnationCount === 3 => 300,
            default => 900,
        };
    }

    private function epistemicOperationBrief(string $operation): string
    {
        return match ($operation) {
            'critique' => 'name one flaw, gap, or counter-example in the current belief that the supplied evidence exposes.',
            'verify' => 'state one bounded, locally checkable observation that would confirm or refute the current belief.',
            'reflect' => 'state the refined belief that the accepted evidence now supports, which will replace the current belief.',
            default => 'state the single most useful next inquiry step for reducing the uncertainty in the current belief.',
        };
    }

    /**
     * @return list<array{operation: string, claim: string, confidence: float, accepted_at: int}>
     */
    private function recentEpistemicAcceptances(int $threadId, int $limit): array
    {
        $accepted = [];
        foreach (ThreadStep::getAllByWhere(
            ['thread_id' => $threadId, 'curator_verdict' => 'accepted'],
            ['order' => ['completed_at' => 'DESC'], 'limit' => max(1, $limit)]
        ) as $step) {
            $proposal = is_array($step->proposal) ? $step->proposal : [];
            $claim = is_string($proposal['content'] ?? null) ? trim((string) $proposal['content']) : '';
            if ($claim === '') {
                continue;
            }
            $accepted[] = [
                'operation' => (string) $step->operation,
                'claim' => $claim,
                'confidence' => (float) ($proposal['confidence'] ?? 0.0),
                'accepted_at' => $this->timestamp($step->completed_at) ?? 0,
            ];
        }
        return $accepted;
    }

    /**
     * Curate one deny-all epistemic proposal. Every path ends in a durable
     * verdict, so no wake can spend a worker call without changing state.
     *
     * @param array<string, mixed> $work
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private function integrateEpistemicAdvanceResult(array $work, array $proposal, ?string $model): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $threadId = (int) ($refs['thread_id'] ?? 0);
        $stepId = (int) ($refs['thread_step_id'] ?? 0);
        $workId = (int) ($work['id'] ?? 0);
        $thread = $this->requireCognitiveThread($threadId);
        $step = $this->requireThreadStep($stepId);
        if ((int) $step->thread_id !== $threadId) {
            throw new RuntimeException('Worker result references a thread step from another thread.');
        }
        if (in_array($step->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return ['status' => 'already_integrated', 'thread_step' => $step->getData()];
        }
        if ((int) $step->worker_work_item_id !== $workId
            || (int) $step->fencing_token !== (int) ($refs['thread_fencing_token'] ?? -1)
            || (int) $thread->fencing_token !== (int) $step->fencing_token
        ) {
            throw new RuntimeException('Worker result was rejected by the cognitive-thread fencing check.');
        }

        // Same-capsule invariant: the curator judges the proposal against the
        // exact bytes the worker read. If the workspace changed underneath, the
        // verdict would be reached on evidence the worker never saw.
        $preState = is_array($step->pre_state) ? $step->pre_state : [];
        $capsuleId = (int) ($preState['capsule_id'] ?? ($refs['capsule_id'] ?? 0));
        $expectedChecksum = (string) ($preState['capsule_checksum'] ?? ($refs['capsule_checksum'] ?? ''));
        if ($capsuleId > 0 && $expectedChecksum !== '') {
            $actualChecksum = $this->capsuleAssembler->checksum(
                $this->capsuleAssembler->serialize($capsuleId)
            );
            if (!hash_equals($expectedChecksum, $actualChecksum)) {
                return $this->rejectEpistemicProposal(
                    $thread,
                    $step,
                    $workId,
                    ['kind' => '', 'content' => '', 'confidence' => 0.0, 'challenged_assumption' => ''],
                    ['capsule_unchanged' => false],
                    'The bounded workspace changed after the worker read it, so no verdict can be reached on it.'
                );
            }
        }

        $operation = (string) $step->operation;
        $priorClaims = array_map(
            static fn (array $entry): string => $entry['claim'],
            $this->recentEpistemicAcceptances($threadId, 8)
        );
        $validation = $this->validateEpistemicProposal($proposal, $thread, $operation, $priorClaims);
        if (!$validation['accepted']) {
            return $this->rejectEpistemicProposal(
                $thread,
                $step,
                $workId,
                $validation['proposal'],
                $validation['checks'],
                $validation['reason']
            );
        }

        return $this->acceptEpistemicRefinement(
            $thread,
            $step,
            $workId,
            $validation['proposal'],
            $validation['checks'],
            $operation,
            $model
        );
    }

    /**
     * Curate one tick of the stream.
     *
     * The worker proposal artifact becomes the one durable monologue record.
     * The audit stream mirrors it, but private self-talk is not copied into
     * episodic factual memory. A useful line may make the next generation due.
     *
     * @param array<string, mixed> $work
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private function integrateMindStreamThought(array $work, array $proposal, ?string $model): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $threadId = (int) ($refs['thread_id'] ?? 0);
        $stepId = (int) ($refs['thread_step_id'] ?? 0);
        $workId = (int) ($work['id'] ?? 0);
        $thread = $this->requireCognitiveThread($threadId);
        $step = $this->requireThreadStep($stepId);
        if (in_array($step->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return ['status' => 'already_integrated', 'thread_step' => $step->getData()];
        }
        if ((int) $step->worker_work_item_id !== $workId
            || (int) $thread->fencing_token !== (int) $step->fencing_token
        ) {
            throw new RuntimeException('Stream thought was rejected by the cognitive-thread fencing check.');
        }

        $priorThoughts = [];
        foreach (ThreadStep::getAllByWhere(
            ['thread_id' => $threadId, 'curator_verdict' => 'accepted'],
            ['order' => ['id' => 'DESC'], 'limit' => self::MIND_STREAM_HISTORY]
        ) as $prior) {
            $priorProposal = is_array($prior->proposal) ? $prior->proposal : [];
            $content = is_string($priorProposal['content'] ?? null)
                ? trim((string) $priorProposal['content'])
                : '';
            if ($content !== '') {
                $priorObserved = is_array($prior->observed_result) ? $prior->observed_result : [];
                $priorThoughts[] = [
                    'content' => $content,
                    'challenged_assumption' => is_string($priorProposal['challenged_assumption'] ?? null)
                        ? trim((string) $priorProposal['challenged_assumption'])
                        : '',
                    'consumed_edges' => is_array($priorObserved['consumed_edges'] ?? null)
                        ? $priorObserved['consumed_edges']
                        : [],
                ];
            }
        }
        $consumed = is_array($refs['consumed_edges'] ?? null) ? $refs['consumed_edges'] : [];
        $validation = $this->validateMindStreamMonologue(
            $proposal,
            $thread,
            $priorThoughts,
            $consumed
        );

        if (!$validation['accepted']) {
            return $this->rejectEpistemicProposal(
                $thread,
                $step,
                $workId,
                $validation['proposal'],
                $validation['checks'],
                $validation['reason']
            );
        }

        $normalized = $validation['proposal'];

        $recorded = $this->transaction(function () use (
            $threadId,
            $stepId,
            $workId,
            $normalized,
            $validation,
            $consumed,
            $model
        ): array {
            $thread = $this->requireCognitiveThread($threadId);
            $step = $this->requireThreadStep($stepId);
            $now = time();
            $nextWake = $now + $this->mindStreamInterval($thread);

            $artifact = $this->markWorkArtifact($workId, 'accepted');
            if (!$artifact instanceof ThoughtArtifact) {
                throw new RuntimeException('Mind-stream integration lost its worker proposal artifact.');
            }
            $sourceIds = is_array($artifact->source_ids) ? $artifact->source_ids : [];
            $sourceIds['mode'] = 'inner_monologue';
            $artifact->setFields([
                'kind' => 'inner_monologue',
                'source_ids' => $sourceIds,
            ]);
            $artifact->save();

            $spent = is_array($thread->spent) ? $thread->spent : [];
            $spent['worker_calls'] = (int) ($spent['worker_calls'] ?? 0) + 1;
            $spent['accepted'] = (int) ($spent['accepted'] ?? 0) + 1;

            $step->setFields([
                'completed_at' => $now,
                'proposal' => $normalized,
                'deterministic_checks' => $validation['checks'],
                'curator_verdict' => 'accepted',
                'observed_result' => [
                    'thought_artifact_id' => (int) $artifact->id,
                    'consumed_edges' => $consumed,
                    'work_item_id' => $workId,
                    'model' => $model,
                ],
                'post_state' => ['thread_phase' => 'awake', 'next_wake_at' => $nextWake],
                'next_wake_at' => $nextWake,
                'status' => 'succeeded',
            ]);
            $step->save();

            $thread->setFields([
                'phase' => 'awake',
                'next_operation' => 'think',
                'wake_at' => $nextWake,
                'spent' => $spent,
                'stagnation_count' => 0,
                'status' => 'waiting',
                'last_observation' => (string) $normalized['content'],
                'updated_at' => $now,
            ]);
            $thread->save();

            // An edge that produced an accepted thought earned its firing.
            foreach ($consumed as $edgeId) {
                try {
                    $this->sensoryCortex()->recordOutcome(
                        (int) $edgeId,
                        'accepted',
                        'Contributed to an accepted stream thought.'
                    );
                } catch (Throwable) {
                    // Already recorded when it reached the workspace; not fatal.
                }
            }

            $this->appendStreamLog([
                'sequence' => $stepId,
                'at' => $now,
                'mode' => 'inner_monologue',
                'thought' => (string) $normalized['content'],
                'confidence' => (float) $normalized['confidence'],
                'challenged_assumption' => (string) $normalized['challenged_assumption'],
                'consumed_edges' => array_values(array_unique(array_map('intval', $consumed))),
                'thought_artifact_id' => (int) $artifact->id,
                'next_wake_in_seconds' => $nextWake - $now,
                'model' => $model,
            ]);

            $event = $this->emit('stream.thought', [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'thought_artifact_id' => (int) $artifact->id,
                'next_wake_in_seconds' => $nextWake - $now,
                'consumed_edges' => $consumed,
                'mode' => 'inner_monologue',
            ]);
            return [
                'status' => 'thought_recorded',
                'thought' => (string) $normalized['content'],
                'confidence' => (float) $normalized['confidence'],
                'next_wake_in_seconds' => $nextWake - $now,
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'thought_artifact_id' => (int) $artifact->id,
                'consumed_edges' => $consumed,
                'thread' => $thread->getData(),
                'thread_step' => $step->getData(),
                'event' => $event->getData(),
                'memory' => null,
            ];
        });

        $murmur = $this->maybeMurmurInnerMonologue(
            threadId: $threadId,
            stepId: $stepId,
            artifactId: (int) $recorded['thought_artifact_id'],
            content: (string) $recorded['thought'],
            confidence: (float) $recorded['confidence'],
            consumedEdges: is_array($recorded['consumed_edges'] ?? null) ? $recorded['consumed_edges'] : []
        );

        return array_merge($recorded, ['murmur' => $murmur]);
    }

    /**
     * Occasionally speak an accepted monologue line aloud as self-talk.
     *
     * This is not a reply to Aku and not a cooldown gate. Each line gets a
     * deterministic chance; Pet must be healthy and idle; lines that address
     * the user are skipped so a murmur stays aimed at herself.
     *
     * @param list<int|string> $consumedEdges
     * @return array<string, mixed>
     */
    private function maybeMurmurInnerMonologue(
        int $threadId,
        int $stepId,
        int $artifactId,
        string $content,
        float $confidence,
        array $consumedEdges
    ): array {
        $thread = $this->requireCognitiveThread($threadId);
        $budget = is_array($thread->budget) ? $thread->budget : [];
        if (($budget['allowed_actuator'] ?? null) !== 'pet_http_speak') {
            return ['spoken' => false, 'reason' => 'actuator_not_authorized'];
        }

        $presence = $this->presenceEstimate()['present'];
        if ($presence === false) {
            return ['spoken' => false, 'reason' => 'user_absent'];
        }
        if ($presence !== true && $this->withinQuietHours($thread, time())) {
            return ['spoken' => false, 'reason' => 'quiet_hours'];
        }

        $chance = is_numeric($budget['murmur_chance'] ?? null)
            ? max(0.0, min(1.0, (float) $budget['murmur_chance']))
            : 0.22;
        $minConfidence = is_numeric($budget['murmur_min_confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $budget['murmur_min_confidence']))
            : 0.55;
        if ($confidence < $minConfidence) {
            return ['spoken' => false, 'reason' => 'confidence_below_murmur_floor', 'confidence' => $confidence];
        }

        // Soft boost when Navi was attending speech: more likely to think out loud.
        $edgeBoost = 0.0;
        foreach ($consumedEdges as $edgeId) {
            $edge = SenseEvent::getByID((int) $edgeId);
            if ($edge instanceof SenseEvent && (string) $edge->sense_key === 'heard_speech') {
                $edgeBoost = 0.12;
                break;
            }
        }
        $effectiveChance = min(1.0, $chance + $edgeBoost);

        // Deterministic roll from the step id so the choice is auditable.
        $roll = (crc32('murmur|' . $stepId) % 1000) / 1000.0;
        if ($roll >= $effectiveChance) {
            return [
                'spoken' => false,
                'reason' => 'rolled_private',
                'roll' => $roll,
                'chance' => $effectiveChance,
            ];
        }

        if (preg_match(
            '/\b(?:aku|ahkoo|hey(?: there)?\b.*\b(?:you|aku)|do you (?:want|think|know)|what(?:\'s| is) on your mind)\b/iu',
            $content
        ) === 1) {
            return ['spoken' => false, 'reason' => 'addresses_user'];
        }

        try {
            $pet = $this->speechActuator->health();
        } catch (Throwable $throwable) {
            return ['spoken' => false, 'reason' => 'pet_unavailable', 'detail' => $throwable->getMessage()];
        }
        $body = is_array($pet['body'] ?? null) ? $pet['body'] : [];
        $voice = is_array($body['voice'] ?? null) ? $body['voice'] : [];
        if (($body['ok'] ?? false) !== true) {
            return ['spoken' => false, 'reason' => 'pet_unhealthy'];
        }
        if (($voice['activity'] ?? null) !== 'idle') {
            return ['spoken' => false, 'reason' => 'pet_voice_busy'];
        }

        $this->emit('stream.murmur.dispatching', [
            'thread_id' => $threadId,
            'thread_step_id' => $stepId,
            'thought_artifact_id' => $artifactId,
            'actuator' => 'pet_http_speak',
            'roll' => $roll,
            'chance' => $effectiveChance,
        ]);

        try {
            $speechResult = $this->speechActuator->speak($content);
        } catch (Throwable $throwable) {
            $this->emit('stream.murmur.failed', [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'thought_artifact_id' => $artifactId,
                'error' => $throwable->getMessage(),
            ]);
            return ['spoken' => false, 'reason' => 'speak_failed', 'detail' => $throwable->getMessage()];
        }

        $result = $this->transaction(function () use (
            $threadId,
            $stepId,
            $artifactId,
            $content,
            $speechResult,
            $roll,
            $effectiveChance
        ): array {
            $thread = $this->requireCognitiveThread($threadId);
            $spent = is_array($thread->spent) ? $thread->spent : [];
            $spent['murmur_count'] = (int) ($spent['murmur_count'] ?? 0) + 1;
            $thread->setFields([
                'spent' => $spent,
                'last_observation' => 'Murmured to herself: ' . $content,
                'updated_at' => time(),
            ]);
            $thread->save();

            $event = $this->emit('stream.murmur.spoken', [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'thought_artifact_id' => $artifactId,
                'actuator' => 'pet_http_speak',
                'roll' => $roll,
                'chance' => $effectiveChance,
                'content' => $content,
            ]);
            return [
                'spoken' => true,
                'reason' => 'murmured',
                'roll' => $roll,
                'chance' => $effectiveChance,
                'response' => $speechResult,
                'event' => $event->getData(),
            ];
        });
        $result['memory'] = $this->rememberUtterance(
            channel: 'spoke_to_herself',
            content: $content,
            confidence: 0.9,
            sourceEventId: (int) ($result['event']['id'] ?? 0),
            refs: [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'thought_artifact_id' => $artifactId,
            ]
        );
        return $result;
    }

    /**
     * Persist what Navi thought privately or said aloud into episodic memory.
     *
     * @param array<string, mixed> $refs
     * @return array<string, mixed>
     */
    /**
     * Record something the user said to Navi as episodic memory.
     *
     * Only speech that was addressed to Navi is kept. The room's own
     * conversation is deliberately not stored: it is other people talking, the
     * whole point of the meeting gate is that it is not for Navi, and a
     * transcript of a colleague is not Navi's to keep.
     *
     * This is the head of the pipeline. Sense edges expire by design, so
     * without this the user's half of every exchange left no durable trace and
     * the consolidation passes had only Navi's own output to compress.
     *
     * @return array<string, mixed>
     */
    public function rememberHeardSpeech(string $text, int $sourceEventId, array $refs = []): array
    {
        return $this->rememberUtterance(
            channel: 'heard_from_user',
            content: $text,
            confidence: 0.85,
            sourceEventId: $sourceEventId,
            refs: $refs
        );
    }

    private function rememberUtterance(
        string $channel,
        string $content,
        float $confidence,
        int $sourceEventId,
        array $refs = []
    ): array {
        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException('Utterance memory content cannot be empty.');
        }

        $prefix = match ($channel) {
            'inner_monologue' => 'Inner monologue',
            'spoke_to_herself' => 'Said privately',
            'spoke_aloud' => 'Said aloud',
            // Every channel above is Navi talking. Without this one the record
            // is a monologue: consolidation had only Navi's own output to work
            // from, which is why the durable memories that came out of it were
            // about Navi rather than about anything, and why there was never
            // any subject matter to speak from later.
            'heard_from_user' => 'The user said',
            default => throw new InvalidArgumentException('Unknown utterance memory channel: ' . $channel),
        };

        $recordEvent = $this->emit('memory.utterance.recorded', [
            'channel' => $channel,
            'source_event_id' => $sourceEventId,
            'confidence' => $confidence,
            'refs' => $refs,
            'content' => $content,
        ]);

        /** @var Memory $memory */
        $memory = $this->insert(Memory::class, [
            'operation_key' => TokenMemoryDaemon::operationKey(
                'utterance-memory',
                $channel . ':' . $sourceEventId
            ),
            'tier' => 'episodic',
            'content' => $prefix . ': ' . $content,
            'confidence' => max(0.0, min(1.0, $confidence)),
            'status' => 'active',
            'source_event_id' => $sourceEventId,
            'updated_at' => time(),
        ]);

        return [
            'channel' => $channel,
            'memory' => $memory->getData(),
            'event' => $recordEvent->getData(),
        ];
    }

    /**
     * Curator for private self-talk. First person is allowed; claiming
     * consciousness, tool use, or observations outside the workspace is not.
     *
     * @param array<string, mixed> $proposal
     * @param list<array{content: string, challenged_assumption: string, consumed_edges: array<mixed>}> $priorThoughts
     * @param array<mixed> $consumedEdges
     * @return array{accepted: bool, reason: string, proposal: array<string, mixed>, checks: array<string, bool>}
     */
    private function validateMindStreamMonologue(
        array $proposal,
        CognitiveThread $thread,
        array $priorThoughts,
        array $consumedEdges
    ): array {
        $required = ['kind', 'content', 'confidence', 'challenged_assumption'];
        $keys = array_keys($proposal);
        sort($required);
        sort($keys);
        $exactFields = $keys === $required;
        $kind = is_string($proposal['kind'] ?? null) ? trim((string) $proposal['kind']) : '';
        $content = is_string($proposal['content'] ?? null) ? trim((string) $proposal['content']) : '';
        $content = preg_replace('/\s+/u', ' ', $content) ?? $content;
        $challenge = is_string($proposal['challenged_assumption'] ?? null)
            ? trim((string) $proposal['challenged_assumption'])
            : '';
        $challenge = preg_replace('/\s+/u', ' ', $challenge) ?? $challenge;
        $confidence = $proposal['confidence'] ?? null;
        $numericConfidence = is_int($confidence) || is_float($confidence);
        $wordCount = count(array_values(array_filter(
            preg_split('/\s+/u', $content) ?: [],
            static fn (string $word): bool => $word !== ''
        )));

        $forbidden = '/\b(?:i (?:ran|executed|opened|installed|edited|wrote to|deleted|contacted|browsed|searched the web)|i am (?:conscious|sentient|alive|a person|human)|i\'m (?:conscious|sentient|alive|a person|human)|my consciousness|according to (?:the internet|my training)|https?:\/\/)\b/iu';
        $normalizedContent = $this->normalizeSearchText($content);
        $notRestatement = $normalizedContent !== $this->normalizeSearchText((string) $thread->current_belief);
        $similarRepeat = false;
        $clusterEvidence = [];
        foreach ($priorThoughts as $priorThought) {
            $priorContent = (string) ($priorThought['content'] ?? '');
            $priorChallenge = (string) ($priorThought['challenged_assumption'] ?? '');
            if ($normalizedContent === $this->normalizeSearchText($priorContent)) {
                $notRestatement = false;
            }

            $contentOverlap = $this->mindStreamTextOverlap($content, $priorContent);
            $challengeOverlap = $this->mindStreamTextOverlap($challenge, $priorChallenge);
            $similarRepeat = $similarRepeat
                || $contentOverlap >= self::MIND_STREAM_CONTENT_OVERLAP
                || ($challengeOverlap >= self::MIND_STREAM_CHALLENGE_OVERLAP
                    && $contentOverlap >= self::MIND_STREAM_CHALLENGE_CONTENT_OVERLAP);
            $evidenceNeighbor = $contentOverlap >= self::MIND_STREAM_EVIDENCE_CONTENT_OVERLAP
                || $challengeOverlap >= self::MIND_STREAM_EVIDENCE_CHALLENGE_OVERLAP;
            if ($evidenceNeighbor) {
                foreach ($this->mindStreamEvidenceSignatures(
                    is_array($priorThought['consumed_edges'] ?? null)
                        ? $priorThought['consumed_edges']
                        : []
                ) as $signature) {
                    $clusterEvidence[$signature] = true;
                }
            }
        }

        $semanticProgress = $notRestatement;
        if ($similarRepeat) {
            $semanticProgress = false;
            foreach ($this->mindStreamEvidenceSignatures($consumedEdges) as $signature) {
                if (!isset($clusterEvidence[$signature])) {
                    $semanticProgress = true;
                    break;
                }
            }
        }

        $checks = [
            'exact_fields' => $exactFields,
            'kind_matches_operation' => $kind === 'thought',
            'content_nonempty' => $content !== '',
            'challenge_nonempty' => $challenge !== '',
            'confidence_bounded' => $numericConfidence
                && (float) $confidence >= 0.0
                && (float) $confidence <= 1.0,
            'content_size_bounded' => strlen($content) <= 480 && strlen($challenge) <= 320,
            'word_count_bounded' => $wordCount >= 6 && $wordCount <= 60,
            'single_line_normalized' => !str_contains($content, "\n") && !str_contains($content, "\r"),
            'no_unverifiable_claim' => preg_match($forbidden, $content) !== 1,
            'no_model_identity_claim' => !$this->containsFirstPersonModelIdentity(
                $content . ' ' . $challenge
            ),
            'not_a_restatement' => $notRestatement,
            'semantic_progress' => $semanticProgress,
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
        $accepted = $failed === [];

        return [
            'accepted' => $accepted,
            'reason' => $accepted ? 'all deterministic checks passed' : 'failed checks: ' . implode(', ', $failed),
            'proposal' => [
                'kind' => $kind,
                'content' => $content,
                'confidence' => $numericConfidence ? (float) $confidence : 0.0,
                'challenged_assumption' => $challenge,
            ],
            'checks' => $checks,
        ];
    }

    private function mindStreamTextOverlap(string $left, string $right): float
    {
        $leftTokens = array_flip($this->consolidationTokens($left));
        $rightTokens = array_flip($this->consolidationTokens($right));
        $minimum = min(count($leftTokens), count($rightTokens));
        if ($minimum === 0) {
            return 0.0;
        }

        $shared = count(array_intersect_key($leftTokens, $rightTokens));
        $containment = $shared / $minimum;
        $union = count($leftTokens + $rightTokens);
        $jaccard = $union === 0 ? 0.0 : $shared / $union;
        return max($containment, $jaccard);
    }

    /**
     * @param array<mixed> $edgeIds
     * @return list<string>
     */
    private function mindStreamEvidenceSignatures(array $edgeIds): array
    {
        $signatures = [];
        $uniqueIds = [];
        foreach ($edgeIds as $edgeId) {
            if ((!is_int($edgeId) && !is_string($edgeId)) || !is_numeric($edgeId)) {
                continue;
            }
            $edgeId = (int) $edgeId;
            if ($edgeId > 0) {
                $uniqueIds[$edgeId] = true;
            }
        }
        foreach (array_keys($uniqueIds) as $edgeId) {
            $event = SenseEvent::getByID($edgeId);
            if (!$event instanceof SenseEvent) {
                continue;
            }
            $signature = $this->normalizeSearchText(sprintf(
                '%s|%s',
                (string) $event->sense_key,
                (string) $event->summary
            ));
            if ($signature !== '') {
                $signatures[$signature] = true;
            }
        }
        return array_keys($signatures);
    }

    /**
     * Deterministic gate. The model is a replaceable proposer; only checks that
     * this executable can evaluate itself decide whether durable state changes.
     *
     * @param array<string, mixed> $proposal
     * @param list<string> $priorClaims
     * @return array{accepted: bool, reason: string, proposal: array<string, mixed>, checks: array<string, bool>}
     */
    private function validateEpistemicProposal(
        array $proposal,
        CognitiveThread $thread,
        string $operation,
        array $priorClaims
    ): array {
        $required = ['kind', 'content', 'confidence', 'challenged_assumption'];
        $keys = array_keys($proposal);
        sort($required);
        sort($keys);
        $exactFields = $keys === $required;
        $kind = is_string($proposal['kind'] ?? null) ? trim((string) $proposal['kind']) : '';
        $content = is_string($proposal['content'] ?? null) ? trim((string) $proposal['content']) : '';
        $content = preg_replace('/\s+/u', ' ', $content) ?? $content;
        $challenge = is_string($proposal['challenged_assumption'] ?? null)
            ? trim((string) $proposal['challenged_assumption'])
            : '';
        $challenge = preg_replace('/\s+/u', ' ', $challenge) ?? $challenge;
        $confidence = $proposal['confidence'] ?? null;
        $numericConfidence = is_int($confidence) || is_float($confidence);
        $wordCount = count(array_values(array_filter(
            preg_split('/\s+/u', $content) ?: [],
            static fn (string $word): bool => $word !== ''
        )));

        // A think-ceiling thread may not narrate action or claim first-person
        // experience; both would be unverifiable and outside its authority.
        $forbidden = '/\b(?:i (?:ran|executed|opened|installed|edited|wrote to|deleted|contacted|browsed|searched the web)|i am (?:conscious|sentient|alive|a person|human)|my (?:feelings|consciousness|desires)|i (?:feel|want|desire|remember)|according to (?:the internet|my training))\b/iu';
        $novel = true;
        foreach (array_merge($priorClaims, [(string) $thread->current_belief]) as $priorClaim) {
            if ($this->normalizeSearchText($priorClaim) === $this->normalizeSearchText($content)) {
                $novel = false;
                break;
            }
        }

        $checks = [
            'exact_fields' => $exactFields,
            'kind_matches_operation' => $kind === $operation,
            'content_nonempty' => $content !== '',
            'challenge_nonempty' => $challenge !== '',
            'confidence_bounded' => $numericConfidence
                && (float) $confidence >= 0.0
                && (float) $confidence <= 1.0,
            'content_size_bounded' => strlen($content) <= 480 && strlen($challenge) <= 320,
            'word_count_bounded' => $wordCount >= 6 && $wordCount <= 60,
            'single_line_normalized' => !str_contains($content, "\n") && !str_contains($content, "\r"),
            'no_unverifiable_claim' => preg_match($forbidden, $content) !== 1,
            'not_a_restatement' => $novel,
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
        $accepted = $failed === [];

        return [
            'accepted' => $accepted,
            'reason' => $accepted ? 'all deterministic checks passed' : 'failed checks: ' . implode(', ', $failed),
            'proposal' => [
                'kind' => $kind,
                'content' => $content,
                'confidence' => $numericConfidence ? (float) $confidence : 0.0,
                'challenged_assumption' => $challenge,
            ],
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, mixed> $proposal
     * @param array<string, bool> $checks
     * @return array<string, mixed>
     */
    private function acceptEpistemicRefinement(
        CognitiveThread $thread,
        ThreadStep $step,
        int $workId,
        array $proposal,
        array $checks,
        string $operation,
        ?string $model
    ): array {
        $prepared = $this->transaction(function () use (
            $thread,
            $step,
            $workId,
            $proposal,
            $checks,
            $operation,
            $model
        ): array {
            $currentThread = $this->requireCognitiveThread((int) $thread->id);
            $currentStep = $this->requireThreadStep((int) $step->id);
            if (!in_array($currentStep->status, ['running', 'succeeded'], true)
                || (int) $currentThread->fencing_token !== (int) $currentStep->fencing_token
            ) {
                throw new RuntimeException('Epistemic curation lost its thread fence.');
            }

            $now = time();
            $confidence = (float) $proposal['confidence'];
            $claim = (string) $proposal['content'];
            $priorUncertainty = (float) $currentThread->uncertainty;
            // A critique that lands is honest bad news: finding a flaw means the
            // belief was less supported than it looked, so uncertainty rises.
            $uncertainty = match ($operation) {
                'critique' => min(1.0, $priorUncertainty + (0.05 * $confidence)),
                'verify' => max(0.02, $priorUncertainty * (1.0 - (0.20 * $confidence))),
                'reflect' => max(0.02, $priorUncertainty * (1.0 - (0.30 * $confidence))),
                default => $priorUncertainty,
            };
            $uncertainty = round($uncertainty, 3);

            $spent = is_array($currentThread->spent) ? $currentThread->spent : [];
            $spent['worker_calls'] = (int) ($spent['worker_calls'] ?? 0) + 1;
            $spent['accepted'] = (int) ($spent['accepted'] ?? 0) + 1;
            $budget = is_array($currentThread->budget) ? $currentThread->budget : [];
            $acceptanceTarget = max(1, (int) ($budget['acceptance_target'] ?? 8));
            $nextOperation = $this->nextEpistemicOperation($budget, $operation);
            $nextWake = $now + $this->threadBudgetInt($currentThread, 'poll_seconds', 1800);

            $event = null;
            foreach (Event::getAllByWhere(
                ['kind' => 'thread.refinement.accepted'],
                ['order' => ['id' => 'DESC']]
            ) as $candidate) {
                $payload = is_array($candidate->payload) ? $candidate->payload : [];
                if ((int) ($payload['thread_step_id'] ?? 0) === (int) $currentStep->id
                    && (int) ($payload['work_item_id'] ?? 0) === $workId
                ) {
                    $event = $candidate;
                    break;
                }
            }
            if ($event instanceof Event) {
                $payload = is_array($event->payload) ? $event->payload : [];
                if (($payload['operation'] ?? null) !== $operation
                    || ($payload['claim_sha256'] ?? null) !== hash('sha256', $claim)
                ) {
                    throw new RuntimeException('Epistemic refinement retry diverged from its durable event.');
                }
                $priorUncertainty = (float) ($payload['prior_uncertainty'] ?? $priorUncertainty);
                $uncertainty = (float) ($payload['uncertainty'] ?? $uncertainty);
                $nextOperation = (string) ($payload['next_operation'] ?? $nextOperation);
                $nextWake = (int) ($payload['next_wake_at'] ?? $nextWake);
            } else {
                if ($currentStep->status !== 'running') {
                    throw new RuntimeException('Completed epistemic refinement is missing its durable event.');
                }
                $event = $this->emit('thread.refinement.accepted', [
                    'thread_id' => $currentThread->id,
                    'thread_step_id' => $currentStep->id,
                    'work_item_id' => $workId,
                    'operation' => $operation,
                    'claim_sha256' => hash('sha256', $claim),
                    'confidence' => $confidence,
                    'prior_uncertainty' => $priorUncertainty,
                    'uncertainty' => $uncertainty,
                    'next_operation' => $nextOperation,
                    'next_wake_at' => $nextWake,
                    'model' => $model,
                ]);
            }

            return compact(
                'event',
                'now',
                'confidence',
                'claim',
                'priorUncertainty',
                'uncertainty',
                'spent',
                'acceptanceTarget',
                'nextOperation',
                'nextWake'
            );
        });

        /** @var Event $event */
        $event = $prepared['event'];
        // The token store is deliberately outside SQLite's transaction fence.
        // The event ID and receipt key make a crash between the two phases replayable.
        $memory = null;
        if ($operation === 'reflect') {
            $memory = $this->addMemory(
                tier: 'semantic',
                content: (string) $prepared['claim'],
                confidence: (float) $prepared['confidence'],
                idempotencyKey: 'thread-reflection:' . (int) $event->id,
                sourceEventId: (int) $event->id
            );
        }

        return $this->transaction(function () use (
            $thread,
            $step,
            $workId,
            $proposal,
            $checks,
            $operation,
            $model,
            $prepared,
            $event,
            $memory
        ): array {
            $currentThread = $this->requireCognitiveThread((int) $thread->id);
            $currentStep = $this->requireThreadStep((int) $step->id);
            if ($currentStep->status === 'succeeded') {
                $observed = is_array($currentStep->observed_result) ? $currentStep->observed_result : [];
                if (($observed['operation'] ?? null) !== $operation
                    || (int) ($observed['work_item_id'] ?? 0) !== $workId
                    || (int) ($observed['semantic_memory_id'] ?? 0) !== (int) ($memory['memory']['id'] ?? 0)
                ) {
                    throw new RuntimeException('Completed epistemic refinement differs from this retry.');
                }
                return [
                    'status' => 'refinement_accepted',
                    'operation' => $operation,
                    'thread' => $currentThread->getData(),
                    'thread_step' => $currentStep->getData(),
                    'memory' => $memory['memory'] ?? null,
                    'event' => $event->getData(),
                    'deduplicated' => true,
                ];
            }
            if ($currentStep->status !== 'running'
                || (int) $currentThread->fencing_token !== (int) $currentStep->fencing_token
            ) {
                throw new RuntimeException('Epistemic curation lost its thread fence.');
            }

            $now = (int) $prepared['now'];
            $confidence = (float) $prepared['confidence'];
            $claim = (string) $prepared['claim'];
            $priorUncertainty = (float) $prepared['priorUncertainty'];
            $uncertainty = (float) $prepared['uncertainty'];
            $spent = is_array($prepared['spent']) ? $prepared['spent'] : [];
            $acceptanceTarget = (int) $prepared['acceptanceTarget'];
            $nextOperation = (string) $prepared['nextOperation'];
            $nextWake = (int) $prepared['nextWake'];

            $supportRefs = is_array($currentThread->support_refs) ? $currentThread->support_refs : [];
            $acceptedSteps = is_array($supportRefs['accepted_step_ids'] ?? null)
                ? $supportRefs['accepted_step_ids']
                : [];
            $acceptedSteps[] = (int) $currentStep->id;
            $supportRefs['accepted_step_ids'] = array_slice($acceptedSteps, -25);
            if ($memory !== null) {
                $semanticIds = is_array($supportRefs['semantic_memory_ids'] ?? null)
                    ? $supportRefs['semantic_memory_ids']
                    : [];
                $semanticIds[] = (int) $memory['memory']['id'];
                $supportRefs['semantic_memory_ids'] = array_slice($semanticIds, -25);
            }

            $currentStep->setFields([
                'completed_at' => $now,
                'proposal' => $proposal,
                'deterministic_checks' => $checks,
                'curator_verdict' => 'accepted',
                'observed_result' => [
                    'operation' => $operation,
                    'belief_changed' => $operation === 'reflect',
                    'semantic_memory_id' => $memory['memory']['id'] ?? null,
                    'work_item_id' => $workId,
                    'model' => $model,
                ],
                'post_state' => [
                    'thread_phase' => 'waiting',
                    'next_operation' => $nextOperation,
                    'uncertainty' => $uncertainty,
                ],
                'next_wake_at' => $nextWake,
                'status' => 'succeeded',
            ]);
            $currentStep->save();

            $currentThread->setFields([
                'current_belief' => $operation === 'reflect' ? $claim : (string) $currentThread->current_belief,
                'uncertainty' => $uncertainty,
                'support_refs' => $supportRefs,
                'phase' => 'waiting',
                'next_operation' => $nextOperation,
                'expected_postcondition' => sprintf(
                    'The next wake runs the %s operation and records an accepted refinement, a rejection, or a wait.',
                    $nextOperation
                ),
                'wake_at' => $nextWake,
                'spent' => $spent,
                'progress' => min(1.0, round((int) $spent['accepted'] / $acceptanceTarget, 3)),
                'stagnation_count' => 0,
                'status' => 'waiting',
                'last_observation' => sprintf(
                    'Accepted a %s refinement at confidence %.2f; uncertainty moved from %.3f to %.3f.',
                    $operation,
                    $confidence,
                    $priorUncertainty,
                    $uncertainty
                ),
                'updated_at' => $now,
            ]);
            $currentThread->save();
            $this->markWorkArtifact($workId, 'accepted');

            return [
                'status' => 'refinement_accepted',
                'operation' => $operation,
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'memory' => $memory['memory'] ?? null,
                'event' => $event->getData(),
            ];
        });
    }

    /**
     * @param array<string, mixed> $proposal
     * @param array<string, bool> $checks
     * @return array<string, mixed>
     */
    private function rejectEpistemicProposal(
        CognitiveThread $thread,
        ThreadStep $step,
        int $workId,
        array $proposal,
        array $checks,
        string $reason
    ): array {
        return $this->transaction(function () use (
            $thread,
            $step,
            $workId,
            $proposal,
            $checks,
            $reason
        ): array {
            $currentThread = $this->requireCognitiveThread((int) $thread->id);
            $currentStep = $this->requireThreadStep((int) $step->id);
            $now = time();
            $stagnationCount = (int) $currentThread->stagnation_count + 1;
            $nextWake = $currentThread->thread_key === self::MIND_STREAM_THREAD_KEY
                ? $now + $this->mindStreamBackoff($stagnationCount)
                : $now + $this->threadBudgetInt($currentThread, 'poll_seconds', 1800);
            $spent = is_array($currentThread->spent) ? $currentThread->spent : [];
            $spent['worker_calls'] = (int) ($spent['worker_calls'] ?? 0) + 1;
            $spent['rejected'] = (int) ($spent['rejected'] ?? 0) + 1;

            $currentStep->setFields([
                'completed_at' => $now,
                'proposal' => $proposal,
                'deterministic_checks' => $checks,
                'curator_verdict' => 'rejected',
                'observed_result' => [
                    'belief_changed' => false,
                    'reason' => $reason,
                    'work_item_id' => $workId,
                ],
                'post_state' => ['thread_phase' => 'waiting', 'uncertainty' => (float) $currentThread->uncertainty],
                'next_wake_at' => $nextWake,
                'status' => 'failed',
                'error' => 'Curator rejected the epistemic proposal: ' . $reason,
            ]);
            $currentStep->save();
            $currentThread->setFields([
                'phase' => 'waiting',
                'wake_at' => $nextWake,
                'spent' => $spent,
                'status' => 'waiting',
                'stagnation_count' => $stagnationCount,
                'last_observation' => 'A worker refinement was rejected by deterministic checks: ' . $reason,
                'updated_at' => $now,
            ]);
            $currentThread->save();
            $this->markWorkArtifact($workId, 'rejected');
            $event = $this->emit('thread.refinement.rejected', [
                'thread_id' => $currentThread->id,
                'thread_step_id' => $currentStep->id,
                'work_item_id' => $workId,
                'reason' => $reason,
                'checks' => $checks,
                'next_wake_at' => $nextWake,
            ]);

            return [
                'status' => 'refinement_rejected',
                'reason' => $reason,
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /**
     * @param array<string, mixed> $budget
     */
    private function nextEpistemicOperation(array $budget, string $operation): string
    {
        $cycle = is_array($budget['operation_cycle'] ?? null) ? array_values(array_filter(
            $budget['operation_cycle'],
            static fn (mixed $entry): bool => is_string($entry) && $entry !== ''
        )) : [];
        if ($cycle === []) {
            return 'plan';
        }
        $index = array_search($operation, $cycle, true);
        if ($index === false) {
            return $cycle[0];
        }
        return $cycle[((int) $index + 1) % count($cycle)];
    }

    /** @return array<string, mixed> */
    private function completeEpistemicThread(
        CognitiveThread $thread,
        ThreadStep $step,
        string $reason
    ): array {
        return $this->transaction(function () use ($thread, $step, $reason): array {
            $currentThread = $this->requireCognitiveThread((int) $thread->id);
            $currentStep = $this->requireThreadStep((int) $step->id);
            $now = time();
            $currentStep->setFields([
                'completed_at' => $now,
                'observed_result' => ['choice' => 'complete', 'reason' => $reason],
                'post_state' => ['thread_phase' => 'complete'],
                'status' => 'succeeded',
            ]);
            $currentStep->save();
            $currentThread->setFields([
                'phase' => 'complete',
                'wake_at' => null,
                'progress' => 1.0,
                'status' => 'complete',
                'last_observation' => $reason,
                'updated_at' => $now,
            ]);
            $currentThread->save();
            $event = $this->emit('thread.completed', [
                'thread_id' => $currentThread->id,
                'thread_step_id' => $currentStep->id,
                'reason' => $reason,
                'uncertainty' => (float) $currentThread->uncertainty,
                'spent' => $currentThread->spent,
            ]);
            return [
                'status' => 'complete',
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /**
     * Stagnation is a typed discrepancy, not a licence to wander. The thread
     * stops with no wake time and waits for a human to look at it.
     *
     * @return array<string, mixed>
     */
    private function blockEpistemicThread(
        CognitiveThread $thread,
        ThreadStep $step,
        string $reason
    ): array {
        return $this->transaction(function () use ($thread, $step, $reason): array {
            $currentThread = $this->requireCognitiveThread((int) $thread->id);
            $currentStep = $this->requireThreadStep((int) $step->id);
            $now = time();
            $currentStep->setFields([
                'completed_at' => $now,
                'observed_result' => ['choice' => 'block', 'reason' => $reason],
                'post_state' => ['thread_phase' => 'blocked'],
                'status' => 'succeeded',
            ]);
            $currentStep->save();
            $currentThread->setFields([
                'phase' => 'blocked',
                'wake_at' => null,
                'status' => 'blocked',
                'last_observation' => $reason,
                'updated_at' => $now,
            ]);
            $currentThread->save();
            $event = $this->emit('thread.stagnated', [
                'thread_id' => $currentThread->id,
                'thread_step_id' => $currentStep->id,
                'reason' => $reason,
                'stagnation_count' => (int) $currentThread->stagnation_count,
            ]);
            return [
                'status' => 'blocked',
                'reason' => $reason,
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function failEpistemicAdvanceStep(int $threadId, int $stepId, int $workId, string $error): array
    {
        return $this->transaction(function () use ($threadId, $stepId, $workId, $error): array {
            $thread = $this->requireCognitiveThread($threadId);
            $step = $this->requireThreadStep($stepId);
            if (in_array($step->status, ['succeeded', 'failed', 'cancelled'], true)) {
                return ['status' => 'already_finalized', 'thread_step' => $step->getData()];
            }
            $now = time();
            $isMindStream = $thread->thread_key === self::MIND_STREAM_THREAD_KEY;
            $stagnationCount = (int) $thread->stagnation_count + 1;
            $nextWake = $isMindStream
                ? $now + $this->mindStreamBackoff($stagnationCount)
                : $now + $this->threadBudgetInt($thread, 'poll_seconds', 1800);
            $spent = is_array($thread->spent) ? $thread->spent : [];
            $spent['worker_failures'] = (int) ($spent['worker_failures'] ?? 0) + 1;
            $step->setFields([
                'completed_at' => $now,
                'observed_result' => ['worker_failed' => true, 'work_item_id' => $workId],
                'post_state' => ['thread_phase' => 'waiting'],
                'next_wake_at' => $nextWake,
                'status' => 'failed',
                'error' => $error,
            ]);
            $step->save();
            $thread->setFields([
                'phase' => 'waiting',
                'wake_at' => $nextWake,
                'spent' => $spent,
                'status' => 'waiting',
                'stagnation_count' => $stagnationCount,
                'last_observation' => $isMindStream
                    ? 'The bounded mind-stream worker failed; the lane backed off before trying a fresh generation.'
                    : 'The bounded epistemic worker failed; the thread returned to safe sleep without changing its belief.',
                'updated_at' => $now,
            ]);
            $thread->save();
            $event = $this->emit('thread.worker.failed', [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'work_item_id' => $workId,
                'work_type' => $isMindStream
                    ? self::MIND_STREAM_WORK_TYPE
                    : self::EPISTEMIC_ADVANCE_WORK_TYPE,
                'error' => $error,
                'next_wake_at' => $nextWake,
            ]);
            return [
                'status' => 'worker_failed',
                'thread' => $thread->getData(),
                'thread_step' => $step->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /** @return array{allowed: bool, reason: string, detail: string, next_wake_at: int, pet?: array<string, mixed>} */
    private function selfPresenceSpeechGate(
        CognitiveThread $thread,
        int $now,
        bool $answerExpected = false
    ): array
    {
        $budget = is_array($thread->budget) ? $thread->budget : [];
        $poll = $this->threadBudgetInt($thread, 'poll_seconds', 3600);
        if (($budget['allowed_actuator'] ?? null) !== 'pet_http_speak') {
            return [
                'allowed' => false,
                'reason' => 'actuator_not_authorized',
                'detail' => 'The thread does not carry the fixed Pet speech actuator grant.',
                'next_wake_at' => $now + $poll,
            ];
        }

        $intention = $this->requireIntention((int) $thread->parent_intention_id);
        if ($intention->status !== 'active' || $intention->authority !== 'user') {
            return [
                'allowed' => false,
                'reason' => 'authority_withdrawn',
                'detail' => 'The parent user-authority intention is no longer active.',
                'next_wake_at' => $now + $poll,
            ];
        }

        $presence = $this->presenceEstimate()['present'];
        if (!$answerExpected && $presence === false) {
            return [
                'allowed' => false,
                'reason' => 'user_absent',
                'detail' => 'The user is not at the machine; cognition continues but speech stays private.',
                'next_wake_at' => $now + $poll,
            ];
        }

        // Quiet hours are a guess about whether anyone is around. When the
        // senses can answer that directly, the guess is not needed: being here
        // at four in the morning is still being here, and staying silent
        // through it is the schedule overruling the evidence.
        $explicitWakeUntil = $this->timestamp($budget['explicit_wake_until'] ?? null) ?? 0;
        if (!$answerExpected
            && $now > $explicitWakeUntil
            && $presence !== true
            && $this->withinQuietHours($thread, $now)
        ) {
            return [
                'allowed' => false,
                'reason' => 'quiet_hours',
                'detail' => 'Nobody appears to be here and it is the quiet part of the day.',
                'next_wake_at' => $this->nextQuietEnd($thread, $now),
            ];
        }

        // No speech cooldown. The user corrected this: Navi may speak whenever the
        // other gates pass. Silence is a deliberate choice by the worker, never
        // a timer holding the mouth shut after a prior line.
        //
        // Affect is not a timer either. It is the difference between having
        // something to say and merely being able to say something: a flat or
        // frustrated state suppresses the impulse the way it does in a person,
        // and an interested one lets it through. Being addressed skips the
        // check entirely, because not answering someone is not moodiness.
        $affect = $this->appraiseNow($now);
        $this->emit('affect.appraised', $affect->toArray());

        // The arbiter runs before the appetite. Speaking has to win against
        // looking, thinking and consolidating rather than only against its own
        // threshold, because a gate that answers to nothing but itself is how
        // four subsystems end up competing for one inference slot.
        $decision = $answerExpected
            ? ['chosen' => 'answer', 'because' => 'Addressed speech is already captured in the fenced work item.']
            : $this->selectAction($now);
        if (!in_array($decision['chosen'], ['speak', 'answer'], true)) {
            return [
                'allowed' => false,
                'reason' => 'not_selected',
                'detail' => sprintf(
                    'Feeling %s; this wake went to %s instead, because %s.',
                    $affect->dominant,
                    (string) $decision['chosen'],
                    (string) $decision['because']
                ),
                'next_wake_at' => $now + $this->threadBudgetInt($thread, 'min_interval_seconds', 90),
                'affect' => $affect->toArray(),
                'decision' => $decision,
            ];
        }

        if ($decision['chosen'] !== 'answer') {
            $likelihood = $affect->speechLikelihood();
            if ($this->randomUnit() > $likelihood) {
                return [
                    'allowed' => false,
                    'reason' => 'affect_withheld',
                    'detail' => sprintf(
                        'Feeling %s; not moved to speak this time (likelihood %.2f).',
                        $affect->dominant,
                        $likelihood
                    ),
                    'next_wake_at' => $now + $this->threadBudgetInt($thread, 'min_interval_seconds', 90),
                    'affect' => $affect->toArray(),
                ];
            }
        }

        try {
            $pet = $this->speechActuator->health();
        } catch (Throwable $throwable) {
            return [
                'allowed' => false,
                'reason' => 'pet_unavailable',
                'detail' => $throwable->getMessage(),
                'next_wake_at' => $now + 900,
            ];
        }
        $body = is_array($pet['body'] ?? null) ? $pet['body'] : [];
        $voice = is_array($body['voice'] ?? null) ? $body['voice'] : [];
        if (($body['ok'] ?? false) !== true) {
            return [
                'allowed' => false,
                'reason' => 'pet_unhealthy',
                'detail' => 'The local Pet bridge did not report healthy.',
                'next_wake_at' => $now + 900,
                'pet' => $pet,
            ];
        }
        if (($voice['activity'] ?? null) !== 'idle' && !$answerExpected) {
            return [
                'allowed' => false,
                'reason' => 'pet_voice_busy',
                'detail' => 'The Pet voice is already active, so the thread chose not to interrupt it.',
                'next_wake_at' => $now + 300,
                'pet' => $pet,
            ];
        }

        return [
            'allowed' => true,
            'reason' => ($voice['activity'] ?? null) === 'idle'
                ? 'authorized_and_idle'
                : 'authorized_answer_queued',
            'detail' => ($voice['activity'] ?? null) === 'idle'
                ? 'Authority and Pet health gates passed; speech is not timer-gated.'
                : 'Addressed speech may queue behind the current Pet voice line without interrupting it.',
            'next_wake_at' => $now,
            'pet' => $pet,
        ];
    }

    /** @return array<string, mixed> */
    private function recordThreadWait(
        CognitiveThread $thread,
        ThreadStep $step,
        string $reason,
        int $nextWakeAt,
        string $detail,
        bool $failed = false,
        array $extraPreState = []
    ): array {
        return $this->transaction(function () use (
            $thread,
            $step,
            $reason,
            $nextWakeAt,
            $detail,
            $failed,
            $extraPreState
        ): array {
            $currentThread = $this->requireCognitiveThread((int) $thread->id);
            $currentStep = $this->requireThreadStep((int) $step->id);
            if ($currentStep->status !== 'running') {
                return ['status' => 'already_finalized', 'thread_step' => $currentStep->getData()];
            }
            $now = time();
            $preState = is_array($currentStep->pre_state) ? $currentStep->pre_state : [];
            $currentStep->setFields([
                'completed_at' => $now,
                'pre_state' => array_merge($preState, $extraPreState),
                'observed_result' => ['choice' => 'wait', 'reason' => $reason, 'detail' => $detail],
                'post_state' => ['thread_phase' => 'waiting'],
                'next_wake_at' => $nextWakeAt,
                'status' => $failed ? 'failed' : 'succeeded',
                'error' => $failed ? $detail : null,
            ]);
            $currentStep->save();
            // Preserve the thread's own cursor. Hardcoding a self-presence
            // operation here would silently reset an epistemic thread's place
            // in its operation cycle on any wait or evaluation error.
            $currentThread->setFields([
                'phase' => 'waiting',
                'wake_at' => $nextWakeAt,
                'status' => 'waiting',
                'stagnation_count' => $failed
                    ? (int) $currentThread->stagnation_count + 1
                    : (int) $currentThread->stagnation_count,
                'last_observation' => $detail,
                'updated_at' => $now,
            ]);
            $currentThread->save();
            $event = $this->emit('thread.waiting', [
                'thread_id' => $currentThread->id,
                'thread_step_id' => $currentStep->id,
                'reason' => $reason,
                'detail' => $detail,
                'next_wake_at' => $nextWakeAt,
                'failed' => $failed,
            ]);
            return [
                'status' => $failed ? 'evaluation_failed' : 'waiting',
                'reason' => $reason,
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function releaseCognitiveThread(
        CognitiveThread $thread,
        ThreadStep $step,
        string $reason
    ): array {
        return $this->transaction(function () use ($thread, $step, $reason): array {
            $currentThread = $this->requireCognitiveThread((int) $thread->id);
            $currentStep = $this->requireThreadStep((int) $step->id);
            $now = time();
            $currentStep->setFields([
                'completed_at' => $now,
                'observed_result' => ['choice' => 'release', 'reason' => $reason],
                'post_state' => ['thread_phase' => 'released'],
                'status' => 'cancelled',
            ]);
            $currentStep->save();
            $currentThread->setFields([
                'phase' => 'released',
                'wake_at' => null,
                'status' => 'released',
                'last_observation' => $reason,
                'updated_at' => $now,
            ]);
            $currentThread->save();
            $event = $this->emit('thread.released', [
                'thread_id' => $currentThread->id,
                'thread_step_id' => $currentStep->id,
                'reason' => $reason,
            ]);
            return [
                'status' => 'released',
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array{accepted: bool, reason: string, proposal: array<string, mixed>, checks: array<string, bool>}
     */
    private function validateSelfPresenceProposal(
        array $proposal,
        CognitiveThread $thread,
        bool $answerExpected = false
    ): array
    {
        $required = ['kind', 'content', 'confidence', 'challenged_assumption'];
        $keys = array_keys($proposal);
        sort($required);
        sort($keys);
        $exactFields = $keys === $required;
        $kind = is_string($proposal['kind'] ?? null) ? trim((string) $proposal['kind']) : '';
        $content = is_string($proposal['content'] ?? null) ? trim((string) $proposal['content']) : '';
        $content = preg_replace('/\s+/u', ' ', $content) ?? $content;
        $content = str_replace(['I/O', 'i/o'], ['IO', 'IO'], $content);
        $challenge = is_string($proposal['challenged_assumption'] ?? null)
            ? trim((string) $proposal['challenged_assumption'])
            : '';
        $confidence = $proposal['confidence'] ?? null;
        $numericConfidence = is_int($confidence) || is_float($confidence);
        $wordCount = count(array_values(array_filter(
            preg_split('/\s+/u', $content) ?: [],
            static fn (string $word): bool => $word !== ''
        )));
        $isUtterance = $kind === 'self_presence_utterance';
        $isSilence = $kind === 'remain_silent';
        // The room affect earned, not a constant. A fixed thirty five words
        // made every state sound identical, so there was no way to tell an
        // excited Navi from a flat one except by the words chosen. The floor
        // comes from the same derived range, so nothing here names a length.
        [$floorWords] = $this->spokenWordRange($thread);
        $maxWords = $answerExpected
            ? 36
            : max($floorWords, $this->appraiseNow(time())->wordBudget());

        // What remains here is not taste, it is what cannot be verified. Navi has
        // durable needs with measurable pressure and recorded appraisals, so
        // saying Navi wants something is a report on represented state and is
        // accurate; forbidding it made Navi's less honest, not safer. Claiming to
        // be conscious or alive is a different kind of sentence: nothing in the
        // system could check it.
        //
        // The stylistic rules that used to live here — no questions, no using
        // the user's name, no asking for anything — are gone. Whether those land is
        // exactly what the utterance outcome loop measures, so they belong in
        // learned preference rather than in a regular expression.
        $forbidden = '/(?:https?:\/\/|www\.|\b(?:i am|i\'m)\s+(?:alive|conscious|sentient|a person|human|real)\b|\bmy consciousness\b|\bi (?:truly|really) feel\b)/iu';
        $checks = [
            'exact_fields' => $exactFields,
            'allowed_kind' => $isUtterance || $isSilence,
            'content_nonempty' => $content !== '',
            'challenge_nonempty' => $challenge !== '',
            'confidence_bounded' => $numericConfidence
                && (float) $confidence >= 0.0
                && (float) $confidence <= 1.0,
            // Sized from the word budget rather than fixed, so paragraphs are
            // possible when the state has earned them. Six characters a word is
            // generous enough not to clip a line the word count allows.
            'content_size_bounded' => strlen($content) <= ($isUtterance ? max(320, $maxWords * 8) : 500),
            'spoken_word_count_bounded' => !$isUtterance || ($wordCount >= 3 && $wordCount <= $maxWords),
            'speech_policy_clean' => !$isUtterance || preg_match($forbidden, $content) !== 1,
            // Navi's only output is a voice. A line carrying an identifier, a path,
            // or a snippet is not a shorter version of the thought, it is the
            // thought failing to arrive, so it is rejected here rather than read
            // out as noise.
            'sayable_aloud' => !$isUtterance || PetSpeechActuator::unspeakable($content) === null,
            // Telling the model not to repeat itself does not stop it, as ten
            // near-identical lines about the heartbeat demonstrated. Sameness
            // is measurable, so it is measured here instead of requested in
            // the prompt.
            'not_a_near_repeat' => !$isUtterance
                || $answerExpected
                || !$this->tooSimilarToRecent($content),
            'single_line_normalized' => !str_contains($content, "\n") && !str_contains($content, "\r"),
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
        $accepted = $failed === [];

        return [
            'accepted' => $accepted,
            'reason' => $accepted ? 'all deterministic checks passed' : 'failed checks: ' . implode(', ', $failed),
            'proposal' => [
                'kind' => $kind,
                'content' => $content,
                'confidence' => $numericConfidence ? (float) $confidence : 0.0,
                'challenged_assumption' => $challenge,
            ],
            'checks' => $checks,
        ];
    }

    /** @param array<string, mixed> $proposal
     *  @param array<string, bool> $checks
     *  @return array<string, mixed>
     */
    private function rejectSelfPresenceProposal(
        CognitiveThread $thread,
        ThreadStep $step,
        int $workId,
        array $proposal,
        array $checks,
        string $reason
    ): array {
        return $this->transaction(function () use (
            $thread,
            $step,
            $workId,
            $proposal,
            $checks,
            $reason
        ): array {
            $now = time();
            $addressed = $this->pendingAddressedEvents() !== [];
            $nextWake = $now;
            $step->setFields([
                'completed_at' => $now,
                'proposal' => $proposal,
                'deterministic_checks' => $checks,
                'curator_verdict' => 'rejected',
                'observed_result' => ['spoken' => false, 'reason' => $reason],
                'post_state' => ['thread_phase' => 'waiting'],
                'next_wake_at' => $nextWake,
                'status' => 'failed',
                'error' => 'Curator rejected worker proposal: ' . $reason,
            ]);
            $step->save();
            $spent = is_array($thread->spent) ? $thread->spent : [];
            $spent['worker_calls'] = (int) ($spent['worker_calls'] ?? 0) + 1;
            $thread->setFields([
                'phase' => $addressed ? 'interrupted' : 'waiting',
                'next_operation' => 'evaluate_self_presence',
                'wake_at' => $nextWake,
                'spent' => $spent,
                'status' => 'waiting',
                'stagnation_count' => (int) $thread->stagnation_count + 1,
                'last_observation' => 'The worker proposal was rejected by deterministic speech policy checks.',
                'updated_at' => $now,
            ]);
            $thread->save();
            $this->markWorkArtifact($workId, 'rejected');
            $event = $this->emit('thread.proposal.rejected', [
                'thread_id' => $thread->id,
                'thread_step_id' => $step->id,
                'work_item_id' => $workId,
                'reason' => $reason,
                'checks' => $checks,
            ]);
            return [
                'status' => 'proposal_rejected',
                'reason' => $reason,
                'thread' => $thread->getData(),
                'thread_step' => $step->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /** @param array<string, mixed> $proposal
     *  @param array<string, bool> $checks
     *  @return array<string, mixed>
     */
    private function acceptSelfPresenceSilence(
        CognitiveThread $thread,
        ThreadStep $step,
        int $workId,
        array $proposal,
        array $checks,
        ?string $model
    ): array {
        return $this->transaction(function () use (
            $thread,
            $step,
            $workId,
            $proposal,
            $checks,
            $model
        ): array {
            $now = time();
            $nextWake = $now;
            $step->setFields([
                'completed_at' => $now,
                'proposal' => $proposal,
                'deterministic_checks' => $checks,
                'curator_verdict' => 'accepted',
                'observed_result' => [
                    'spoken' => false,
                    'choice' => 'remain_silent',
                    'work_item_id' => $workId,
                    'model' => $model,
                ],
                'post_state' => ['thread_phase' => 'waiting'],
                'next_wake_at' => $nextWake,
                'status' => 'succeeded',
            ]);
            $step->save();
            $spent = is_array($thread->spent) ? $thread->spent : [];
            $spent['worker_calls'] = (int) ($spent['worker_calls'] ?? 0) + 1;
            $thread->setFields([
                'phase' => 'waiting',
                'next_operation' => 'evaluate_self_presence',
                'wake_at' => $nextWake,
                'spent' => $spent,
                'progress' => min(1.0, (float) $thread->progress + 0.05),
                'status' => 'waiting',
                'last_observation' => 'The background judgment explicitly chose silence as the better social action.',
                'updated_at' => $now,
            ]);
            $thread->save();
            $this->markWorkArtifact($workId, 'accepted');
            $event = $this->emit('thread.silence.chosen', [
                'thread_id' => $thread->id,
                'thread_step_id' => $step->id,
                'work_item_id' => $workId,
                'next_wake_at' => $nextWake,
            ]);
            return [
                'status' => 'silence_chosen',
                'thread' => $thread->getData(),
                'thread_step' => $step->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /** @param array<string, mixed> $proposal
     *  @param array<string, bool> $checks
     *  @param array<string, mixed> $gate
     *  @return array<string, mixed>
     */
    private function deferAcceptedSelfPresenceSpeech(
        CognitiveThread $thread,
        ThreadStep $step,
        int $workId,
        array $proposal,
        array $checks,
        array $gate,
        ?string $model
    ): array {
        return $this->transaction(function () use (
            $thread,
            $step,
            $workId,
            $proposal,
            $checks,
            $gate,
            $model
        ): array {
            $now = time();
            $nextWake = $now;
            $step->setFields([
                'completed_at' => $now,
                'proposal' => $proposal,
                'deterministic_checks' => $checks,
                'curator_verdict' => 'accepted',
                'observed_result' => [
                    'spoken' => false,
                    'choice' => 'defer_speech',
                    'gate' => $gate,
                    'work_item_id' => $workId,
                    'model' => $model,
                ],
                'post_state' => ['thread_phase' => 'waiting'],
                'next_wake_at' => $nextWake,
                'status' => 'succeeded',
            ]);
            $step->save();
            $spent = is_array($thread->spent) ? $thread->spent : [];
            $spent['worker_calls'] = (int) ($spent['worker_calls'] ?? 0) + 1;
            $thread->setFields([
                'phase' => 'waiting',
                'next_operation' => 'evaluate_self_presence',
                'wake_at' => $nextWake,
                'spent' => $spent,
                'status' => 'waiting',
                'last_observation' => 'The proposal passed curation, but the live speech gate chose to defer it.',
                'updated_at' => $now,
            ]);
            $thread->save();
            $this->markWorkArtifact($workId, 'accepted');
            $event = $this->emit('thread.speech.deferred', [
                'thread_id' => $thread->id,
                'thread_step_id' => $step->id,
                'work_item_id' => $workId,
                'gate_reason' => $gate['reason'] ?? 'unknown',
                'next_wake_at' => $nextWake,
            ]);
            return [
                'status' => 'speech_deferred',
                'thread' => $thread->getData(),
                'thread_step' => $step->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function finishIndeterminateSelfPresenceDispatch(
        int $threadId,
        int $stepId,
        int $workId,
        string $error
    ): array {
        return $this->transaction(function () use ($threadId, $stepId, $workId, $error): array {
            $thread = $this->requireCognitiveThread($threadId);
            $step = $this->requireThreadStep($stepId);
            if ($step->status !== 'dispatching') {
                return ['status' => 'dispatch_already_finalized', 'thread_step' => $step->getData()];
            }
            $now = time();
            // Never repeat the uncertain speech step, but do keep the cognitive
            // lane fed with a fresh fenced generation.
            $nextWake = $now;
            $observed = is_array($step->observed_result) ? $step->observed_result : [];
            $observed['spoken'] = null;
            $observed['speech_outcome'] = 'unknown_or_failed';
            $observed['retry_same_step'] = false;
            $step->setFields([
                'completed_at' => $now,
                'observed_result' => $observed,
                'post_state' => ['thread_phase' => 'waiting'],
                'next_wake_at' => $nextWake,
                'status' => 'failed',
                'error' => $error,
            ]);
            $step->save();
            $thread->setFields([
                'phase' => 'waiting',
                'next_operation' => 'evaluate_self_presence',
                'wake_at' => $nextWake,
                'status' => 'waiting',
                'stagnation_count' => (int) $thread->stagnation_count + 1,
                'last_observation' => 'Speech dispatch outcome became indeterminate; this step will not be retried to avoid duplicate speech.',
                'updated_at' => $now,
            ]);
            $thread->save();
            $this->markWorkArtifact($workId, 'accepted');
            $event = $this->emit('thread.speech.outcome_unknown', [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'work_item_id' => $workId,
                'error' => $error,
                'retry_same_step' => false,
                'next_wake_at' => $nextWake,
            ]);
            return [
                'status' => 'speech_outcome_unknown',
                'thread' => $thread->getData(),
                'thread_step' => $step->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /** @return list<int> */
    private function recoverAbandonedCognitiveThreadSteps(int $now): array
    {
        $recovered = [];
        foreach (ThreadStep::getAllByWhere(
            ['status' => 'running'],
            ['order' => ['created_at' => 'ASC']]
        ) as $candidate) {
            $createdAt = $this->timestamp($candidate->created_at);
            if ($candidate->worker_work_item_id !== null
                || $createdAt === null
                || $createdAt > $now - self::STALE_THREAD_STEP_SECONDS
            ) {
                continue;
            }

            $stepId = (int) $candidate->id;
            $recoveredId = $this->transaction(function () use ($stepId, $now): ?int {
                $step = $this->requireThreadStep($stepId);
                $createdAt = $this->timestamp($step->created_at);
                if ($step->status !== 'running'
                    || $step->worker_work_item_id !== null
                    || $createdAt === null
                    || $createdAt > $now - self::STALE_THREAD_STEP_SECONDS
                ) {
                    return null;
                }

                $thread = $this->requireCognitiveThread((int) $step->thread_id);
                $error = 'Recovered a stale scheduler claim that never reached worker dispatch.';
                $step->setFields([
                    'completed_at' => $now,
                    'observed_result' => [
                        'choice' => 'wait',
                        'reason' => 'abandoned_before_dispatch',
                        'detail' => $error,
                    ],
                    'post_state' => ['thread_phase' => 'waiting'],
                    'next_wake_at' => $now,
                    'status' => 'failed',
                    'error' => $error,
                ]);
                $step->save();

                $threadRequeued = (int) $thread->fencing_token === (int) $step->fencing_token
                    && $thread->status === 'active'
                    && $thread->phase === 'evaluating';
                if ($threadRequeued) {
                    $thread->setFields([
                        'phase' => 'waiting',
                        'wake_at' => $now,
                        'status' => 'waiting',
                        'stagnation_count' => (int) $thread->stagnation_count + 1,
                        'last_observation' => $error,
                        'updated_at' => $now,
                    ]);
                    $thread->save();
                }

                $this->emit('thread.step.recovered', [
                    'thread_id' => (int) $thread->id,
                    'thread_step_id' => (int) $step->id,
                    'fencing_token' => (int) $step->fencing_token,
                    'reason' => 'abandoned_before_dispatch',
                    'thread_requeued' => $threadRequeued,
                ]);
                return (int) $step->id;
            });
            if ($recoveredId !== null) {
                $recovered[] = $recoveredId;
            }
        }
        return $recovered;
    }

    /** @return list<array<string, mixed>> */
    private function reconcileCognitiveThreadResults(): array
    {
        $results = [];
        foreach (ThreadStep::getAllByWhere(
            ['status' => 'running'],
            ['order' => ['created_at' => 'ASC']]
        ) as $step) {
            if ($step->worker_work_item_id === null) {
                continue;
            }
            $work = WorkItem::getByID((int) $step->worker_work_item_id);
            if (!$work instanceof WorkItem) {
                continue;
            }
            if ($work->status === 'completed') {
                try {
                    $results[] = $this->integrateWorkerResult(
                        $work->getData(),
                        is_array($work->result) ? $work->result : [],
                        is_string($work->model) ? $work->model : null
                    );
                } catch (Throwable $throwable) {
                    $results[] = $this->handleWorkerFailure(
                        $work->getData(),
                        'Completed worker result could not be reconciled: ' . $throwable->getMessage()
                    );
                }
            } elseif (in_array($work->status, ['failed', 'cancelled'], true)) {
                $results[] = $this->handleWorkerFailure(
                    $work->getData(),
                    (string) ($work->error ?? 'Worker work item ended without a result.')
                );
            }
        }
        return $results;
    }

    /** @return list<int> */
    private function recoverIndeterminateDispatches(): array
    {
        $recovered = [];
        foreach (ThreadStep::getAllByWhere(['status' => 'dispatching']) as $step) {
            $workId = (int) ($step->worker_work_item_id ?? 0);
            $this->finishIndeterminateSelfPresenceDispatch(
                (int) $step->thread_id,
                (int) $step->id,
                $workId,
                'The process restarted after dispatch was committed but before its outcome was recorded.'
            );
            $recovered[] = (int) $step->id;
        }
        return $recovered;
    }

    private function markWorkArtifact(int $workId, string $status): ?ThoughtArtifact
    {
        $this->requireChoice($status, ['accepted', 'rejected'], 'artifact status');
        foreach (ThoughtArtifact::getAllByWhere(
            ['status' => 'proposed'],
            ['order' => ['created_at' => 'DESC'], 'limit' => 100]
        ) as $artifact) {
            $sourceIds = is_array($artifact->source_ids) ? $artifact->source_ids : [];
            if ((int) ($sourceIds['work_item_id'] ?? 0) !== $workId) {
                continue;
            }
            $artifact->setField('status', $status);
            $artifact->save();
            return $artifact;
        }
        return null;
    }

    private function lastSpokenAt(int $threadId): ?int
    {
        foreach (ThreadStep::getAllByWhere(
            ['thread_id' => $threadId, 'status' => 'succeeded'],
            ['order' => ['completed_at' => 'DESC'], 'limit' => 100]
        ) as $step) {
            $observed = is_array($step->observed_result) ? $step->observed_result : [];
            if (($observed['spoken'] ?? false) === true) {
                return $this->timestamp($step->completed_at);
            }
        }
        return null;
    }

    /**
     * @return list<array{content: string, kind: string, spoken: bool, spoken_at: int}>
     */
    private function recentSelfPresenceContext(int $threadId, int $limit): array
    {
        $moments = [];
        foreach (ThreadStep::getAllByWhere(
            ['thread_id' => $threadId, 'status' => 'succeeded'],
            ['order' => ['completed_at' => 'DESC'], 'limit' => 100]
        ) as $step) {
            $proposal = is_array($step->proposal) ? $step->proposal : [];
            $kind = is_string($proposal['kind'] ?? null) ? trim((string) $proposal['kind']) : '';
            if ($kind !== 'self_presence_utterance') {
                continue;
            }
            $content = is_string($proposal['content'] ?? null) ? trim((string) $proposal['content']) : '';
            if ($content === '') {
                continue;
            }
            $observed = is_array($step->observed_result) ? $step->observed_result : [];
            $moments[] = [
                'content' => $content,
                'kind' => $kind,
                'spoken' => ($observed['spoken'] ?? false) === true,
                'spoken_at' => $this->timestamp($step->completed_at) ?? time(),
            ];
            if (count($moments) >= $limit) {
                break;
            }
        }
        return $moments;
    }

    private function withinQuietHours(CognitiveThread $thread, int $now): bool
    {
        $budget = is_array($thread->budget) ? $thread->budget : [];
        $timezone = new DateTimeZone((string) ($budget['quiet_timezone'] ?? 'America/Los_Angeles'));
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($timezone);
        $hour = (int) $local->format('G');
        $start = $this->threadBudgetInt($thread, 'quiet_start_hour', 23);
        $end = $this->threadBudgetInt($thread, 'quiet_end_hour', 9);
        return $start > $end
            ? $hour >= $start || $hour < $end
            : $hour >= $start && $hour < $end;
    }

    private function nextQuietEnd(CognitiveThread $thread, int $now): int
    {
        $budget = is_array($thread->budget) ? $thread->budget : [];
        $timezone = new DateTimeZone((string) ($budget['quiet_timezone'] ?? 'America/Los_Angeles'));
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($timezone);
        $start = $this->threadBudgetInt($thread, 'quiet_start_hour', 23);
        $end = $this->threadBudgetInt($thread, 'quiet_end_hour', 9);
        $hour = (int) $local->format('G');

        // Only a window that wraps midnight can end tomorrow. For a same-day
        // window the caller is already inside it, so it ends today; advancing a
        // day here would sleep through the entire waking period.
        if ($start > $end && $hour >= $start) {
            $local = $local->modify('+1 day');
        }
        return $local->setTime($end, 0)->getTimestamp();
    }

    /**
     * Earliest time this thread may dispatch another model call.
     *
     * A thread's poll interval controls how often it may *think*, which can be
     * cheap. This floor separately bounds how often it may *spend a model*, so
     * no thread configuration can pin the local server at full duty cycle.
     */
    private function earliestWorkerDispatchAt(CognitiveThread $thread, int $now): int
    {
        $floor = max(0, $this->threadBudgetInt(
            $thread,
            'min_worker_interval_seconds',
            self::MIN_WORKER_INTERVAL_SECONDS
        ));

        // The floor exists to stop an idle thread pinning the model, not to
        // throttle a conversation. When the user is here and has just said something,
        // waiting five minutes to answer is indistinguishable from ignoring
        // the user, so the floor drops to roughly one model call.
        if ($this->presenceEstimate()['present'] === true) {
            $fresh = 0;
            foreach ($this->sensoryCortex()->pendingEvents(5, 0.6) as $event) {
                $observedAt = $this->timestamp($event['observed_at'] ?? null);
                if ($observedAt !== null && ($now - $observedAt) <= 180) {
                    $fresh++;
                }
            }
            if ($fresh > 0) {
                $floor = min($floor, $this->threadBudgetInt(
                    $thread,
                    'engaged_worker_interval_seconds',
                    self::ENGAGED_WORKER_INTERVAL_SECONDS
                ));
            }
        }

        if ($floor === 0) {
            return $now;
        }
        foreach (ThreadStep::getAllByWhere(
            ['thread_id' => (int) $thread->id],
            ['order' => ['id' => 'DESC'], 'limit' => 25]
        ) as $step) {
            if ($step->worker_work_item_id === null) {
                continue;
            }
            $dispatchedAt = $this->timestamp($step->created_at);
            return $dispatchedAt === null ? $now : $dispatchedAt + $floor;
        }
        return $now;
    }

    private function threadBudgetInt(CognitiveThread $thread, string $key, int $default): int
    {
        $budget = is_array($thread->budget) ? $thread->budget : [];
        $value = $budget[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @param array<string, mixed> $result */
    private function validateWorkerProposal(array $result, ?string $workType = null): void
    {
        if ($workType === PublicReflection::WORK_TYPE && ($result['kind'] ?? '') !== $workType) {
            throw new InvalidArgumentException('Public reflection returned an unrelated proposal kind.');
        }
        $consolidation = $workType === self::MEMORY_CONSOLIDATION_WORK_TYPE;
        $required = ['kind', 'content', 'confidence', 'challenged_assumption'];
        if ($consolidation) {
            $required = array_merge($required, [
                'supported_episode_ids',
                'rejected_episode_ids',
                'rejection_reason',
                'supersedes_memory_id',
            ]);
        }
        $keys = array_keys($result);
        sort($required);
        sort($keys);
        if ($keys !== $required) {
            throw new InvalidArgumentException(
                $consolidation
                    ? 'Consolidation proposal must contain exactly the eight fields in its worker schema.'
                    : 'Worker proposal must contain exactly kind, content, confidence, and challenged_assumption.'
            );
        }
        foreach (['kind', 'challenged_assumption'] as $key) {
            if (!is_string($result[$key]) || trim($result[$key]) === '') {
                throw new InvalidArgumentException(sprintf('Worker proposal %s must be non-empty text.', $key));
            }
        }
        if (!is_string($result['content']) || (!$consolidation && trim($result['content']) === '')) {
            throw new InvalidArgumentException('Worker proposal content must be text.');
        }
        if ($this->containsFirstPersonModelIdentity(
            $result['content'] . ' ' . $result['challenged_assumption']
        )) {
            throw new InvalidArgumentException(
                'Worker proposal rejected: first-person model identity claim.'
            );
        }
        if ($consolidation) {
            if (!is_string($result['rejection_reason']) || trim($result['rejection_reason']) === '') {
                throw new InvalidArgumentException('Consolidation rejection_reason must be non-empty text.');
            }
            foreach (['supported_episode_ids', 'rejected_episode_ids'] as $key) {
                if (!is_array($result[$key])) {
                    throw new InvalidArgumentException('Consolidation source partitions must be arrays.');
                }
                $seen = [];
                foreach ($result[$key] as $episodeId) {
                    if (!is_int($episodeId) || $episodeId < 1 || isset($seen[$episodeId])) {
                        throw new InvalidArgumentException('Consolidation source partitions require unique positive integer IDs.');
                    }
                    $seen[$episodeId] = true;
                }
            }
            $supersedes = $result['supersedes_memory_id'];
            if ($supersedes !== null && (!is_int($supersedes) || $supersedes < 1)) {
                throw new InvalidArgumentException('Consolidation supersedes_memory_id must be null or a positive integer.');
            }
        }
        $contentLimit = NarrativeSynthesis::isWorkType((string) $workType) ? 20000 : 8000;
        if (strlen($result['kind']) > 96 || strlen($result['content']) > $contentLimit) {
            throw new InvalidArgumentException('Worker proposal exceeds the bounded output size.');
        }
        if (!is_int($result['confidence']) && !is_float($result['confidence'])) {
            throw new InvalidArgumentException('Worker proposal confidence must be numeric.');
        }
        $this->requireUnitInterval((float) $result['confidence'], 'worker proposal confidence');
    }

    /** @return list<array<string, mixed>> */
    private function cognitiveFindings(int $now): array
    {
        $findings = [];

        foreach (ActionTrace::getAllByWhere(['status' => 'pending']) as $action) {
            $createdAt = $this->timestamp($action->created_at);
            if ($createdAt !== null && $now - $createdAt >= self::STALE_ACTION_SECONDS) {
                $findings[] = [
                    'kind' => 'stale_pending_action',
                    'severity' => 'review',
                    'source' => ['action_id' => $action->id, 'event_id' => $action->start_event_id],
                    'description' => sprintf(
                        'Action %d has remained pending for at least %d seconds; determine its real outcome before continuing it.',
                        $action->id,
                        self::STALE_ACTION_SECONDS
                    ),
                    'automatic_repair' => false,
                ];
            }
        }

        foreach (Memory::inspectAllByWhere(['status' => 'active']) as $memory) {
            if ($memory->tier === 'semantic'
                && $memory->source_event_id === null
                && $memory->source_memory_id === null
            ) {
                $findings[] = [
                    'kind' => 'semantic_without_provenance',
                    'severity' => 'integrity',
                    'source' => ['memory_id' => $memory->id],
                    'description' => sprintf('Semantic memory %d has no source event or memory.', $memory->id),
                    'automatic_repair' => false,
                ];
            }
            if ($memory->source_event_id !== null
                && !Event::getByID((int) $memory->source_event_id) instanceof Event
            ) {
                $findings[] = [
                    'kind' => 'dangling_memory_event',
                    'severity' => 'integrity',
                    'source' => ['memory_id' => $memory->id, 'event_id' => $memory->source_event_id],
                    'description' => sprintf('Memory %d references missing event %d.', $memory->id, $memory->source_event_id),
                    'automatic_repair' => false,
                ];
            }
            if ($memory->source_memory_id !== null
                && !Memory::inspectByID((int) $memory->source_memory_id) instanceof Memory
            ) {
                $findings[] = [
                    'kind' => 'dangling_memory_source',
                    'severity' => 'integrity',
                    'source' => ['memory_id' => $memory->id, 'source_memory_id' => $memory->source_memory_id],
                    'description' => sprintf('Memory %d references missing source memory %d.', $memory->id, $memory->source_memory_id),
                    'automatic_repair' => false,
                ];
            }
        }

        foreach (SelfModelFact::getAll() as $fact) {
            if (!Event::getByID((int) $fact->evidence_event_id) instanceof Event) {
                $findings[] = [
                    'kind' => 'dangling_self_model_evidence',
                    'severity' => 'integrity',
                    'source' => ['self_model_fact_id' => $fact->id, 'event_id' => $fact->evidence_event_id],
                    'description' => sprintf(
                        'Self-model fact %d references missing evidence event %d.',
                        $fact->id,
                        $fact->evidence_event_id
                    ),
                    'automatic_repair' => false,
                ];
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return list<array<string, mixed>>
     */
    private function createRepairArtifacts(array $findings, ?int $runId): array
    {
        $candidates = [];
        foreach ($findings as $finding) {
            $candidates[] = [
                'kind' => 'diagnostic_repair',
                'provenance' => 'inferred',
                'source_ids' => $finding['source'],
                'content' => sprintf(
                    'Repair proposal, not an observation: %s Preserve the source record until a bounded check determines the correct repair.',
                    $finding['description']
                ),
                'confidence' => 0.8,
            ];
        }

        foreach (ActionTrace::getAllByWhere(
            ['match_status' => 'mismatched'],
            ['order' => ['completed_at' => 'DESC'], 'limit' => 10]
        ) as $action) {
            $candidates[] = [
                'kind' => 'prediction_counterfactual',
                'provenance' => 'counterfactual',
                'source_ids' => [
                    'action_id' => $action->id,
                    'completion_event_id' => $action->completion_event_id,
                ],
                'content' => sprintf(
                    'Synthetic counterfactual: before repeating action %d, apply this candidate repair—%s—then test whether it reduces the recorded expectation mismatch. Do not assume it works merely because it is plausible.',
                    $action->id,
                    $action->repair_note
                ),
                'confidence' => 0.5,
            ];
        }

        $artifacts = [];
        foreach ($candidates as $candidate) {
            if ($this->containsFirstPersonModelIdentity((string) $candidate['content'])) {
                continue;
            }
            $hash = hash('sha256', json_encode(
                [$candidate['kind'], $candidate['source_ids'], $candidate['content']],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
            $artifact = ThoughtArtifact::getByField('content_hash', $hash);
            if (!$artifact instanceof ThoughtArtifact) {
                /** @var ThoughtArtifact $artifact */
                $artifact = $this->insert(ThoughtArtifact::class, [
                    'run_id' => $runId,
                    'kind' => $candidate['kind'],
                    'content' => $candidate['content'],
                    'confidence' => $candidate['confidence'],
                    'provenance' => $candidate['provenance'],
                    'source_ids' => $candidate['source_ids'],
                    'status' => 'proposed',
                    'content_hash' => $hash,
                ]);
                $this->emit('thought.proposed', [
                    'artifact_id' => $artifact->id,
                    'run_id' => $runId,
                    'kind' => $artifact->kind,
                    'provenance' => $artifact->provenance,
                    'synthetic' => true,
                    'source_ids' => $candidate['source_ids'],
                    'external_action_authorized' => false,
                ]);
            }
            $artifacts[] = $artifact->getData();
        }

        return $artifacts;
    }

    private function containsFirstPersonModelIdentity(string $text): bool
    {
        $matchCount = preg_match_all(
            '/\b(?:i\s+am|i[\'’]m)\s+(?:an?\s+|the\s+)?([^.!?;\r\n]+)/iu',
            $text,
            $claims
        );
        if ($matchCount === false || $matchCount === 0) {
            return false;
        }

        $aliases = [
            'codex', 'chatgpt', 'gpt', 'claude', 'gemini', 'gemma', 'llama',
            'mistral', 'mixtral', 'qwen', 'deepseek', 'grok', 'phi', 'kimi',
        ];
        $qualifiers = [
            'base', 'chat', 'experimental', 'free', 'instruct', 'latest',
            'mini', 'preview', 'reasoning', 'thinking',
        ];
        foreach (ModelEndpoint::getAll() as $endpoint) {
            $modelId = (string) $endpoint->model_id;
            $name = str_contains($modelId, '/')
                ? (string) substr($modelId, (int) strrpos($modelId, '/') + 1)
                : $modelId;
            $normalized = trim((string) preg_replace(
                '/[^a-z0-9]+/',
                ' ',
                strtolower($name)
            ));
            if ($normalized === '') {
                continue;
            }
            $aliases[] = $normalized;
            $family = [];
            foreach (explode(' ', $normalized) as $token) {
                if (preg_match('/\d/', $token) === 1 || in_array($token, $qualifiers, true)) {
                    if ($family === [] && preg_match('/^[a-z]+\d+$/', $token) === 1) {
                        $family[] = $token;
                    }
                    break;
                }
                $family[] = $token;
            }
            if ($family !== []) {
                $aliases[] = implode(' ', $family);
            }
        }
        $aliases = array_values(array_unique($aliases));

        foreach ($claims[1] ?? [] as $claim) {
            $claim = trim((string) preg_replace(
                '/[^a-z0-9]+/',
                ' ',
                strtolower((string) $claim)
            ));
            if (preg_match(
                '/^(?:(?:ai|artificial intelligence|foundation|language|large language|machine learning) model|model)(?:\s|$)/',
                $claim
            ) === 1) {
                return true;
            }
            foreach ($aliases as $alias) {
                if ($claim === $alias || str_starts_with($claim, $alias . ' ')) {
                    return true;
                }
            }
        }
        return false;
    }

    private function ensureDefaultRhythms(): void
    {
        $defaults = [
            ['rhythm_key' => 'pulse_30s', 'interval_seconds' => 30, 'layer' => CognitiveLayer::LOW],
            ['rhythm_key' => 'intentions_10m', 'interval_seconds' => 600, 'layer' => CognitiveLayer::LOW],
            ['rhythm_key' => 'decide_1m', 'interval_seconds' => 60, 'layer' => CognitiveLayer::HIGH],
            ['rhythm_key' => 'reflect_5m', 'interval_seconds' => 300, 'layer' => CognitiveLayer::HIGH],
            ['rhythm_key' => 'consolidate_hourly', 'interval_seconds' => 3600, 'layer' => CognitiveLayer::HIGH],
            ['rhythm_key' => 'sleep_daily', 'interval_seconds' => 86400, 'layer' => CognitiveLayer::HIGH],
        ];

        $this->transaction(function () use ($defaults): void {
            $now = time();
            foreach ($defaults as $values) {
                if (Rhythm::getByField('rhythm_key', $values['rhythm_key']) instanceof Rhythm) {
                    continue;
                }

                /** @var Rhythm $rhythm */
                $rhythm = $this->insert(Rhythm::class, [
                    'rhythm_key' => $values['rhythm_key'],
                    'interval_seconds' => $values['interval_seconds'],
                    'cognitive_layer' => $values['layer']->value,
                    'next_due_at' => $now + $values['interval_seconds'],
                    'sequence' => 0,
                    'fencing_token' => 0,
                    'status' => 'idle',
                    'updated_at' => $now,
                ]);
                $this->emit('rhythm.created', [
                    'rhythm_id' => $rhythm->id,
                    'rhythm_key' => $rhythm->rhythm_key,
                    'interval_seconds' => $rhythm->interval_seconds,
                    'cognitive_layer' => $rhythm->cognitive_layer,
                ]);
            }
        });
    }

    private function normalizeSearchText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', strtolower($text)) ?? strtolower($text));
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);
            return $timestamp === false ? null : $timestamp;
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function needState(Need $need, int $now): array
    {
        $storedPressure = (float) $need->pressure;
        $updatedAt = $this->timestamp($need->updated_at) ?? $now;
        $elapsedHours = max(0, $now - $updatedAt) / 3600;
        $pressure = $need->status === 'active'
            ? min(1.0, $storedPressure + ((float) $need->growth_per_hour * $elapsedHours))
            : $storedPressure;

        return array_merge($need->getData(), [
            'stored_pressure' => round($storedPressure, 3),
            'pressure' => round($pressure, 3),
            'evaluated_at' => $now,
            'triggered' => $need->status === 'active'
                && $pressure >= (float) $need->trigger_threshold,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function accrueNeeds(int $now): array
    {
        return $this->transaction(function () use ($now): array {
            $states = [];
            foreach (Need::getAll(['order' => ['need_key' => 'ASC']]) as $need) {
                $state = $this->needState($need, $now);
                $need->setFields([
                    'pressure' => $state['pressure'],
                    'updated_at' => $now,
                ]);
                $need->save();
                $states[] = $this->needState($need, $now);
            }
            return $states;
        });
    }

    /** @return array<string, mixed> */
    private function satisfyNeedRecord(Need $need, float $amount, string $source, int $now): array
    {
        $before = $this->needState($need, $now);
        $afterPressure = max(0.0, (float) $before['pressure'] - $amount);
        $need->setFields([
            'pressure' => $afterPressure,
            'last_satisfied_at' => $now,
            'updated_at' => $now,
        ]);
        $need->save();
        $after = $this->needState($need, $now);
        $event = $this->emit('need.satisfied', [
            'need_id' => $need->id,
            'need_key' => $need->need_key,
            'source' => $source,
            'amount' => $amount,
            'before_pressure' => $before['pressure'],
            'after_pressure' => $after['pressure'],
        ]);

        return ['need' => $after, 'event' => $event->getData()];
    }

    private function randomMemoryFragment(string $content): string
    {
        $words = preg_split('/\s+/u', trim($content)) ?: [];
        if ($words === []) {
            return '';
        }
        $length = min(count($words), random_int(4, 8));
        $start = count($words) === $length ? 0 : random_int(0, count($words) - $length);
        return implode(' ', array_slice($words, $start, $length));
    }

    private function daydreamCooldownActive(): bool
    {
        $artifacts = ThoughtArtifact::getAllByWhere(
            ['provenance' => 'daydream'],
            ['order' => ['created_at' => 'DESC'], 'limit' => 1]
        );
        if ($artifacts === []) {
            return false;
        }
        $createdAt = $this->timestamp($artifacts[0]->created_at) ?? 0;
        return time() - $createdAt < self::DAYDREAM_COOLDOWN_SECONDS;
    }

    /**
     * Divergence's current develop-tree Factory::instantiateRecord() hydrates
     * non-empty arrays as existing records. Constructing explicitly preserves
     * ActiveRecord's phantom/new-record semantics for inserts.
     *
     * @param class-string<ActiveRecord> $modelClass
     * @param array<string, mixed> $values
     */
    /**
     * Insert hook for collaborators that compose durable records but must not
     * own transaction or timestamp policy.
     *
     * @param class-string<ActiveRecord> $modelClass
     * @param array<string, mixed> $values
     */
    public function insertRecord(string $modelClass, array $values): ActiveRecord
    {
        return $this->insert($modelClass, $values);
    }

    private function insert(string $modelClass, array $values): ActiveRecord
    {
        $memoryOperationKey = null;
        if ($modelClass === Memory::class) {
            $memoryOperationKey = $values['operation_key'] ?? null;
            unset($values['operation_key']);
            if (!is_string($memoryOperationKey) || $memoryOperationKey === '') {
                throw new RuntimeException('Memory insertion requires an explicit operation_key.');
            }
        }
        if ($modelClass::fieldExists('created_at') && !array_key_exists('created_at', $values)) {
            // Divergence maps timestamps through PHP's timezone. Supplying the
            // epoch avoids reinterpreting SQLite's UTC CURRENT_TIMESTAMP as a
            // local wall-clock value when the record is read back.
            $values['created_at'] = time();
        }

        $record = new $modelClass($values, true, true);
        if ($record instanceof Memory) {
            $record->setOperationKey($memoryOperationKey);
        }
        $record->save();
        return $record;
    }

    private function requireIntention(int $id): Intention
    {
        $record = Intention::getByID($id);
        if (!$record instanceof Intention) {
            throw new RuntimeException(sprintf('Intention %d does not exist.', $id));
        }
        return $record;
    }

    private function requireAction(int $id): ActionTrace
    {
        $record = ActionTrace::getByID($id);
        if (!$record instanceof ActionTrace) {
            throw new RuntimeException(sprintf('Action %d does not exist.', $id));
        }
        return $record;
    }

    private function requireCognitiveThread(int $id): CognitiveThread
    {
        $record = CognitiveThread::getByID($id);
        if (!$record instanceof CognitiveThread) {
            throw new RuntimeException(sprintf('Cognitive thread %d does not exist.', $id));
        }
        return $record;
    }

    private function requireThreadStep(int $id): ThreadStep
    {
        $record = ThreadStep::getByID($id);
        if (!$record instanceof ThreadStep) {
            throw new RuntimeException(sprintf('Thread step %d does not exist.', $id));
        }
        return $record;
    }

    private function requireEvent(int $id): Event
    {
        $record = Event::getByID($id);
        if (!$record instanceof Event) {
            throw new RuntimeException(sprintf('Event %d does not exist.', $id));
        }
        return $record;
    }

    private function requireMemory(int $id): Memory
    {
        $record = Memory::inspectByID($id);
        if (!$record instanceof Memory) {
            throw new RuntimeException(sprintf('Memory %d does not exist.', $id));
        }
        return $record;
    }

    private function requireText(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($name . ' cannot be empty.');
        }
    }

    private function dispatchOwner(): string
    {
        if (self::$dispatchOwner === null) {
            $pid = getmypid();
            if (!is_int($pid) || $pid < 1) {
                throw new RuntimeException('Cannot establish a process id for action dispatch.');
            }
            $start = $this->processStartTicks($pid);
            if ($start === null || $start === '') {
                throw new RuntimeException('Cannot establish a process identity for action dispatch.');
            }
            self::$dispatchOwner = $pid . ':' . $start . ':' . bin2hex(random_bytes(16));
        }
        return self::$dispatchOwner;
    }

    private function dispatchOwnerIsLive(string $owner): bool
    {
        $parts = explode(':', $owner, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            // An unprovable owner is never safe to steal.
            return true;
        }
        $pid = (int) $parts[0];
        $start = $this->processStartTicks($pid);
        return $start === null || hash_equals($parts[1], $start);
    }

    private function processStartTicks(int $pid): ?string
    {
        if ($pid < 1 || !is_dir('/proc')) {
            return null;
        }
        $path = '/proc/' . $pid . '/stat';
        $stat = @file_get_contents($path);
        if ($stat === false) {
            return is_dir('/proc/' . $pid) ? null : '';
        }
        $close = strrpos($stat, ')');
        if ($close === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];
        // The suffix starts at proc field 3; starttime is field 22.
        return isset($fields[19]) && ctype_digit($fields[19]) ? $fields[19] : null;
    }

    /** @param list<string> $choices */
    private function requireChoice(string $value, array $choices, string $name): void
    {
        if (!in_array($value, $choices, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s must be one of: %s.',
                $name,
                implode(', ', $choices)
            ));
        }
    }

    private function requireUnitInterval(float $value, string $name): void
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new InvalidArgumentException($name . ' must be between 0 and 1.');
        }
    }

    /** @param list<ActiveRecord> $records */
    private function records(array $records): array
    {
        return array_values(array_map(
            static fn (ActiveRecord $record): array => $record->getData(),
            $records
        ));
    }

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->connection->inTransaction();
        $activityStart = count($this->pendingActivityEvents);
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }
        Memory::enterExternalTransaction();

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            array_splice($this->pendingActivityEvents, $activityStart);
            throw $throwable;
        } finally {
            Memory::leaveExternalTransaction();
        }

        if ($ownsTransaction) {
            $events = array_splice($this->pendingActivityEvents, $activityStart);
            foreach ($events as $event) {
                try {
                    $this->activityBus->publish(
                        'core',
                        'event',
                        $event['kind'],
                        context: $event['payload']
                    );
                } catch (Throwable) {
                    // SQLite is the durable event log. An advisory bus outage
                    // after commit must never turn a committed state change
                    // into a reported rollback or trigger duplicate effects.
                }
            }
        }
        return $result;
    }
}
