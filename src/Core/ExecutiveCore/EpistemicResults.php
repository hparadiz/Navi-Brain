<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\Event;
use NaviBrain\Model\Memory;
use NaviBrain\Storage\TokenMemoryDaemon;
use NaviBrain\Model\ThreadStep;
use RuntimeException;

class EpistemicResults extends Component
{
    public function acceptEpistemicRefinement(EpistemicProposal $refinement): array
    {
        $currentThread = $this->requireCognitiveThread((int) $refinement->thread->id);
        $currentStep = $this->requireThreadStep((int) $refinement->step->id);
        if (!in_array($currentStep->status, ['running', 'succeeded'], true)
            || (int) $currentThread->fencing_token !== (int) $currentStep->fencing_token
        ) {
            throw new RuntimeException('Epistemic curation lost its thread fence.');
        }

        $now = time();
        $confidence = (float) $refinement->proposal['confidence'];
        $claim = (string) $refinement->proposal['content'];
        $priorUncertainty = (float) $currentThread->uncertainty;

        $uncertainty = match ($refinement->operation) {
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
        $nextOperation = $this->nextEpistemicOperation($budget, $refinement->operation);
        $nextWake = $now + $this->threadBudgetInt($currentThread, 'poll_seconds', 1800);

        $event = null;
        foreach (Event::getAllByWhere(['kind' => 'thread.refinement.accepted'], ['order' => ['id' => 'DESC']]) as $candidate) {
            $payload = is_array($candidate->payload) ? $candidate->payload : [];
            if ((int) ($payload['thread_step_id'] ?? 0) === (int) $currentStep->id
                && (int) ($payload['work_item_id'] ?? 0) === $refinement->workId
            ) {
                $event = $candidate;
                break;
            }
        }
        if ($event instanceof Event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            if (($payload['operation'] ?? null) !== $refinement->operation
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
                'work_item_id' => $refinement->workId,
                'operation' => $refinement->operation,
                'claim_sha256' => hash('sha256', $claim),
                'confidence' => $confidence,
                'prior_uncertainty' => $priorUncertainty,
                'uncertainty' => $uncertainty,
                'next_operation' => $nextOperation,
                'next_wake_at' => $nextWake,
                'model' => $refinement->model,
            ]);
        }

        $memory = null;
        if ($refinement->operation === 'reflect') {
            $memory = new Memory([
                'tier' => 'semantic',
                'content' => $claim,
                'confidence' => $confidence,
                'operation_key' => TokenMemoryDaemon::operationKey('memory-add', 'thread-reflection:' . (int) $event->id),
                'source_event_id' => (int) $event->id,
            ], true, true);
            $memory->save();
        }

        $currentThread = $this->requireCognitiveThread((int) $refinement->thread->id);
        $currentStep = $this->requireThreadStep((int) $refinement->step->id);
        if ($currentStep->status === 'succeeded') {
            $observed = is_array($currentStep->observed_result) ? $currentStep->observed_result : [];
            if (($observed['operation'] ?? null) !== $refinement->operation
                || (int) ($observed['work_item_id'] ?? 0) !== $refinement->workId
                || (int) ($observed['semantic_memory_id'] ?? 0) !== (int) ($memory?->id ?? 0)
            ) {
                throw new RuntimeException('Completed epistemic refinement differs from this retry.');
            }
            return [
                'status' => 'refinement_accepted',
                'operation' => $refinement->operation,
                'thread' => $currentThread->getData(),
                'thread_step' => $currentStep->getData(),
                'memory' => $memory?->getData(),
                'event' => $event->getData(),
                'deduplicated' => true,
            ];
        }
        if ($currentStep->status !== 'running'
            || (int) $currentThread->fencing_token !== (int) $currentStep->fencing_token
        ) {
            throw new RuntimeException('Epistemic curation lost its thread fence.');
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
            $semanticIds[] = (int) $memory->id;
            $supportRefs['semantic_memory_ids'] = array_slice($semanticIds, -25);
        }

        $currentStep->setFields([
            'completed_at' => $now,
            'proposal' => $refinement->proposal,
            'deterministic_checks' => $refinement->checks,
            'curator_verdict' => 'accepted',
            'observed_result' => [
                'operation' => $refinement->operation,
                'belief_changed' => $refinement->operation === 'reflect',
                'semantic_memory_id' => $memory?->id,
                'work_item_id' => $refinement->workId,
                'model' => $refinement->model,
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
            'current_belief' => $refinement->operation === 'reflect' ? $claim : (string) $currentThread->current_belief,
            'uncertainty' => $uncertainty,
            'support_refs' => $supportRefs,
            'phase' => 'waiting',
            'next_operation' => $nextOperation,
            'expected_postcondition' => sprintf('The next wake runs the %s operation and records an accepted refinement, a rejection, or a wait.', $nextOperation),
            'wake_at' => $nextWake,
            'spent' => $spent,
            'progress' => min(1.0, round((int) $spent['accepted'] / $acceptanceTarget, 3)),
            'stagnation_count' => 0,
            'status' => 'waiting',
            'last_observation' => sprintf('Accepted a %s refinement at confidence %.2f; uncertainty moved from %.3f to %.3f.', $refinement->operation, $confidence, $priorUncertainty, $uncertainty),
            'updated_at' => $now,
        ]);
        $currentThread->save();
        $this->markWorkArtifact($refinement->workId, 'accepted');

        return [
            'status' => 'refinement_accepted',
            'operation' => $refinement->operation,
            'thread' => $currentThread->getData(),
            'thread_step' => $currentStep->getData(),
            'memory' => $memory?->getData(),
            'event' => $event->getData(),
        ];
    }

    public function rejectEpistemicProposal(EpistemicProposal $refinement, string $reason): array
    {
        $currentThread = $this->requireCognitiveThread((int) $refinement->thread->id);
        $currentStep = $this->requireThreadStep((int) $refinement->step->id);
        $now = time();
        $stagnationCount = (int) $currentThread->stagnation_count + 1;
        $nextWake = $currentThread->thread_key === Executive::MIND_STREAM_THREAD_KEY
            ? $now + $this->mindStreamBackoff($stagnationCount)
            : $now + $this->threadBudgetInt($currentThread, 'poll_seconds', 1800);
        $spent = is_array($currentThread->spent) ? $currentThread->spent : [];
        $spent['worker_calls'] = (int) ($spent['worker_calls'] ?? 0) + 1;
        $spent['rejected'] = (int) ($spent['rejected'] ?? 0) + 1;

        $currentStep->setFields([
            'completed_at' => $now,
            'proposal' => $refinement->proposal,
            'deterministic_checks' => $refinement->checks,
            'curator_verdict' => 'rejected',
            'observed_result' => [
                'belief_changed' => false,
                'reason' => $reason,
                'work_item_id' => $refinement->workId,
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
        $this->markWorkArtifact($refinement->workId, 'rejected');
        $event = $this->emit('thread.refinement.rejected', [
            'thread_id' => $currentThread->id,
            'thread_step_id' => $currentStep->id,
            'work_item_id' => $refinement->workId,
            'reason' => $reason,
            'checks' => $refinement->checks,
            'next_wake_at' => $nextWake,
        ]);

        return [
            'status' => 'refinement_rejected',
            'reason' => $reason,
            'thread' => $currentThread->getData(),
            'thread_step' => $currentStep->getData(),
            'event' => $event->getData(),
        ];
    }

    public function nextEpistemicOperation(array $budget, string $operation): string
    {
        $cycle = is_array($budget['operation_cycle'] ?? null) ? array_values(array_filter( $budget['operation_cycle'], static fn (mixed $entry): bool => is_string($entry) && $entry !== '' )) : [];
        if ($cycle === []) {
            return 'plan';
        }
        $index = array_search($operation, $cycle, true);
        if ($index === false) {
            return $cycle[0];
        }
        return $cycle[((int) $index + 1) % count($cycle)];
    }

    public function completeEpistemicThread(CognitiveThread $thread, ThreadStep $step, string $reason): array
    {
        $currentThread = $this->requireCognitiveThread((int) $thread->id);
        $currentStep = $this->requireThreadStep((int) $step->id);
        $now = time();
        $currentStep->setFields([ 'completed_at' => $now, 'observed_result' => ['choice' => 'complete', 'reason' => $reason], 'post_state' => ['thread_phase' => 'complete'], 'status' => 'succeeded', ]);
        $currentStep->save();
        $currentThread->setFields([ 'phase' => 'complete', 'wake_at' => null, 'progress' => 1.0, 'status' => 'complete', 'last_observation' => $reason, 'updated_at' => $now, ]);
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
    }

    public function blockEpistemicThread(CognitiveThread $thread, ThreadStep $step, string $reason): array
    {
        $currentThread = $this->requireCognitiveThread((int) $thread->id);
        $currentStep = $this->requireThreadStep((int) $step->id);
        $now = time();
        $currentStep->setFields([ 'completed_at' => $now, 'observed_result' => ['choice' => 'block', 'reason' => $reason], 'post_state' => ['thread_phase' => 'blocked'], 'status' => 'succeeded', ]);
        $currentStep->save();
        $currentThread->setFields([ 'phase' => 'blocked', 'wake_at' => null, 'status' => 'blocked', 'last_observation' => $reason, 'updated_at' => $now, ]);
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
    }

    public function failEpistemicAdvanceStep(int $threadId, int $stepId, int $workId, string $error): array
    {
        $thread = $this->requireCognitiveThread($threadId);
        $step = $this->requireThreadStep($stepId);
        if (in_array($step->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return ['status' => 'already_finalized', 'thread_step' => $step->getData()];
        }
        $now = time();
        $isMindStream = $thread->thread_key === Executive::MIND_STREAM_THREAD_KEY;
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
                ? Executive::MIND_STREAM_WORK_TYPE
                : Executive::EPISTEMIC_ADVANCE_WORK_TYPE,
            'error' => $error,
            'next_wake_at' => $nextWake,
        ]);
        return [
            'status' => 'worker_failed',
            'thread' => $thread->getData(),
            'thread_step' => $step->getData(),
            'event' => $event->getData(),
        ];
    }
}
