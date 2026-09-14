<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use DateTimeInterface;
use Divergence\Models\ActiveRecord;
use InvalidArgumentException;
use NaviBrain\Core\CapsuleAssembler;
use NaviBrain\Core\CodexSparkWorker;
use NaviBrain\Core\DecisionStateMachine;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Core\OtherModel;
use NaviBrain\Core\ProceduralMemory;
use NaviBrain\Core\WorkingMemory;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\Event;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Perception\PetSpeechActuator;
use NaviBrain\Perception\SensoryCortex;
use NaviBrain\Perception\SocialFeedback;
use NaviBrain\Storage\Schema;
use NaviBrain\Support\ActivityBus;
use RuntimeException;

class Executive
{
    private array $components = [];
    private static ?array $componentMethods = null;
    protected const COMPONENT_CLASSES = [
        DecisionOwnership::class,
        ProcedureLifecycle::class,
        ActionDispatch::class,
        IntentionManagement::class,
        ThreadCreation::class,
        ActionPlanning::class,
        MemoryStorage::class,
        ConsolidationLedger::class,
        DreamConsolidation::class,
        ConsolidationPlanning::class,
        VisionPlanning::class,
        DecisionCompletion::class,
        VisionResults::class,
        Affect::class,
        ConsolidationResults::class,
        NeedsAndRest::class,
        Interrupts::class,
        HeartbeatScheduling::class,
        WorkQueue::class,
        WorkerResults::class,
        ModelEndpoints::class,
        Values::class,
        ContextMetrics::class,
        EventLog::class,
        RhythmLifecycle::class,
        DatabaseMaintenance::class,
        SelfPresenceComposition::class,
        EpistemicPlanning::class,
        MindStream::class,
        IntentionCompletion::class,
        EpistemicResults::class,
        SelfPresenceDispatch::class,
        ThreadRecovery::class,
        CognitiveHealth::class,
    ];

    public function __call(string $method, array $arguments): mixed
    {
        if (static::$componentMethods === null) {
            static::$componentMethods = [];
            foreach (static::COMPONENT_CLASSES as $class) {
                foreach (get_class_methods($class) as $name) {
                    if (!str_starts_with($name, '__')) {
                        static::$componentMethods[$name] = $class;
                    }
                }
            }
        }
        $class = static::$componentMethods[$method] ?? null;
        if ($class === null) {
            throw new \BadMethodCallException('Unknown executive operation: ' . $method);
        }
        $component = $this->components[$class] ??= new $class($this);
        return $component->$method(...$arguments);
    }

    public const SELF_PRESENCE_SPEECH_WORK_TYPE = 'self_presence_speech';
    public const SELF_PRESENCE_ANSWER_WORK_TYPE = 'self_presence_answer';
    public const MEMORY_CONSOLIDATION_WORK_TYPE = 'memory_consolidation';
    public const STALE_ACTION_SECONDS = 86400;
    public const LOOK_WORK_TYPE = 'machine_look';
    public const COMPLETION_WORK_TYPE = 'intention_completion';

    public const COMPLETION_CONFIDENCE_FLOOR = 0.7;
    public const CONSOLIDATION_NEW = 12;
    public const CONSOLIDATION_INTERLEAVED = 4;
    public const CONSOLIDATION_EXISTING = 5;

    public const CONSOLIDATION_PENDING_WINDOW = 512;
    public const CONSOLIDATION_CONFIDENCE_FLOOR = 0.55;
    public const CONSOLIDATION_MAX_ATTEMPTS = 3;
    public const CONSOLIDATION_VALIDATOR_VERSION = 7;
    public const DREAM_REFERENCE_PROTOCOL = 'dream-consolidation-v2';
    public const DREAM_RENDERER_VERSION = 'dream-json-v1';
    public const DREAM_PROJECTION_VERSION = 'consolidation-evidence-v1';

    public const CONSOLIDATION_MEMORY_FLOOR = 4;

    public const REPEAT_WINDOW_SECONDS = 3600;
    public const REPEAT_OVERLAP = 0.6;

    public const NOMINAL_SPEAKING_RATE = 2.5;

    public const AFFECT_WINDOW_SECONDS = 600;
    public const DAYDREAM_MEMORY_LIMIT = 3;
    public const DAYDREAM_COOLDOWN_SECONDS = 300;
    public const SELF_PRESENCE_NEED_KEY = 'relationship_continuity';
    public const SELF_PRESENCE_THREAD_KEY = 'self_presence';
    public const EPISTEMIC_ADVANCE_THREAD_KEY = 'epistemic_advance';
    public const EPISTEMIC_ADVANCE_WORK_TYPE = 'epistemic_advance_step';
    public const METRICS_PROTOCOL_V1 = 'cognitive-v1';
    public const MIND_STREAM_THREAD_KEY = 'mind_stream';
    public const MIND_STREAM_WORK_TYPE = 'mind_stream_thought';
    public const MIND_STREAM_HISTORY = 24;
    public const MIND_STREAM_CONTENT_OVERLAP = 0.35;
    public const MIND_STREAM_CHALLENGE_OVERLAP = 0.55;
    public const MIND_STREAM_CHALLENGE_CONTENT_OVERLAP = 0.25;
    public const MIND_STREAM_EVIDENCE_CONTENT_OVERLAP = 0.30;
    public const MIND_STREAM_EVIDENCE_CHALLENGE_OVERLAP = 0.40;
    public const LOCAL_MODEL_PROVIDER = 'local';
    public const CODEX_MODEL_PROVIDER = 'codex';
    public const MIN_WORKER_INTERVAL_SECONDS = 300;

