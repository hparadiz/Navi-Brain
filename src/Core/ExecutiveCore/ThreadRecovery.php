<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use DateTimeImmutable;
use DateTimeZone;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Model\WorkItem;
use Throwable;

class ThreadRecovery extends Component
{
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

    public function recoverAbandonedCognitiveThreadSteps(int $now): array
    {
        $recovered = [];
        foreach (ThreadStep::getAllByWhere(['status' => 'running'], ['order' => ['created_at' => 'ASC']]) as $candidate) {
            $createdAt = $this->timestamp($candidate->created_at);
            if ($candidate->worker_work_item_id !== null
                || $createdAt === null
                || $createdAt > $now - Executive::STALE_THREAD_STEP_SECONDS
            ) {
                continue;
            }

            $step = $candidate;
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
            $recovered[] = (int) $step->id;
        }
        return $recovered;
    }

    public function reconcileCognitiveThreadResults(): array
    {
        $results = [];
        foreach (ThreadStep::getAllByWhere(['status' => 'running'], ['order' => ['created_at' => 'ASC']]) as $step) {
            if ($step->worker_work_item_id === null) {
                continue;
            }
            $work = WorkItem::getByID((int) $step->worker_work_item_id);
            if (!$work instanceof WorkItem) {
                continue;
            }
            if ($work->status === 'completed') {
                try {
                    $results[] = $this->integrateWorkerResult($work->getData(), is_array($work->result) ? $work->result : [], is_string($work->model) ? $work->model : null);
                } catch (Throwable $throwable) {
                    $results[] = $this->handleWorkerFailure($work->getData(), 'Completed worker result could not be reconciled: ' . $throwable->getMessage());
                }
            } elseif (in_array($work->status, ['failed', 'cancelled'], true)) {
                $results[] = $this->handleWorkerFailure($work->getData(), (string) ($work->error ?? 'Worker work item ended without a result.'));
            }
        }
        return $results;
    }

    public function recoverIndeterminateDispatches(): array
    {
        $recovered = [];
        foreach (ThreadStep::getAllByWhere(['status' => 'dispatching']) as $step) {
            $workId = (int) ($step->worker_work_item_id ?? 0);
            $this->finishIndeterminateSelfPresenceDispatch((int) $step->thread_id, (int) $step->id, $workId, 'The process restarted after dispatch was committed but before its outcome was recorded.');
            $recovered[] = (int) $step->id;
        }
        return $recovered;
    }

    public function markWorkArtifact(int $workId, string $status): ?ThoughtArtifact
    {
        $this->requireChoice($status, ['accepted', 'rejected'], 'artifact status');
        foreach (ThoughtArtifact::getAllByWhere(['status' => 'proposed'], ['order' => ['created_at' => 'DESC'], 'limit' => 100]) as $artifact) {
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

    public function recentSelfPresenceContext(int $threadId, int $limit): array
    {
        $moments = [];
        foreach (ThreadStep::getAllByWhere(['thread_id' => $threadId, 'status' => 'succeeded'], ['order' => ['completed_at' => 'DESC'], 'limit' => 100]) as $step) {
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

    public function withinQuietHours(CognitiveThread $thread, int $now): bool
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

    public function nextQuietEnd(CognitiveThread $thread, int $now): int
    {
        $budget = is_array($thread->budget) ? $thread->budget : [];
        $timezone = new DateTimeZone((string) ($budget['quiet_timezone'] ?? 'America/Los_Angeles'));
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($timezone);
        $start = $this->threadBudgetInt($thread, 'quiet_start_hour', 23);
        $end = $this->threadBudgetInt($thread, 'quiet_end_hour', 9);
        $hour = (int) $local->format('G');

        if ($start > $end && $hour >= $start) {
            $local = $local->modify('+1 day');
        }
        return $local->setTime($end, 0)->getTimestamp();
    }

    public function earliestWorkerDispatchAt(CognitiveThread $thread, int $now): int
    {
        $floor = max(0, $this->threadBudgetInt( $thread, 'min_worker_interval_seconds', Executive::MIN_WORKER_INTERVAL_SECONDS ));

        if ($this->presenceEstimate()['present'] === true) {
            $fresh = 0;
            foreach ($this->sensoryCortex()->pendingEvents(5, 0.6) as $event) {
                $observedAt = $this->timestamp($event['observed_at'] ?? null);
                if ($observedAt !== null && ($now - $observedAt) <= 180) {
                    $fresh++;
                }
            }
            if ($fresh > 0) {
                $floor = min($floor, $this->threadBudgetInt( $thread, 'engaged_worker_interval_seconds', Executive::ENGAGED_WORKER_INTERVAL_SECONDS ));
            }
        }

        if ($floor === 0) {
            return $now;
        }
        foreach (ThreadStep::getAllByWhere(['thread_id' => (int) $thread->id], ['order' => ['id' => 'DESC'], 'limit' => 25]) as $step) {
            if ($step->worker_work_item_id === null) {
                continue;
            }
            $dispatchedAt = $this->timestamp($step->created_at);
            return $dispatchedAt === null ? $now : $dispatchedAt + $floor;
        }
        return $now;
    }

    public function threadBudgetInt(CognitiveThread $thread, string $key, int $default): int
    {
        $budget = is_array($thread->budget) ? $thread->budget : [];
        $value = $budget[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }
}
