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
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Appraisal;
use NaviBrain\Model\CapsuleSlot;
use NaviBrain\Model\Checkpoint;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\ContextCapsule;
use NaviBrain\Model\CycleRun;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Event;
use NaviBrain\Model\ExecutiveInterrupt;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\MetricSnapshot;
use NaviBrain\Model\ModelEndpoint;
use NaviBrain\Model\Need;
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
use RuntimeException;
use Throwable;

final class ExecutiveCore
{
    public const SELF_PRESENCE_SPEECH_WORK_TYPE = 'self_presence_speech';
    public const SELF_PRESENCE_ANSWER_WORK_TYPE = 'self_presence_answer';
    private const STALE_ACTION_SECONDS = 86400;
    private const CONSOLIDATION_WORK_TYPE = 'memory_consolidation';
    private const LOOK_WORK_TYPE = 'machine_look';
    private const COMPLETION_WORK_TYPE = 'intention_completion';
    /** A finished intention is a claim about the world, so it needs real confidence. */
    private const COMPLETION_CONFIDENCE_FLOOR = 0.7;
    /**
     * The replay mix. New material is the minority on purpose: interleaving is
     * only interleaving if what is already known outweighs what just happened,
     * which is what keeps one loud recent hour from being written down as
     * though it were the shape of things.
     */
    private const CONSOLIDATION_NEW = 4;
    private const CONSOLIDATION_INTERLEAVED = 6;
    private const CONSOLIDATION_EXISTING = 3;
    private const CONSOLIDATION_CONFIDENCE_FLOOR = 0.4;
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
    private const LOCAL_MODEL_PROVIDER = 'local';
    private const MIN_WORKER_INTERVAL_SECONDS = 300;
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
    private ?SensoryCortex $sensoryCortex = null;
    private ?SocialFeedback $socialFeedback = null;

