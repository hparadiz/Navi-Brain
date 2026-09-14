<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Support\Name;
use NaviBrain\Support\Pronouns;
use NaviBrain\Model\WorkItem;

use InvalidArgumentException;
use NaviBrain\Core\ExecutiveComposition;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Need;
use NaviBrain\Model\Sense;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Support\PlainText;
use RuntimeException;
use Throwable;

class SelfPresenceComposition extends Component
{
    public function evaluateSelfPresenceThread(CognitiveThread $thread, ThreadStep $step, int $now): array
    {
        $intention = $this->requireIntention((int) $thread->parent_intention_id);
        if ($intention->status !== 'active' || $intention->authority !== 'user') {
            return $this->releaseCognitiveThread($thread, $step, 'The parent user-authority intention is no longer active.');
        }

        $addressed = $this->pendingAddressedEvents();

        $this->accrueNeeds($now);
        $need = Need::getByField('need_key', Executive::SELF_PRESENCE_NEED_KEY);
        $needState = $need instanceof Need ? $this->needState($need, $now) : null;
        $gate = $this->selfPresenceSpeechGate($thread, $now, $addressed !== []);
        $recentMoments = $this->recentSelfPresenceContext((int) $thread->id, 5);

        $preState = (array) $step->pre_state;
        $preState['need'] = $needState;
        $preState['gate'] = $gate;
        $preState['recent_moments'] = $recentMoments;
        $step->pre_state = $preState;
        $prompt = $this->composeSelfPresence($thread, $step, $addressed, $now);
        $workType = $addressed === []
            ? Executive::SELF_PRESENCE_SPEECH_WORK_TYPE
            : Executive::SELF_PRESENCE_ANSWER_WORK_TYPE;
        $contextScope = $addressed === []
            ? 'privacy_safe_generic_self_presence_capsule'
            : 'no_workspace';
        $queued = $this->enqueueWork(new WorkItem([
            'parent_run_id' => null,
            'parent_intention_id' => (int) $thread->parent_intention_id,
            'work_type' => $workType,
            'prompt' => $prompt,
            'input_refs' => [
                'thread_id' => (int) $thread->id,
                'thread_step_id' => (int) $step->id,
                'thread_fencing_token' => (int) $thread->fencing_token,
                'context_scope' => $contextScope,
                'attention_priority' => $addressed === [] ? 'background' : 'addressed_speech',
                'addressed_event_ids' => array_values(array_map( static fn (array $event): int => (int) $event['id'], $addressed )),
            ],
            'token_budget' => $addressed === []
                ? max(256, (int) round($this->appraiseNow(time())->wordBudget() * 1.8) + 96)
                : 160,
            'wall_budget_seconds' => $addressed === [] ? 300 : 90,
            'idempotency_key' => sprintf('thread:%d:fence:%d:self_presence', $thread->id, $thread->fencing_token),
            'depth' => 0,
            'max_depth' => 0
        ], true, true));
        $work = $queued['work_item'];

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
        $currentStep->setFields([ 'pre_state' => $preState, 'worker_work_item_id' => (int) $work['id'], ]);
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
    }

    public function latestReadingFor(string $sourceKey, int $staleAfterSeconds): ?array
    {
        foreach (SenseReading::getAllByWhere(['source_key' => $sourceKey], ['order' => ['id' => 'DESC'], 'limit' => 1]) as $reading) {
            $observedAt = $this->timestamp($reading->observed_at);
            if ($observedAt === null || (time() - $observedAt) > $staleAfterSeconds) {
                return null;
            }
            return is_array($reading->payload) ? $reading->payload : null;
        }
        return null;
    }

    public function presenceNow(int $staleAfterSeconds = 300): ?bool
    {
        return $this->presenceEstimate($staleAfterSeconds)['present'];
    }