    public static ?string $dispatchOwner = null;

    public static array $relinquishedDecisionClaims = [];
    public static bool $drainingDecisionRelinquishments = false;
    public static int $lookClaimSweepCursor = 0;

    public const QUICK_CHECK_INTERVAL_SECONDS = 900;

    public const STALE_THREAD_STEP_SECONDS = 300;

    public const ENGAGED_WORKER_INTERVAL_SECONDS = 45;

    public PetSpeechActuator $speechActuator;
    public CapsuleAssembler $capsuleAssembler;
    public WorkingMemory $workingMemory;
    public ProceduralMemory $proceduralMemory;
    public DecisionStateMachine $decisionStateMachine;
    public OtherModel $otherModel;
    public ActivityBus $activityBus;
    public ?SensoryCortex $sensoryCortex = null;
    public ?SocialFeedback $socialFeedback = null;

    public ?array $cachedQuickCheck = null;
    public int $cachedQuickCheckAt = 0;

    public const HALTING_FINDINGS = ['integrity_failure'];

    public const VALUE_SHORTFALL_ALIGNMENT = 0.5;

    public const VALUE_INTENTION_STREAK = 3;

    public const METRICS_MANIFEST_V1 = <<<'MANIFEST'
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

    public const METRICS_MANIFEST_V1_SHA256 = 'd5f8af0a5286af90f0a16cc8e999b4fa598bd3ee6bf180677b2f6d019193e87f';

    public static function isSelfPresenceWorkType(string $workType): bool
    {
        return in_array($workType, [ self::SELF_PRESENCE_SPEECH_WORK_TYPE, self::SELF_PRESENCE_ANSWER_WORK_TYPE, ], true);
    }

    public function __construct(public readonly Schema $schema = new Schema(), ?PetSpeechActuator $speechActuator = null)
    {
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

    public function cognitionControl(): array
    {
        return ExecutiveControl::status();
    }

    public function pauseCognition(string $reason): array
    {
        $this->requireText($reason, 'pause reason');
        $state = ExecutiveControl::status();
        if ($state['paused']) {
            return $state;
        }
        $this->emit('executive.control.paused', ['reason' => trim($reason)]);
        return ExecutiveControl::status();
    }

    public function resumeCognition(int $pauseEventId): array
    {
        if ($pauseEventId < 1) {
            throw new InvalidArgumentException('Resume requires a positive pause event ID.');
        }
        $state = ExecutiveControl::status();
        if ($state['pause_event_id'] !== $pauseEventId) {
            throw new RuntimeException('Resume does not match the current pause identity.');
        }
        if (!$state['paused']) {
            return $state;
        }
        $this->emit('executive.control.resumed', [ 'reason' => 'Operator resumed cognition.', 'pause_event_id' => $pauseEventId, ]);
        return ExecutiveControl::status();
    }

    public function workingMemory(): WorkingMemory
    {
        return $this->workingMemory;
    }

    public function publishDecisionReasoning(int $cycleId, array $plan, array $basis): void
    {
        $this->workingMemory->publishDecisionReasoning($cycleId, $plan, $basis);
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

    public function initialize(): array
    {
        $schema = $this->schema->ensure();
        $this->ensureDefaultNeeds();
        $this->ensureDefaultRhythms();
        $this->registerCodexModel(CodexSparkWorker::MODEL_ID);
        $this->otherModel->captureBaseline();
        return $schema;
    }

    public function normalizeSearchText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', strtolower($text)) ?? strtolower($text));
    }

    public function timestamp(mixed $value): ?int
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

    public function requireIntention(int $id): Intention
    {
        $record = Intention::getByID($id);
        if (!$record instanceof Intention) {
            throw new RuntimeException(sprintf('Intention %d does not exist.', $id));
        }
        return $record;
    }

    public function requireAction(int $id): ActionTrace
    {
        $record = ActionTrace::getByID($id);
        if (!$record instanceof ActionTrace) {
            throw new RuntimeException(sprintf('Action %d does not exist.', $id));
        }
        return $record;
    }

    public function requireCognitiveThread(int $id): CognitiveThread
    {
        $record = CognitiveThread::getByID($id);
        if (!$record instanceof CognitiveThread) {
            throw new RuntimeException(sprintf('Cognitive thread %d does not exist.', $id));
        }
        return $record;
    }

    public function requireThreadStep(int $id): ThreadStep
    {
        $record = ThreadStep::getByID($id);
        if (!$record instanceof ThreadStep) {
            throw new RuntimeException(sprintf('Thread step %d does not exist.', $id));
        }
        return $record;
    }

    public function requireEvent(int $id): Event
    {
        $record = Event::getByID($id);
        if (!$record instanceof Event) {
            throw new RuntimeException(sprintf('Event %d does not exist.', $id));
        }
        return $record;
    }

    public function requireMemory(int $id): Memory
    {
        $record = Memory::inspectByID($id);
        if (!$record instanceof Memory) {
            throw new RuntimeException(sprintf('Memory %d does not exist.', $id));
        }
        return $record;
    }

    public function requireText(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($name . ' cannot be empty.');
        }
    }

    public function requireChoice(string $value, array $choices, string $name): void
    {
        if (!in_array($value, $choices, true)) {
            throw new InvalidArgumentException(sprintf( '%s must be one of: %s.', $name, implode(', ', $choices) ));
        }
    }

    public function requireUnitInterval(float $value, string $name): void
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new InvalidArgumentException($name . ' must be between 0 and 1.');
        }
    }

    public function records(array $records): array
    {
        return array_values(array_map( static fn (ActiveRecord $record): array => $record->getData(), $records ));
    }
}
