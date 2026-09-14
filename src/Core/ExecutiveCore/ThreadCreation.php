<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\Need;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Model\WorkItem;
use RuntimeException;

class ThreadCreation extends Component
{
    public function createSelfPresenceThread(int $intentionId): array
    {
        $intention = $this->requireIntention($intentionId);
        if ($intention->status !== 'active') {
            throw new RuntimeException('A self-presence thread requires an active intention.');
        }
        if ($intention->authority !== 'user') {
            throw new RuntimeException('Local spontaneous speech requires an explicit user-authority intention.');
        }

        if (!Need::getByField('need_key', Executive::SELF_PRESENCE_NEED_KEY) instanceof Need) {
            $this->setNeed(new Need([
                'need_key' => Executive::SELF_PRESENCE_NEED_KEY,
                'description' => 'Maintain a sparse, authentic social connection with the user without manufacturing urgency or demanding attention.',
                'pressure' => 0.0,
                'growth_per_hour' => 0.08,
                'trigger_threshold' => 0.60,
                'status' => 'active',
                'authority' => 'user'
            ], true, true), 'The user explicitly authorized a bounded background self-presence loop with local Pet speech.');
        }

        $existing = CognitiveThread::getByField('thread_key', Executive::SELF_PRESENCE_THREAD_KEY);
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

        $thread = new CognitiveThread([
            'parent_intention_id' => (int) $intention->id,
            'thread_key' => Executive::SELF_PRESENCE_THREAD_KEY,
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
                'need_key' => Executive::SELF_PRESENCE_NEED_KEY,
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
        ], true, true);
        $thread->save();
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

        $existing = CognitiveThread::getByField('thread_key', Executive::EPISTEMIC_ADVANCE_THREAD_KEY);
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

        $thread = new CognitiveThread([
            'parent_intention_id' => (int) $intention->id,
            'thread_key' => Executive::EPISTEMIC_ADVANCE_THREAD_KEY,
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
        ], true, true);
        $thread->save();
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
    }

    public function createMindStreamThread(int $intentionId): array
    {
        $intention = $this->requireIntention($intentionId);
        if ($intention->status !== 'active' || $intention->authority !== 'user') {
            throw new RuntimeException('The mind stream requires an active user-authority intention.');
        }

        $now = time();
        $budget = $this->mindStreamBudget();
        $existing = CognitiveThread::getByField('thread_key', Executive::MIND_STREAM_THREAD_KEY);
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

        $thread = new CognitiveThread([
            'parent_intention_id' => (int) $intention->id,
            'thread_key' => Executive::MIND_STREAM_THREAD_KEY,
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
        ], true, true);
        $thread->save();
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
    }

    public function listInnerMonologue(int $limit = 12): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }
        return $this->recentStreamThoughts($limit);
    }

    public function mindStreamBudget(): array
    {
        return [

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

    public function listCognitiveThreads(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['candidate', 'active', 'waiting', 'blocked', 'complete', 'released'], 'thread status');
            return $this->records(CognitiveThread::getAllByWhere( ['status' => $status], ['order' => ['updated_at' => 'DESC']] ));
        }

        return $this->records(CognitiveThread::getAll(['order' => ['updated_at' => 'DESC']]));
    }

    public function listThreadSteps(?int $threadId = null): array
    {
        if ($threadId !== null) {
            $this->requireCognitiveThread($threadId);
            return $this->records(ThreadStep::getAllByWhere( ['thread_id' => $threadId], ['order' => ['created_at' => 'DESC']] ));
        }

        return $this->records(ThreadStep::getAll(['order' => ['created_at' => 'DESC']]));
    }

    public function releaseCognitiveThreadByKey(string $threadKey, string $reason): array
    {
        $this->requireText($threadKey, 'thread key');
        $this->requireText($reason, 'release reason');

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
                    $this->emit('work.cancelled', [ 'work_item_id' => $workId, 'thread_id' => $thread->id, 'reason' => 'cognitive_thread_released', ]);
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
    }
}