    public function __construct(
        private readonly Schema $schema = new Schema(),
        ?PetSpeechActuator $speechActuator = null
    ) {
        $this->connection = Connections::getConnection();
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

    /** @return array{version: int, tables: list<string>} */
    public function initialize(): array
    {
        $schema = $this->schema->ensure();
        $this->ensureDefaultNeeds();
        $this->ensureDefaultRhythms();
        $this->registerLocalModel(LocalModelWorker::MODEL_ID);
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
                $budget['poll_seconds'] = 5;
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
                    'poll_seconds' => 5,
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
                    'poll_seconds' => 5,
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
     * herself, paced by salience, causally linked through a carried-over
     * workspace. It is not continuous token generation, and it stops while Navi
     * is asleep.
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
                    'effect_ceiling' => 'act',
                    'concern' => 'Keep one inner monologue while awake: talk to yourself about what is happening. Most lines stay private; occasionally murmur one aloud as self-talk through Pet.',
                    'current_belief' => 'Inner monologue is successive self-talk linked across wakes; tempo follows salience; occasional spoken murmurs are still aimed at herself, not at Aku.',
                    'phase' => 'awake',
                    'next_operation' => 'think',
                    'expected_postcondition' => 'Each tick records one line of self-talk or an explicit reason for drifting; a subset may be murmured aloud.',
                    'wake_at' => $now,
                    'budget' => $budget,
                    'status' => 'active',
                    'last_observation' => 'User asked that the inner monologue occasionally produce spoken self-talk.',
                    'updated_at' => $now,
                ]);
                $existing->save();
                $event = $this->emit('thread.policy.changed', [
                    'thread_id' => $existing->id,
                    'thread_key' => $existing->thread_key,
                    'change_authority' => 'user',
                    'policy' => 'inner_monologue_with_occasional_murmur',
                    'effect_ceiling' => 'act',
                    'allowed_actuator' => 'pet_http_speak',
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
                'effect_ceiling' => 'act',
                'concern' => 'Keep one inner monologue while awake: talk to yourself about what is happening. Most lines stay private; occasionally murmur one aloud as self-talk through Pet.',
                'current_belief' => 'Inner monologue is successive self-talk linked across wakes; tempo follows salience; occasional spoken murmurs are still aimed at herself, not at Aku.',
                'uncertainty' => 0.7,
                'support_refs' => [
                    'intention_id' => (int) $intention->id,
                    'authorization' => 'explicit_user_request_for_inner_monologue',
                    'authorized_at' => $now,
                    'effect_ceiling' => 'act',
                    'allowed_actuator' => 'pet_http_speak',
                ],
                'desired_outcome' => 'A legible monologue across a waking period, mostly private, with occasional spoken self-talk murmured through Pet.',
                'phase' => 'awake',
                'next_operation' => 'think',
                'expected_postcondition' => 'Each tick records one line of self-talk or an explicit reason for drifting; a subset may be murmured aloud.',
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
                'effect_ceiling' => 'act',
                'continuous' => true,
                'mode' => 'inner_monologue',
                'allowed_actuator' => 'pet_http_speak',
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
            // Tempo. The floor keeps a burst of edges from pinning the local
            // model; the base is the idle drift rate between lines of self-talk.
            'idle_interval_seconds' => 240,
            'min_interval_seconds' => 60,
            'salience_gain' => 3.0,
            'min_worker_interval_seconds' => 60,
            'quiet_start_hour' => 2,
            'quiet_end_hour' => 9,
            'quiet_timezone' => 'America/Los_Angeles',
            'mode' => 'inner_monologue',
            // Occasional spoken self-talk. Not a cooldown: each accepted line
            // gets a deterministic chance to be murmured through Pet.
            'allowed_actuator' => 'pet_http_speak',
            'murmur_chance' => 0.22,
            'murmur_min_confidence' => 0.55,
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
        ?int $procedureRunId = null,
        ?int $stepIndex = null,
        ?array $verifier = null
    ): array
    {
        $this->requireText($description, 'description');
        $this->requireText($expected, 'expected result');
        $intention = $this->requireIntention($intentionId);
        if ($intention->status !== 'active') {
            throw new RuntimeException('Actions can only start for active intentions.');
        }

        $procedure = $this->proceduralMemory->recall($description, $expected, $actionKind, $arguments);

        return $this->transaction(function () use (
            $intentionId,
            $description,
            $expected,
            $actionKind,
            $arguments,
            $procedureId,
            $procedureRunId,
            $stepIndex,
            $verifier,
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
                $procedureRunId,
                $stepIndex,
                $verifier
            );

            $event = $this->emit('action.started', [
                'action_id' => $action->id,
                'intention_id' => $intentionId,
                'description' => $description,
                'expected' => $expected,
                'action_kind' => $actionKind,
                'arguments' => $actionKind === null ? null : $arguments,
                'procedure_id' => $procedureId ?? ($procedure['procedure_id'] ?? null),
                'procedure_memory_id' => $procedure['memory_id'] ?? null,
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
    public function runProcedure(int $procedureId, int $intentionId, array $arguments = []): array
    {
        return $this->proceduralMemory->run($procedureId, $intentionId, $arguments);
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

        $action = $this->requireAction($actionId);
        if ($action->status !== 'pending') {
            throw new RuntimeException('The action has already been finished.');
        }

        return $this->transaction(function () use ($action, $status, $observed, $matched, $repairNote): array {
            $matchStatus = $matched ? 'matched' : 'mismatched';
            $event = $this->emit('action.finished', [
                'action_id' => $action->id,
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

            /** @var Memory $memory */
            $memory = $this->insert(Memory::class, [
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
                'updated_at' => time(),
            ]);

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

            $procedure = $this->proceduralMemory->observe($action, $event, $memory);

            return [
                'action' => $action->getData(),
                'event' => $event->getData(),
                'episodic_memory' => $memory->getData(),
                'procedure' => $procedure,
            ];
        });
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

    public function addMemory(
        string $tier,
        string $content,
        float $confidence,
        ?int $sourceEventId = null,
        ?int $sourceMemoryId = null,
        ?int $supersedesId = null,
        ?string $expiresAt = null,
        bool $allowProceduralWrite = false
    ): array {
        $this->requireChoice($tier, ['working', 'episodic', 'semantic', 'procedural'], 'tier');
        $this->requireText($content, 'memory content');
        $this->requireUnitInterval($confidence, 'confidence');

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

        return $this->transaction(function () use (
            $tier,
            $content,
            $confidence,
            $sourceEventId,
            $sourceMemoryId,
            $supersedesId,
            $expiresAt,
            $superseded
        ): array {
            /** @var Memory $memory */
            $memory = $this->insert(Memory::class, [
                'tier' => $tier,
                'content' => $content,
                'confidence' => $confidence,
                'status' => 'active',
                'source_event_id' => $sourceEventId,
                'source_memory_id' => $sourceMemoryId,
                'supersedes_id' => $supersedesId,
                'expires_at' => $expiresAt,
                'updated_at' => time(),
            ]);

            if ($superseded instanceof Memory) {
                $superseded->setFields(['status' => 'superseded', 'updated_at' => time()]);
                $superseded->save();
            }

            $event = $this->emit('memory.stored', [
                'memory_id' => $memory->id,
                'tier' => $tier,
                'source_event_id' => $sourceEventId,
                'source_memory_id' => $sourceMemoryId,
                'supersedes_id' => $supersedesId,
            ]);

            return ['memory' => $memory->getData(), 'event' => $event->getData()];
        });
    }

    public function consolidateMemory(int $episodeId, string $semanticContent, float $confidence): array
    {
        $episode = $this->requireMemory($episodeId);
        if ($episode->tier !== 'episodic') {
            throw new RuntimeException('Only episodic memories can be consolidated by this command.');
        }

        return $this->addMemory(
            tier: 'semantic',
            content: $semanticContent,
            confidence: $confidence,
            sourceEventId: $episode->source_event_id,
            sourceMemoryId: $episodeId
        );
    }

    /**
     * Queue one interleaved replay of episodes into a durable claim.
     *
     * Sleep audited and repaired but never compressed, and consolidateMemory
     * was reachable only by hand from the command line, so episodes piled up
     * for days and almost nothing became knowledge. That is the middle of the
     * pipeline: what gets said becomes an episode, sleep turns episodes into
     * something general, and only the general form is any use to a later
     * decision. Without this pass the executive had a diary and no knowledge.
     *
     * The batch is built the way McClelland, McNaughton and O'Reilly (1995)
     * argue it has to be. Their point is not that consolidation summarises; it
     * is that the direction of change must be "governed not by the particular
     * characteristics of individual associations but by the shared structure
     * common to the environment from which these individual associations are
     * sampled". A batch of only the newest episodes is the failure case they
     * describe: it is one correlated sample from one recent hour, so whatever
     * is peculiar to that hour gets written down as though it were structure.
     *
     * So the replay set interleaves three things: what is new, a sample of
     * older episodes it must be reconciled against, and the existing claims on
     * the same subject. The last of those is what makes consolidation gradual
     * rather than additive — an existing claim is revised and superseded, not
     * left standing beside a new one that half contradicts it.
     *
     * The work is queued rather than run here. Sleep must not block on a model.
     *
     * @return array<string, mixed>|null
     */
    private function enqueueConsolidation(?int $runId, int $now): ?array
    {
        $consolidated = [];
        foreach (Memory::getAllByWhere(['tier' => 'semantic'], ['limit' => 500]) as $semantic) {
            if ($semantic->source_memory_id !== null) {
                $consolidated[(int) $semantic->source_memory_id] = true;
            }
        }

        $episodes = Memory::getAllByWhere(
            ['tier' => 'episodic', 'status' => 'active'],
            ['order' => ['id' => 'DESC'], 'limit' => 400]
        );
        // Only evidence is replayed. Navi's own speech and inner monologue are
        // records of output, not observations of anything, and generalising
        // from them is a closed loop: a line gets said, becomes an episode,
        // consolidates into a claim about Navi's own internals, and that claim
        // then feeds the next line. Left unfiltered it produced confident
        // inventions about mechanisms that do not exist in this codebase,
        // because seventy seven per cent of the episode store is Navi talking.
        // What the user said, what an action actually did, and what a session
        // actually contained are samples of the world; the rest is an echo.
        $episodes = array_values(array_filter(
            $episodes,
            fn (Memory $episode): bool => $this->isEvidence((string) $episode->content)
        ));

        $fresh = [];
        foreach ($episodes as $episode) {
            if (!isset($consolidated[(int) $episode->id])) {
                $fresh[] = $episode;
            }
        }
        if ($fresh === []) {
            return null;
        }
        $fresh = array_slice($fresh, 0, self::CONSOLIDATION_NEW);

        // Everything else is interleaving material, whether or not it has been
        // integrated before. Reinstating an episode again is not waste: it is
        // the mechanism, and restricting the pool to already-consolidated rows
        // would make the sample small and correlated in a different way.
        $chosen = array_flip(array_map(static fn (Memory $m): int => (int) $m->id, $fresh));
        $older = array_values(array_filter(
            $episodes,
            static fn (Memory $m): bool => !isset($chosen[(int) $m->id])
        ));
        // Spread the sample across the whole history rather than taking the
        // rows adjacent to the new material, which would still be one
        // correlated stretch of the same afternoon.
        shuffle($older);
        $older = array_slice($older, 0, self::CONSOLIDATION_INTERLEAVED);
        if (count($fresh) + count($older) < 2) {
            return null;
        }

        $subject = implode(' ', array_map(
            static fn (Memory $m): string => (string) $m->content,
            array_slice($fresh, 0, 3)
        ));
        $existing = [];
        $supersedes = null;
        foreach ($this->searchMemory($subject, 6) as $candidate) {
            if (($candidate['tier'] ?? null) !== 'semantic') {
                continue;
            }
            $existing[] = ['id' => (int) $candidate['id'], 'claim' => (string) $candidate['content']];
            $supersedes ??= (int) $candidate['id'];
            if (count($existing) >= self::CONSOLIDATION_EXISTING) {
                break;
            }
        }

        $composition = new ExecutiveComposition(
            'Navi is asleep. Replaying a mix of new and older episodes to find what holds across all of them.'
        );
        $composition->contribute(
            'replay',
            implode(' ', [
                'These are deliberately mixed: some are recent, some are old.',
                'State what is true across the sample as a whole.',
                'Anything that is only true of the newest ones is an accident of when they happened, not something to keep.',
                'Write about the subject, not about Navi and not about the act of remembering.',
                'If the sample supports nothing worth keeping, say so plainly and give it low confidence.',
            ]),
            array_map(
                static fn (Memory $episode): array => [
                    'id' => (int) $episode->id,
                    'episode' => mb_substr((string) $episode->content, 0, 300),
                ],
                array_merge($fresh, $older)
            )
        );
        if ($existing !== []) {
            $composition->contribute(
                'existing_knowledge',
                implode(' ', [
                    'Navi already believes this about the same subject.',
                    'Revise it in light of the replay rather than restating it or contradicting it outright.',
                    'A small correction that keeps what still holds is worth more than a fresh claim.',
                ]),
                $existing
            );
        }

        // Every work type owes the worker its output contract. This prompt was
        // shipped without one, so models returned a sensible claim and no
        // challenged_assumption, the validator rejected it, and three working
        // endpoints were marked degraded for failing to guess a field nobody
        // had asked them for.
        $prompt = $composition->prompt() . "\n\n" . implode("\n", [
            'Return kind memory_consolidation and content holding the claim itself.',
            'Put the confidence you actually have in it in confidence.',
            'Put the belief this replay called into question in challenged_assumption; '
                . 'if the replay confirmed what was already believed, say that there instead.',
            'Output exactly the four JSON fields in the supplied schema and nothing else.',
        ]);

        return $this->enqueueWork(
            parentRunId: $runId,
            parentIntentionId: null,
            workType: self::CONSOLIDATION_WORK_TYPE,
            prompt: $prompt,
            inputRefs: [
                'episode_ids' => array_map(static fn (Memory $m): int => (int) $m->id, $fresh),
                'interleaved_ids' => array_map(static fn (Memory $m): int => (int) $m->id, $older),
                'primary_episode_id' => (int) $fresh[0]->id,
                'supersedes_memory_id' => $supersedes,
            ],
            tokenBudget: 512,
            wallBudgetSeconds: 300,
            idempotencyKey: 'consolidate:' . $fresh[0]->id . ':' . count($fresh)
        );
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
        ?int $procedureStep = null
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
            $procedureStep
        ): array {
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
            return ['event' => $event->getData()];
        });
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
        foreach (Memory::getAllByWhere(['tier' => 'semantic'], ['limit' => 500]) as $semantic) {
            if ($semantic->source_memory_id !== null) {
                $consolidated[(int) $semantic->source_memory_id] = true;
            }
        }
        $unconsolidated = 0;
        foreach (Memory::getAllByWhere(
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
        foreach (Memory::getAllByWhere(
            ['status' => 'active'],
            ['order' => ['id' => 'DESC'], 'limit' => 200]
        ) as $memory) {
            if (!str_contains((string) $memory->content, 'this machine')) {
                continue;
            }
            $learned[] = mb_substr((string) $memory->content, 0, 260);
            if (count($learned) >= 8) {
                break;
            }
        }

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

        $event = $this->emit('look.observed', [
            'command' => $command,
            'because' => $because,
            'worked' => $worked,
            'refused' => $refused,
            'exit_code' => $exit,
        ]);

        $memory = $this->addMemory(
            tier: 'episodic',
            content: $content,
            // A look that failed is as true a record as one that worked, and
            // knowing what does not work here is most of what stops the same
            // dead end being tried again.
            confidence: $worked ? 0.9 : 0.8,
            sourceEventId: (int) $event->id
        );
        $procedure = $requestEventId === null
            ? null
            : $this->proceduralMemory->completePendingLook($requestEventId, $reading);
        return $memory + ['procedure_completion' => $procedure];
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
        $answered = [];
        foreach (SenseReading::getAllByWhere(
            ['source_key' => 'machine_inspection'],
            ['order' => ['id' => 'DESC'], 'limit' => 200]
        ) as $reading) {
            $payload = is_array($reading->payload) ? $reading->payload : [];
            $command = (string) ($payload['command'] ?? '');
            // A look that could not happen was not answered. Counting a refusal
            // as an answer meant a question asked while the service was down
            // could never be asked again, which is the opposite of what a
            // refusal means: the service was unavailable, not the question.
            if ($command !== '' && ($payload['refused'] ?? false) !== true) {
                $answered[$command] = true;
            }
        }

        $pending = [];
        foreach (Event::getAllByWhere(
            ['kind' => 'look.requested'],
            ['order' => ['id' => 'DESC'], 'limit' => 40]
        ) as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $command = (string) ($payload['command'] ?? '');
            if ($command === '' || isset($answered[$command])) {
                continue;
            }
            $answered[$command] = true;
            $pending[] = [
                'command' => $command,
                'because' => (string) ($payload['because'] ?? ''),
                'event_id' => (int) $event->id,
                'action_id' => isset($payload['action_id']) ? (int) $payload['action_id'] : null,
                'procedure_run_id' => isset($payload['procedure_run_id'])
                    ? (int) $payload['procedure_run_id']
                    : null,
                'procedure_step' => isset($payload['procedure_step'])
                    ? (int) $payload['procedure_step']
                    : null,
            ];
            if (count($pending) >= max(1, $limit)) {
                break;
            }
        }
        return array_reverse($pending);
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
        $episodeId = (int) ($refs['primary_episode_id'] ?? 0);
        $content = trim((string) ($proposal['content'] ?? ''));
        $confidence = is_numeric($proposal['confidence'] ?? null) ? (float) $proposal['confidence'] : 0.0;

        if ($episodeId === 0 || $content === '') {
            return ['status' => 'rejected', 'reason' => 'empty_consolidation'];
        }
        // A claim the pass itself does not believe is not knowledge. Storing it
        // anyway is how a memory store fills with things nothing will act on.
        if ($confidence < self::CONSOLIDATION_CONFIDENCE_FLOOR) {
            return ['status' => 'rejected', 'reason' => 'below_confidence_floor', 'confidence' => $confidence];
        }

        // Supersede rather than append when the replay revised something Navi
        // already believed. Two claims about one subject sitting side by side
        // is the additive failure: recall returns both, they disagree at the
        // edges, and nothing ever decides between them.
        $supersedes = isset($refs['supersedes_memory_id']) ? (int) $refs['supersedes_memory_id'] : null;
        $episode = $this->requireMemory($episodeId);
        if ($episode->tier !== 'episodic') {
            return ['status' => 'rejected', 'reason' => 'primary_is_not_an_episode'];
        }
        $stored = $this->addMemory(
            tier: 'semantic',
            content: $content,
            confidence: $confidence,
            sourceEventId: $episode->source_event_id,
            sourceMemoryId: $episodeId,
            supersedesId: $supersedes
        );

        $this->emit('memory.consolidated', [
            'work_item_id' => (int) ($work['id'] ?? 0),
            'episode_ids' => $refs['episode_ids'] ?? [],
            'interleaved_ids' => $refs['interleaved_ids'] ?? [],
            'supersedes_memory_id' => $supersedes,
            'memory_id' => $stored['memory']['id'] ?? null,
            'confidence' => $confidence,
            'model' => $model,
        ]);
        return ['status' => 'consolidated', 'memory' => $stored['memory'] ?? null];
    }

    /** @return list<array<string, mixed>> */
    public function searchMemory(string $query, int $limit = 20): array
    {
        $this->requireText($query, 'query');
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }

        $this->workingMemory->expireStale();
        $normalizedQuery = $this->normalizeSearchText($query);
        $terms = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $normalizedQuery) ?: [],
            static fn (string $term): bool => $term !== ''
        )));

        $ranked = [];
        foreach (Memory::getAllByWhere(['status' => 'active']) as $memory) {
            $content = $this->normalizeSearchText((string) $memory->content);
            $matchedTerms = 0;
            $occurrences = 0;

            foreach ($terms as $term) {
                $count = substr_count($content, $term);
                if ($count > 0) {
                    $matchedTerms++;
                    $occurrences += $count;
                }
            }

            $phraseMatch = $normalizedQuery !== '' && str_contains($content, $normalizedQuery);
            if (!$phraseMatch && $matchedTerms === 0) {
                continue;
            }

            $coverage = $terms === [] ? 0.0 : $matchedTerms / count($terms);
            $tierWeight = match ($memory->tier) {
                'working' => 4,
                'semantic' => 3,
                'episodic' => 2,
                'procedural' => 3,
                default => 1,
            };

            $ranked[] = [
                'memory' => $memory,
                'score' => ($phraseMatch ? 1000 : 0)
                    + ($coverage * 100)
                    + ($matchedTerms * 10)
                    + min($occurrences, 10)
                    + $tierWeight,
                'updated_at' => $this->timestamp($memory->updated_at) ?? 0,
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score']
                ?: $right['updated_at'] <=> $left['updated_at'];
        });

        return array_values(array_map(
            static fn (array $entry): array => $entry['memory']->getData(),
            array_slice($ranked, 0, $limit)
        ));
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
            $memories = array_values(array_filter(
                Memory::getAllByWhere(['status' => 'active']),
                static fn (Memory $memory): bool => in_array($memory->tier, ['semantic', 'episodic'], true)
            ));
            shuffle($memories);
            $memories = array_slice($memories, 0, self::DAYDREAM_MEMORY_LIMIT);

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
            $nonRem = $this->transaction(function (): array {
                $expired = [];
                $now = time();
                foreach (Memory::getAllByWhere(['tier' => 'working', 'status' => 'active']) as $memory) {
                    $expiresAt = $this->timestamp($memory->expires_at);
                    if ($expiresAt === null || $expiresAt > $now) {
                        continue;
                    }
                    $memory->setFields(['status' => 'expired', 'updated_at' => $now]);
                    $memory->save();
                    $event = $this->emit('memory.expired', [
                        'memory_id' => $memory->id,
                        'expires_at' => $expiresAt,
                        'repair_kind' => 'explicit_expiry',
                    ]);
                    $expired[] = ['memory_id' => $memory->id, 'event_id' => $event->id];
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

            return [
                'state' => 'sleeping',
                'reason' => $reason,
                'before_checkpoint' => $before,
                'non_rem' => $nonRem,
                'rem' => [
                    'repair_artifacts' => $repairArtifacts,
                    'wandering' => $wandering,
                ],
                'after_checkpoint' => $after,
                'event' => $completed->getData(),
                'checkpoint_kind' => 'audit_snapshot_not_automatic_restore',
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

        $integrity = $this->quickCheck();
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
        $reconciled = $this->reconcileCognitiveThreadResults();
        $indeterminate = $this->recoverIndeterminateDispatches();
        $now = time();

        $thread = $this->transaction(function () use ($now): ?CognitiveThread {
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
            return $this->requireCognitiveThread((int) $id);
        });

        if (!$thread instanceof CognitiveThread) {
            return [
                'status' => 'idle',
                'node_id' => $nodeId,
                'reconciled' => $reconciled,
                'indeterminate_dispatches' => $indeterminate,
            ];
        }

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

        try {
            if ($thread->thread_key !== self::SELF_PRESENCE_THREAD_KEY
                && $thread->thread_key !== self::EPISTEMIC_ADVANCE_THREAD_KEY
                && $thread->thread_key !== self::MIND_STREAM_THREAD_KEY
            ) {
                return $this->recordThreadWait(
                    $thread,
                    $step,
                    'unsupported_thread_type',
                    $now + 3600,
                    'No bounded executor is registered for this thread key.'
                );
            }

            $earliestDispatch = $this->earliestWorkerDispatchAt($thread, $now);
            if ($earliestDispatch > $now) {
                return $this->recordThreadWait(
                    $thread,
                    $step,
                    'worker_rate_limited',
                    $earliestDispatch,
                    sprintf(
                        'The previous model dispatch for this thread was under %d seconds ago. Waiting keeps a short poll interval from driving the model continuously.',
                        $this->threadBudgetInt($thread, 'min_worker_interval_seconds', self::MIN_WORKER_INTERVAL_SECONDS)
                    )
                );
            }

            if ($thread->thread_key === self::MIND_STREAM_THREAD_KEY) {
                return array_merge(
                    ['node_id' => $nodeId, 'reconciled' => $reconciled],
                    $this->evaluateMindStreamThread($thread, $step, $now)
                );
            }

            if ($thread->thread_key === self::EPISTEMIC_ADVANCE_THREAD_KEY) {
                return array_merge(
                    ['node_id' => $nodeId, 'reconciled' => $reconciled],
                    $this->evaluateEpistemicAdvanceThread($thread, $step, $now)
                );
            }

            return array_merge(
                ['node_id' => $nodeId, 'reconciled' => $reconciled],
                $this->evaluateSelfPresenceThread($thread, $step, $now)
            );
        } catch (Throwable $throwable) {
            return $this->recordThreadWait(
                $thread,
                $step,
                'evaluation_error',
                time() + 900,
                $throwable->getMessage(),
                true
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
        $workingContext = $this->workingMemory->contextForWork($parentIntentionId, $inputRefs);
        if ($workingContext !== []) {
            $workingJson = json_encode(
                $workingContext,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $prompt .= "\n\nActive working memory (bounded typed state; claims retain their labels and provenance): "
                . $workingJson;
            $inputRefs['working_memory_checksum'] = hash('sha256', $workingJson);
            $inputRefs['working_memory_roles'] = array_values(array_map(
                static fn (array $slot): string => (string) ($slot['slot_role'] ?? ''),
                $workingContext
            ));
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

    public function claimWork(string $owner, int $leaseSeconds = 180): ?array
    {
        $this->requireText($owner, 'worker owner');
        if ($leaseSeconds < 10 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('work lease must be between 10 and 3600 seconds.');
        }

        return $this->transaction(function () use ($owner, $leaseSeconds): ?array {
            $now = time();
            $this->recoverExpiredWorkLeases($now);
            $statement = $this->connection->prepare(
                "UPDATE work_items
                 SET status = 'leased', lease_owner = :owner,
                     lease_expires_at = :lease_expires,
                     fencing_token = fencing_token + 1,
                     attempts = attempts + 1, updated_at = :now, error = NULL
                 WHERE id = (
                     SELECT id FROM work_items
                     WHERE status = 'queued'
                     ORDER BY CASE work_type
                         WHEN 'self_presence_answer' THEN 0
                         ELSE 1
                     END, created_at ASC LIMIT 1
                 ) AND status = 'queued'
                 RETURNING id"
            );
            $statement->execute([
                ':owner' => $owner,
                ':lease_expires' => date('Y-m-d H:i:s', $now + $leaseSeconds),
                ':now' => date('Y-m-d H:i:s', $now),
            ]);
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

        return $this->transaction(function () use (
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
            if ($succeeded) {
                $this->validateWorkerProposal($result);
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
                // Parser and social-label proposals stay in their dedicated
                // curators. Neither is a general reasoning result.
                if (!in_array($work->work_type, [
                    OtherModel::WORK_TYPE,
                    SocialFeedback::WORK_TYPE,
                ], true)) {
                    $this->workingMemory->publish(
                        role: 'reasoning_result',
                        claim: sprintf(
                            'Uncurated %s proposal: %s',
                            (string) $result['kind'],
                            (string) $result['content']
                        ),
                        recordType: 'thought_artifact',
                        recordId: $artifact instanceof ThoughtArtifact ? (int) $artifact->id : null,
                        confidence: (float) $result['confidence'],
                        ttlSeconds: 900
                    );
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

            return [
                'work_item' => $work->getData(),
                'artifact' => $artifact?->getData(),
                'event' => $event->getData(),
            ];
        });
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
        if (($work['work_type'] ?? null) === self::CONSOLIDATION_WORK_TYPE) {
            return $this->integrateConsolidation($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === self::LOOK_WORK_TYPE) {
            return $this->integrateLookProposal($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === self::COMPLETION_WORK_TYPE) {
            return $this->integrateCompletionCheck($work, $proposal, $model);
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

        return $this->transaction(function () use (
            $threadId,
            $stepId,
            $workId,
            $normalized,
            $speechResult,
            $model,
            $work
        ): array {
            $currentThread = $this->requireCognitiveThread($threadId);
            $currentStep = $this->requireThreadStep($stepId);
            if ($currentStep->status !== 'dispatching') {
                return ['status' => 'dispatch_already_finalized', 'thread_step' => $currentStep->getData()];
            }
            $now = time();
            $nextWake = $now + $this->threadBudgetInt($currentThread, 'poll_seconds', 3600);
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
            $memory = $this->rememberUtterance(
                channel: 'spoke_aloud',
                content: (string) $normalized['content'],
                confidence: 0.9,
                sourceEventId: (int) $event->id,
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

            return [
                'status' => 'spoken',
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'satisfaction' => $satisfaction,
                'event' => $event->getData(),
                'memory' => $memory,
            ];
        });
    }

    /** @param array<string, mixed> $work
     *  @return array<string, mixed>
     */
    public function handleWorkerFailure(array $work, string $error): array
    {
        $workType = $work['work_type'] ?? null;
        if ($workType === OtherModel::WORK_TYPE) {
            return $this->otherModel->failParserWork($work, $error);
        }
        if ($workType === SocialFeedback::WORK_TYPE) {
            return $this->socialFeedback()->failReflection($work, $error);
        }
        if ($workType === DecisionStateMachine::WORK_TYPE) {
            return $this->decisionStateMachine->failWork($work, $error);
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
            $nextWake = $addressed
                ? $now
                : $now + $this->threadBudgetInt($thread, 'poll_seconds', 3600);
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
                'last_observation' => 'The bounded speech-judgment worker failed; the thread returned to safe sleep.',
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
        ), static fn (string $model): bool => $model !== '' && str_ends_with($model, '-free'))));

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
                    $endpoint->setFields(['last_discovered_at' => $now, 'updated_at' => $now]);
                    $endpoint->save();
                }
            }

            foreach (ModelEndpoint::getAll() as $endpoint) {
                // The local endpoint is not in the remote catalogue and must not
                // be swept away by remote discovery; it is the brain's baseline.
                if ($endpoint->provider === self::LOCAL_MODEL_PROVIDER) {
                    continue;
                }
                if (!in_array($endpoint->model_id, $normalized, true)) {
                    $endpoint->setFields([
                        'status' => 'unavailable',
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
                if ($endpoint->provider === self::LOCAL_MODEL_PROVIDER) {
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

    /**
     * Ensure the always-present local endpoint exists so its health history is
     * recorded next to the remote pool rather than in a separate ledger.
     */
    public function registerLocalModel(string $modelId): array
    {
        $this->requireText($modelId, 'model id');
        return $this->transaction(function () use ($modelId): array {
            $endpoint = ModelEndpoint::getByField('model_id', $modelId);
            $now = time();
            if ($endpoint instanceof ModelEndpoint) {
                $endpoint->setFields(['last_discovered_at' => $now, 'updated_at' => $now]);
                $endpoint->save();
                return $endpoint->getData();
            }
            /** @var ModelEndpoint $endpoint */
            $endpoint = $this->insert(ModelEndpoint::class, [
                'model_id' => $modelId,
                'provider' => self::LOCAL_MODEL_PROVIDER,
                'status' => 'discovered',
                'last_discovered_at' => $now,
                'consecutive_failures' => 0,
                'updated_at' => $now,
            ]);
            $this->emit('models.local.registered', [
                'model_id' => $modelId,
                'provider' => self::LOCAL_MODEL_PROVIDER,
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
            throw new RuntimeException(sprintf('Model %s is not in the discovered free-model pool.', $modelId));
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
        return $this->records(SelfModelFact::getAll(['order' => ['fact_key' => 'ASC']]));
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
            'active_intentions' => $this->records(Intention::getAllByWhere(
                ['status' => 'active'],
                ['order' => ['updated_at' => 'DESC']]
            )),
            'blocked_intentions' => $this->records(Intention::getAllByWhere(
                ['status' => 'blocked'],
                ['order' => ['updated_at' => 'DESC']]
            )),
            'pending_actions' => $this->records(ActionTrace::getAllByWhere(
                ['status' => 'pending'],
                ['order' => ['created_at' => 'DESC']]
            )),
            'recent_discrepancies' => $this->records(ActionTrace::getAllByWhere(
                ['match_status' => 'mismatched'],
                ['order' => ['completed_at' => 'DESC'], 'limit' => 10]
            )),
            'working_memory' => $this->records(Memory::getAllByWhere(
                ['tier' => 'working', 'status' => 'active'],
                ['order' => ['updated_at' => 'DESC'], 'limit' => 25]
            )),
            'working_memory_slots' => $this->records(\NaviBrain\Model\WorkingMemorySlot::getAllByWhere(
                ['status' => 'active'],
                ['order' => ['updated_at' => 'DESC'], 'limit' => 50]
            )),
            'semantic_memory' => $this->records(Memory::getAllByWhere(
                ['tier' => 'semantic', 'status' => 'active'],
                ['order' => ['updated_at' => 'DESC'], 'limit' => 25]
            )),
            'procedural_memory' => $this->records(Memory::getAllByWhere(
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
        $snapshot = $this->status();
        /** @var Checkpoint $checkpoint */
        $checkpoint = $this->insert(Checkpoint::class, [
            'reason' => $reason,
            'snapshot' => $snapshot,
        ]);

        return $checkpoint->getData();
    }

    private function emit(string $kind, array $payload): Event
    {
        /** @var Event $event */
        $event = $this->insert(Event::class, ['kind' => $kind, 'payload' => $payload]);
        return $event;
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
        $integrity = $this->quickCheck();
        $now = time();
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
            'decide_1m' => ['tokens' => 768, 'wall_seconds' => 300, 'max_workers' => 1],
            'reflect_5m' => ['tokens' => 512, 'wall_seconds' => 120, 'max_workers' => 1],
            'consolidate_hourly' => ['tokens' => 768, 'wall_seconds' => 180, 'max_workers' => 1],
            'sleep_daily' => ['tokens' => 1024, 'wall_seconds' => 300, 'max_workers' => 1],
            default => ['tokens' => 256, 'wall_seconds' => 120, 'max_workers' => 1],
        };
    }

    private function eventWatermark(): int
    {
        $value = $this->connection->query('SELECT COALESCE(MAX(id), 0) FROM events')?->fetchColumn();
        return $value === false ? 0 : (int) $value;
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

    /** @return array{path: string, sha256: string, bytes: int, quick_check: list<string>} */
    public function backupDatabase(string $reason): array
    {
        $this->requireText($reason, 'backup reason');
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
            '%s/%s-%s-%s.sqlite',
            $directory,
            $stem,
            gmdate('Ymd-His'),
            bin2hex(random_bytes(3))
        );
        $backupConnection = new PDO('sqlite:' . $source);
        $backupConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $backupConnection->exec('PRAGMA busy_timeout = 5000');
        $quotedTarget = $backupConnection->quote($target);
        if (!is_string($quotedTarget)) {
            throw new RuntimeException('Unable to quote the SQLite backup path.');
        }
        $backupConnection->exec('VACUUM INTO ' . $quotedTarget);
        chmod($target, 0600);

        $backup = new PDO('sqlite:' . $target);
        $check = array_values(array_map(
            'strval',
            $backup->query('PRAGMA quick_check')?->fetchAll(PDO::FETCH_COLUMN) ?: []
        ));
        if ($check !== ['ok']) {
            throw new RuntimeException('New SQLite backup failed quick_check: ' . implode('; ', $check));
        }

        $result = [
            'path' => $target,
            'sha256' => hash_file('sha256', $target),
            'bytes' => filesize($target),
            'quick_check' => $check,
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

        // Presence is the real signal, not the clock. If the user is at the machine
        // Navi knows it and this is an occasion; if the user is gone there is nothing
        // to interact with and the stream should have the compute instead.
        $present = $this->presenceNow();
        if ($present === false && $addressed === []) {
            // Nothing to say to an empty room, but this is the useful half of
            // being alone: pick up the track now so the compute the stream is
            // about to spend goes somewhere, and so the user returning finds Navi
            // already holding a specific thing rather than starting to wonder
            // what to say. Speaking still waits for the user.
            $picked = $this->heldFocus($thread, $now);
            return $this->recordThreadWait(
                $thread,
                $step,
                'user_absent',
                $now + $this->threadBudgetInt($thread, 'absent_poll_seconds', 300),
                $picked === null
                    ? 'The user is not at the machine. Nothing to interact with, so this spends no worker call and the stream keeps the compute.'
                    : sprintf(
                        'The user is not at the machine, so nothing is said. Holding "%s" to take further while the user is out and to raise when the user is back.',
                        $picked['following']
                    )
            );
        }
        // Only fall back to a schedule when the presence sense cannot answer.
        if ($addressed === []
            && $present === null
            && $this->withinQuietHours($thread, $now)
        ) {
            return $this->recordThreadWait(
                $thread,
                $step,
                'quiet_hours_without_presence',
                $this->nextQuietEnd($thread, $now),
                'The presence sense is unavailable, so quiet hours stand in for it.'
            );
        }

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
            'Bounded workspace (every slot you may reason from): ' . json_encode(
                $evidence,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'Recent accepted refinements: ' . json_encode(
                $recent,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
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
     * One tick of the waking stream.
     *
     * Sleep is a real state, not a low duty cycle: while asleep the stream does
     * not think at all and simply sets its wake for morning.
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

        // Evidence outranks the clock. If the user is here, the hour is irrelevant;
        // sleeping through someone talking to Navi was the whole failure the
        // presence senses exist to fix. The schedule only governs when the
        // senses have nothing to say.
        $presence = $this->presenceEstimate();
        $unattendedEdges = count($this->sensoryCortex()->pendingEvents(5, 0.6));
        if ($presence['present'] !== true
            && $unattendedEdges === 0
            && $this->withinQuietHours($thread, $now)
        ) {
            return $this->recordThreadWait(
                $thread,
                $step,
                'asleep',
                min(
                    $this->nextQuietEnd($thread, $now),
                    $now + $this->threadBudgetInt($thread, 'sleep_recheck_seconds', 600)
                ),
                'Asleep: no evidence of anyone here and nothing significant unattended.'
            );
        }

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
            'Most lines stay private. Occasionally a line may be murmured aloud as self-talk, so still write as addressing yourself, never the user.',
            'Do not act, do not request tools, and do not invent observations outside the workspace.',
            'Return exactly one JSON object with the exact keys kind, content, confidence, and challenged_assumption.',
            'kind must be exactly "thought".',
            'content is one bounded sentence of first-person self-talk grounded in the workspace below.',
            'Write as if continuing a conversation with yourself: you may use "I", "okay", "wait", "hold on", questions to yourself, or correcting your own prior line.',
            'Prefer what just changed over restating a standing fact.',
            'If safety_notice is filled, address that interrupt before anything else.',
            'If newest_edge or heard_focus is heard_speech, stay with that spoken change instead of an unchanged constraint.',
            'Do not claim to be conscious, sentient, alive, or a person. Do not claim you ran tools or changed the world.',
            'Do not address Aku by name and do not ask the user a question; if you ask, ask yourself.',
            'confidence is a number from 0 through 1 reflecting how well the workspace supports this line.',
            'challenged_assumption names what this line of self-talk puts in question.',
            'Your recent inner monologue, newest first (continue from yourself; do not restate the latest line): ' . json_encode(
                $recentMonologue,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'Workspace: ' . json_encode(
                $workspace,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
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
                'Heard, oldest to newest: ' . json_encode(
                    $heard,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
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
        return 'Said to Navi just now and not yet answered, newest first: ' . json_encode(
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
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . '. This is a person waiting, so answering it comes before anything else Navi might say.';
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
        $idle = $this->threadBudgetInt($thread, 'idle_interval_seconds', 900);
        $floor = max(60, $this->threadBudgetInt($thread, 'min_interval_seconds', 90));
        $budget = is_array($thread->budget) ? $thread->budget : [];
        $gain = is_numeric($budget['salience_gain'] ?? null) ? (float) $budget['salience_gain'] : 3.0;

        $salience = 0.0;
        foreach ($this->sensoryCortex()->pendingEvents(10) as $event) {
            $salience += (float) ($event['significance'] ?? 0.0);
        }

        // When the user is here, attention belongs to the user and the stream drifts.
        // When the user is gone, this is the thread that gets to think.
        if ($this->presenceNow() === true) {
            $idle = (int) round($idle * 2.0);

            // There is one model slot, so thinking and answering compete for
            // it. Mid-conversation the stream stands down entirely: a thought
            // occupying the slot delays a reply by a whole generation, and a
            // reply that arrives a minute late is indistinguishable from being
            // ignored. Attention is singular here in the same way it is in a
            // person.
            foreach ($this->sensoryCortex()->pendingEvents(5, 0.6) as $event) {
                $observedAt = $this->timestamp($event['observed_at'] ?? null);
                if ($observedAt !== null && (time() - $observedAt) <= 180) {
                    return max($idle, $this->threadBudgetInt($thread, 'yield_interval_seconds', 300));
                }
            }
        }

        if ($salience <= 0.0) {
            return $idle;
        }
        return (int) max($floor, min($idle, round($idle / (1.0 + ($salience * $gain)))));
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
     * An accepted monologue line becomes a thought artifact and an episodic
     * memory. Occasional murmurs and self-presence speech also write episodic
     * memories. The stream's next wake is computed from salience.
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
            ['order' => ['id' => 'DESC'], 'limit' => 8]
        ) as $prior) {
            $priorProposal = is_array($prior->proposal) ? $prior->proposal : [];
            if (is_string($priorProposal['content'] ?? null)) {
                $priorThoughts[] = (string) $priorProposal['content'];
            }
        }
        $validation = $this->validateMindStreamMonologue($proposal, $thread, $priorThoughts);

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
        $consumed = is_array($refs['consumed_edges'] ?? null) ? $refs['consumed_edges'] : [];

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

            $hash = hash('sha256', 'monologue|' . $stepId . '|' . $normalized['content']);
            $artifact = ThoughtArtifact::getByField('content_hash', $hash);
            if (!$artifact instanceof ThoughtArtifact) {
                /** @var ThoughtArtifact $artifact */
                $artifact = $this->insert(ThoughtArtifact::class, [
                    'run_id' => null,
                    'kind' => 'inner_monologue',
                    'content' => (string) $normalized['content'],
                    'confidence' => (float) $normalized['confidence'],
                    'provenance' => 'worker',
                    'source_ids' => [
                        'work_item_id' => $workId,
                        'thread_step_id' => $stepId,
                        'consumed_edges' => $consumed,
                        'mode' => 'inner_monologue',
                    ],
                    'status' => 'accepted',
                    'content_hash' => $hash,
                ]);
            }

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
            $this->markWorkArtifact($workId, 'accepted');

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
            $memory = $this->rememberUtterance(
                channel: 'inner_monologue',
                content: (string) $normalized['content'],
                confidence: (float) $normalized['confidence'],
                sourceEventId: (int) $event->id,
                refs: [
                    'thread_id' => $threadId,
                    'thread_step_id' => $stepId,
                    'thought_artifact_id' => (int) $artifact->id,
                    'consumed_edges' => $consumed,
                ]
            );

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
                'memory' => $memory,
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

        return $this->transaction(function () use (
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
            $memory = $this->rememberUtterance(
                channel: 'spoke_to_herself',
                content: $content,
                confidence: 0.9,
                sourceEventId: (int) $event->id,
                refs: [
                    'thread_id' => $threadId,
                    'thread_step_id' => $stepId,
                    'thought_artifact_id' => $artifactId,
                ]
            );

            return [
                'spoken' => true,
                'reason' => 'murmured',
                'roll' => $roll,
                'chance' => $effectiveChance,
                'response' => $speechResult,
                'event' => $event->getData(),
                'memory' => $memory,
            ];
        });
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
        return $this->transaction(fn (): array => $this->rememberUtterance(
            channel: 'heard_from_user',
            content: $text,
            confidence: 0.85,
            sourceEventId: $sourceEventId,
            refs: $refs
        ));
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
     * @param list<string> $priorClaims
     * @return array{accepted: bool, reason: string, proposal: array<string, mixed>, checks: array<string, bool>}
     */
    private function validateMindStreamMonologue(
        array $proposal,
        CognitiveThread $thread,
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

        $forbidden = '/\b(?:i (?:ran|executed|opened|installed|edited|wrote to|deleted|contacted|browsed|searched the web)|i am (?:conscious|sentient|alive|a person|human)|i\'m (?:conscious|sentient|alive|a person|human)|my consciousness|according to (?:the internet|my training)|https?:\/\/)\b/iu';
        $novel = true;
        foreach (array_merge($priorClaims, [(string) $thread->current_belief]) as $priorClaim) {
            if ($this->normalizeSearchText($priorClaim) === $this->normalizeSearchText($content)) {
                $novel = false;
                break;
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
        return $this->transaction(function () use (
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
            if ($currentStep->status !== 'running'
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

            $event = $this->emit('thread.refinement.accepted', [
                'thread_id' => $currentThread->id,
                'thread_step_id' => $currentStep->id,
                'work_item_id' => $workId,
                'operation' => $operation,
                'confidence' => $confidence,
                'prior_uncertainty' => $priorUncertainty,
                'uncertainty' => $uncertainty,
                'next_operation' => $nextOperation,
                'next_wake_at' => $nextWake,
                'model' => $model,
            ]);

            // A reflect step is the only operation that rewrites the belief, so
            // it is the only one that earns a durable semantic memory.
            $memory = null;
            if ($operation === 'reflect') {
                $memory = $this->addMemory(
                    tier: 'semantic',
                    content: $claim,
                    confidence: $confidence,
                    sourceEventId: (int) $event->id
                );
            }

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
            $nextWake = $now + $this->threadBudgetInt($currentThread, 'poll_seconds', 1800);
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
                'stagnation_count' => (int) $currentThread->stagnation_count + 1,
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
            $nextWake = $now + $this->threadBudgetInt($thread, 'poll_seconds', 1800);
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
                'stagnation_count' => (int) $thread->stagnation_count + 1,
                'last_observation' => 'The bounded epistemic worker failed; the thread returned to safe sleep without changing its belief.',
                'updated_at' => $now,
            ]);
            $thread->save();
            $event = $this->emit('thread.worker.failed', [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'work_item_id' => $workId,
                'work_type' => self::EPISTEMIC_ADVANCE_WORK_TYPE,
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

        // Quiet hours are a guess about whether anyone is around. When the
        // senses can answer that directly, the guess is not needed: being here
        // at four in the morning is still being here, and staying silent
        // through it is the schedule overruling the evidence.
        $explicitWakeUntil = $this->timestamp($budget['explicit_wake_until'] ?? null) ?? 0;
        if (!$answerExpected
            && $now > $explicitWakeUntil
            && $this->presenceEstimate()['present'] !== true
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
            $nextWake = $addressed
                ? $now
                : $now + $this->threadBudgetInt($thread, 'poll_seconds', 3600);
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
            $nextWake = $now + $this->threadBudgetInt($thread, 'poll_seconds', 3600);
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
            $nextWake = max($now + 60, (int) ($gate['next_wake_at'] ?? $now + 900));
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
            $nextWake = $now + 900;
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

    private function markWorkArtifact(int $workId, string $status): void
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
            return;
        }
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
    private function validateWorkerProposal(array $result): void
    {
        $required = ['kind', 'content', 'confidence', 'challenged_assumption'];
        $keys = array_keys($result);
        sort($required);
        sort($keys);
        if ($keys !== $required) {
            throw new InvalidArgumentException(
                'Worker proposal must contain exactly kind, content, confidence, and challenged_assumption.'
            );
        }
        foreach (['kind', 'content', 'challenged_assumption'] as $key) {
            if (!is_string($result[$key]) || trim($result[$key]) === '') {
                throw new InvalidArgumentException(sprintf('Worker proposal %s must be non-empty text.', $key));
            }
        }
        if (strlen($result['kind']) > 96 || strlen($result['content']) > 8000) {
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

        foreach (Memory::getAllByWhere(['status' => 'active']) as $memory) {
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
                && !Memory::getByID((int) $memory->source_memory_id) instanceof Memory
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

    private function ensureDefaultRhythms(): void
    {
        $defaults = [
            ['rhythm_key' => 'pulse_30s', 'interval_seconds' => 30, 'layer' => CognitiveLayer::LOW],
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
        if ($modelClass::fieldExists('created_at') && !array_key_exists('created_at', $values)) {
            // Divergence maps timestamps through PHP's timezone. Supplying the
            // epoch avoids reinterpreting SQLite's UTC CURRENT_TIMESTAMP as a
            // local wall-clock value when the record is read back.
            $values['created_at'] = time();
        }

        $record = new $modelClass($values, true, true);
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
        $record = Memory::getByID($id);
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
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->connection->commit();
            }
            return $result;
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $throwable;
        }
    }
}
