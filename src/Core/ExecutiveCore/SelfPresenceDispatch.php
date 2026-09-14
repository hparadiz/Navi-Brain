<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Perception\PetSpeechActuator;
use Throwable;

class SelfPresenceDispatch extends Component
{
    public function selfPresenceSpeechGate(CognitiveThread $thread, int $now, bool $answerExpected = false): array
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

        $affect = $this->appraiseNow($now);
        $this->emit('affect.appraised', $affect->toArray());

        $decision = $answerExpected
            ? ['chosen' => 'answer', 'because' => 'Addressed speech is already captured in the fenced work item.']
            : $this->selectAction($now);
        if (!in_array($decision['chosen'], ['speak', 'answer'], true)) {
            return [
                'allowed' => false,
                'reason' => 'not_selected',
                'detail' => sprintf('Feeling %s; this wake went to %s instead, because %s.', $affect->dominant, (string) $decision['chosen'], (string) $decision['because']),
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
                    'detail' => sprintf('Feeling %s; not moved to speak this time (likelihood %.2f).', $affect->dominant, $likelihood),
                    'next_wake_at' => $now + $this->threadBudgetInt($thread, 'min_interval_seconds', 90),
                    'affect' => $affect->toArray(),
                ];
            }
        }

        try {
            $pet = $this->executive->speechActuator->health();
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

    public function recordThreadWait(CognitiveThread $thread, ThreadStep $step): array
    {
        if ($step->status !== 'running') {
            return ['status' => 'already_finalized', 'thread_step' => $step->getData()];
        }
        $reason = (string) ($step->observed_result['reason'] ?? 'waiting');
        $detail = (string) ($step->observed_result['detail'] ?? '');
        $nextWakeAt = $this->timestamp($step->next_wake_at) ?? time();
        $failed = $step->error !== null;
        $now = time();
        $step->setFields([
            'completed_at' => $now,
            'observed_result' => ['choice' => 'wait', 'reason' => $reason, 'detail' => $detail],
            'post_state' => ['thread_phase' => 'waiting'],
            'next_wake_at' => $nextWakeAt,
            'status' => $failed ? 'failed' : 'succeeded',
            'error' => $failed ? $detail : null,
        ]);
        $step->save();

        $thread->setFields([
            'phase' => 'waiting',
            'wake_at' => $nextWakeAt,
            'status' => 'waiting',
            'stagnation_count' => $failed
                ? (int) $thread->stagnation_count + 1
                : (int) $thread->stagnation_count,
            'last_observation' => $detail,
            'updated_at' => $now,
        ]);
        $thread->save();
        $event = $this->emit('thread.waiting', [
            'thread_id' => $thread->id,
            'thread_step_id' => $step->id,
            'reason' => $reason,
            'detail' => $detail,
            'next_wake_at' => $nextWakeAt,
            'failed' => $failed,
        ]);
        return [
            'status' => $failed ? 'evaluation_failed' : 'waiting',
            'reason' => $reason,
            'thread' => $thread->getData(),
            'thread_step' => $step->getData(),
            'event' => $event->getData(),
        ];
    }

    public function releaseCognitiveThread(CognitiveThread $thread, ThreadStep $step, string $reason): array
    {
        $currentThread = $this->requireCognitiveThread((int) $thread->id);
        $currentStep = $this->requireThreadStep((int) $step->id);
        $now = time();
        $currentStep->setFields([ 'completed_at' => $now, 'observed_result' => ['choice' => 'release', 'reason' => $reason], 'post_state' => ['thread_phase' => 'released'], 'status' => 'cancelled', ]);
        $currentStep->save();
        $currentThread->setFields([ 'phase' => 'released', 'wake_at' => null, 'status' => 'released', 'last_observation' => $reason, 'updated_at' => $now, ]);
        $currentThread->save();
        $event = $this->emit('thread.released', [ 'thread_id' => $currentThread->id, 'thread_step_id' => $currentStep->id, 'reason' => $reason, ]);
        return [
            'status' => 'released',
            'thread' => $currentThread->getData(),
            'thread_step' => $currentStep->getData(),
            'event' => $event->getData(),
        ];
    }

    public function validateSelfPresenceProposal(array $proposal, CognitiveThread $thread, bool $answerExpected = false): array
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
        $wordCount = count(array_values(array_filter( preg_split('/\s+/u', $content) ?: [], static fn (string $word): bool => $word !== '' )));
        $isUtterance = $kind === 'self_presence_utterance';
        $isSilence = $kind === 'remain_silent';

        [$floorWords] = $this->spokenWordRange($thread);
        $maxWords = $answerExpected
            ? 36
            : max($floorWords, $this->appraiseNow(time())->wordBudget());

        $forbidden = '/(?:https?:\/\/|www\.|\b(?:i am|i\'m)\s+(?:alive|conscious|sentient|a person|human|real)\b|\bmy consciousness\b|\bi (?:truly|really) feel\b)/iu';
        $checks = [
            'exact_fields' => $exactFields,
            'allowed_kind' => $isUtterance || $isSilence,
            'content_nonempty' => $content !== '',
            'challenge_nonempty' => $challenge !== '',
            'confidence_bounded' => $numericConfidence
                && (float) $confidence >= 0.0
                && (float) $confidence <= 1.0,

            'content_size_bounded' => strlen($content) <= ($isUtterance ? max(320, $maxWords * 8) : 500),
            'spoken_word_count_bounded' => !$isUtterance || ($wordCount >= 3 && $wordCount <= $maxWords),
            'speech_policy_clean' => !$isUtterance || preg_match($forbidden, $content) !== 1,

            'sayable_aloud' => !$isUtterance || PetSpeechActuator::unspeakable($content) === null,

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

    public function rejectSelfPresenceProposal(CognitiveThread $thread, ThreadStep $step, string $reason): array
    {
        $now = time();
        $addressed = $this->pendingAddressedEvents() !== [];
        $nextWake = $now;
        $step->setFields([
            'completed_at' => $now,
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
        $this->markWorkArtifact((int) $step->worker_work_item_id, 'rejected');
        $event = $this->emit('thread.proposal.rejected', [
            'thread_id' => $thread->id,
            'thread_step_id' => $step->id,
            'work_item_id' => (int) $step->worker_work_item_id,
            'reason' => $reason,
            'checks' => (array) $step->deterministic_checks,
        ]);
        return [
            'status' => 'proposal_rejected',
            'reason' => $reason,
            'thread' => $thread->getData(),
            'thread_step' => $step->getData(),
            'event' => $event->getData(),
        ];
    }

    public function acceptSelfPresenceSilence(CognitiveThread $thread, ThreadStep $step, ?string $model): array
    {
        $now = time();
        $nextWake = $now;
        $step->setFields([
            'completed_at' => $now,
            'curator_verdict' => 'accepted',
            'observed_result' => [
                'spoken' => false,
                'choice' => 'remain_silent',
                'work_item_id' => (int) $step->worker_work_item_id,
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
        $this->markWorkArtifact((int) $step->worker_work_item_id, 'accepted');
        $event = $this->emit('thread.silence.chosen', [ 'thread_id' => $thread->id, 'thread_step_id' => $step->id, 'work_item_id' => (int) $step->worker_work_item_id, 'next_wake_at' => $nextWake, ]);
        return [
            'status' => 'silence_chosen',
            'thread' => $thread->getData(),
            'thread_step' => $step->getData(),
            'event' => $event->getData(),
        ];
    }

    public function deferAcceptedSelfPresenceSpeech(CognitiveThread $thread, ThreadStep $step, array $gate, ?string $model): array
    {
        $now = time();
        $nextWake = $now;
        $step->setFields([
            'completed_at' => $now,
            'curator_verdict' => 'accepted',
            'observed_result' => [
                'spoken' => false,
                'choice' => 'defer_speech',
                'gate' => $gate,
                'work_item_id' => (int) $step->worker_work_item_id,
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
        $this->markWorkArtifact((int) $step->worker_work_item_id, 'accepted');
        $event = $this->emit('thread.speech.deferred', [
            'thread_id' => $thread->id,
            'thread_step_id' => $step->id,
            'work_item_id' => (int) $step->worker_work_item_id,
            'gate_reason' => $gate['reason'] ?? 'unknown',
            'next_wake_at' => $nextWake,
        ]);
        return [
            'status' => 'speech_deferred',
            'thread' => $thread->getData(),
            'thread_step' => $step->getData(),
            'event' => $event->getData(),
        ];
    }

    public function finishIndeterminateSelfPresenceDispatch(int $threadId, int $stepId, int $workId, string $error): array
    {
        $thread = $this->requireCognitiveThread($threadId);
        $step = $this->requireThreadStep($stepId);
        if ($step->status !== 'dispatching') {
            return ['status' => 'dispatch_already_finalized', 'thread_step' => $step->getData()];
        }
        $now = time();

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
    }
}