    public function presenceEstimate(int $staleAfterSeconds = 300): array
    {
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

    public function composeSelfPresence(CognitiveThread $thread, ThreadStep $step, array $addressed, int $now): string
    {
        $needState = $step->pre_state['need'] ?? null;
        $gate = (array) ($step->pre_state['gate'] ?? []);
        $recentMoments = (array) ($step->pre_state['recent_moments'] ?? []);
        $recentThoughts = $this->recentStreamThoughts(5);
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
                sprintf('%s is answering speech addressed to %s right now.', Name::get(), Pronouns::get()->object),
                'Answer the spoken content directly. Do not discuss sensors, cognition, waiting, or this instruction.',
                "Heard, oldest to newest:\n" . PlainText::render($heard, 3000, 8),
                'Use 3 to 36 spoken words. No code, file paths, identifiers, brackets, symbols, or URLs.',
                'Do not claim to be conscious, sentient, alive, human, real, or a person.',
                'Return exactly the four JSON fields kind, content, confidence, and challenged_assumption.',
                'kind must be self_presence_utterance. Do not return remain_silent.',
            ]);
        }

        $track = $this->heldFocus($thread, $now);

        $composition = new ExecutiveComposition(sprintf('%s is deciding whether to say one thing out loud right now, and what.', Name::get()));

        if ($track !== null) {
            $composition->contribute(
                'goal_maintenance',
                sprintf('This is what %1$s has been working on and means to tell the user about. If %1$s speaks, it is about this. Say the specific thing %1$s found or is stuck on, not that %1$s has been busy.', Name::get()),
                $track
            );
            if (($track['what_is_already_known'] ?? []) !== []) {
                $composition->contribute('knowledge', 'Ground the line in one of these rather than speaking in general terms.', $track['what_is_already_known']);
            }
        }

        $composition->contribute(
            'initiation',
            'Silence is a real choice and costs nothing. Speak only when there is a specific thing to say. Having gone a while without speaking is not a reason.',
            ['allowed' => $gate['allowed'] ?? null, 'reason' => $gate['reason'] ?? null]
        );

        $ambient = array_values(array_filter( $this->sensoryCortex()->pendingEvents(5), static fn (array $event): bool => ($event['addressed'] ?? false) !== true ));
        $composition->contribute(
            'attention',
            'What the senses just turned up. Worth mentioning only if it changes something.',
            array_map(static fn (array $event): array => [ 'sense' => $event['sense_key'], 'noticed' => $event['summary'], 'significance' => $event['significance'], ], $ambient)
        );

        foreach ($ambient as $event) {
            try {
                $this->sensoryCortex()->recordOutcome((int) $event['id'], 'used', 'Read into a self-presence speech decision.');
            } catch (Throwable) {

            }
        }

        $composition->contribute(
            'working_memory',
            sprintf('%s has said these already. Saying a version of one again is worse than saying nothing.', Name::get()),
            ['recent_lines' => $recentMoments, 'recent_thoughts' => $recentThoughts]
        );

        $composition->contribute(
            'drive',
            'This is pressure to speak, not something to speak about. Never make the line a check-in, a wellness question, or an observation that the user has been quiet.',
            $needState
        );

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

    public function heldFocus(CognitiveThread $thread, int $now): ?array
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

    public function releaseFocus(CognitiveThread $thread, int $now, string $reason): void
    {
        $held = trim((string) $thread->desired_outcome);
        if ($held === '') {
            return;
        }
        if (!in_array($reason, ['achieved', 'impossible', 'reason_lapsed'], true)) {
            throw new InvalidArgumentException('A track is released when achieved, believed impossible, or its reason lapsed.');
        }
        $thread->setFields(['desired_outcome' => '', 'updated_at' => $now]);
        $thread->save();
        $this->emit('focus.released', ['track' => $held, 'reason' => $reason]);
    }

    public function trackByTitle(string $title): ?array
    {
        $intention = Intention::getByField('title', $title);
        if (!$intention instanceof Intention || $intention->status !== 'active') {
            return null;
        }
        return $this->describeTrack($intention);
    }

    public function trackOfInterest(int $now): ?array
    {
        $candidates = [];
        foreach (Intention::getAllByWhere(['status' => 'active']) as $intention) {

            if ((int) $intention->id === $this->selfPresenceIntentionId()) {
                continue;
            }
            $candidates[] = $intention;
        }
        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (Intention $left, Intention $right): int { return ($this->timestamp($left->updated_at) ?? 0) <=> ($this->timestamp($right->updated_at) ?? 0); });

        return $this->describeTrack($candidates[0]);
    }

    public function describeTrack(Intention $intention): array
    {

        $knows = [];
        foreach ($this->searchMemory((string) $intention->title . ' ' . (string) $intention->next_action, 8) as $memory) {
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
            'what_is_already_known' => $knows,
        ];
    }

    public function selfPresenceIntentionId(): int
    {
        $thread = CognitiveThread::getByField('thread_key', Executive::SELF_PRESENCE_THREAD_KEY);
        return $thread instanceof CognitiveThread ? (int) $thread->parent_intention_id : 0;
    }

    public function pendingAddressedEvents(int $limit = 5): array
    {
        return array_values(array_filter( $this->sensoryCortex()->pendingEvents($limit), static fn (array $event): bool => ($event['addressed'] ?? false) === true ));
    }

    public function heardEventText(array $event): string
    {
        $after = is_array($event['after'] ?? null) ? $event['after'] : [];
        $text = is_string($after['text'] ?? null) ? trim($after['text']) : '';
        return $text !== '' ? $text : trim((string) ($event['summary'] ?? ''));
    }
}
