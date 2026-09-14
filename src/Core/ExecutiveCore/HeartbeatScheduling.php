<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use Divergence\IO\Database\SQLite;
use NaviBrain\Core\CognitiveLayer;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Core\NarrativeSynthesis;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\CycleRun;
use NaviBrain\Model\ExecutiveInterrupt;
use NaviBrain\Model\Rhythm;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Model\WorkItem;
use RuntimeException;
use Throwable;

class HeartbeatScheduling extends Component
{
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
            'running_cycles' => $this->records(CycleRun::getAllByWhere( ['status' => 'running'], ['order' => ['created_at' => 'ASC']] )),
            'queued_work' => $this->records(WorkItem::getAllByWhere( ['status' => 'queued'], ['order' => ['created_at' => 'ASC']] )),
            'leased_work' => $this->records(WorkItem::getAllByWhere( ['status' => 'leased'], ['order' => ['created_at' => 'ASC']] )),
            'cognitive_threads' => $this->records(array_values(array_filter(CognitiveThread::getAll(['order' => ['updated_at' => 'DESC']]), static fn (CognitiveThread $thread): bool => in_array( $thread->status, ['active', 'waiting'], true )))),
            'running_thread_steps' => $this->records(array_values(array_filter(ThreadStep::getAll(['order' => ['created_at' => 'ASC']]), static fn (ThreadStep $step): bool => in_array( $step->status, ['running', 'dispatching'], true )))),
            'pending_interrupts' => $this->records(ExecutiveInterrupt::getAllByWhere( ['status' => 'pending'], ['order' => ['created_at' => 'ASC']] )),
        ];
    }

    public function runDueHeartbeats(string $nodeId): array
    {
        $this->requireText($nodeId, 'node id');
        $recoveredDecisions = $this->recoverDecisionIntegrations();
        $now = time();
        $recoveredCycles = $this->recoverExpiredCycleLeases($now);
        $due = array_values(array_filter(Rhythm::getAll(), fn (Rhythm $rhythm): bool => in_array($rhythm->status, ['idle', 'failed'], true) && ($this->timestamp($rhythm->next_due_at) ?? PHP_INT_MAX) <= $now));
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
            'recovered_decision_integrations' => $recoveredDecisions,
            'fired' => $results,
            'status' => $this->heartbeatStatus(),
        ];
    }

    public function runDueCognitiveThreads(string $nodeId): array
    {
        $this->requireText($nodeId, 'node id');
        $now = time();
        $recoveredSteps = $this->recoverAbandonedCognitiveThreadSteps($now);
        $reconciled = $this->reconcileCognitiveThreadResults();
        $indeterminate = $this->recoverIndeterminateDispatches();

        $scheduler = [
            'node_id' => $nodeId,
            'recovered_thread_step_ids' => $recoveredSteps,
            'reconciled' => $reconciled,
            'indeterminate_dispatches' => $indeterminate,
        ];
        $thread = null;
        if (!ExecutiveControl::status()['paused']) {
            $thread = CognitiveThread::getByQuery(
                "SELECT * FROM cognitive_threads
                 WHERE status IN ('active', 'waiting')
                   AND wake_at IS NOT NULL AND wake_at <= " . SQLite::quote(date('Y-m-d H:i:s', $now)) . "
                 ORDER BY CASE phase WHEN 'interrupted' THEN 0 ELSE 1 END,
                          wake_at ASC, id ASC LIMIT 1"
            );
        }
        if (!$thread instanceof CognitiveThread) {
            return ['status' => 'idle'] + $scheduler;
        }
        $thread->setFields([
            'status' => 'active', 'phase' => 'evaluating', 'wake_at' => null,
            'version' => (int) $thread->version + 1,
            'fencing_token' => (int) $thread->fencing_token + 1, 'updated_at' => $now,
        ]);
        $thread->save();

        $step = new ThreadStep([
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
        ], true, true);
        $step->save();
        $this->emit('thread.step.started', [
            'thread_id' => $thread->id,
            'thread_step_id' => $step->id,
            'thread_key' => $thread->thread_key,
            'operation' => $step->operation,
            'fencing_token' => $step->fencing_token,
            'node_id' => $nodeId,
        ]);

        try {
            if ($thread->thread_key !== Executive::SELF_PRESENCE_THREAD_KEY
                && $thread->thread_key !== Executive::EPISTEMIC_ADVANCE_THREAD_KEY
                && $thread->thread_key !== Executive::MIND_STREAM_THREAD_KEY
            ) {
                $step->setFields([
                    'observed_result' => ['reason' => 'unsupported_thread_type', 'detail' => 'No bounded executor is registered for this thread key.'],
                    'next_wake_at' => $now + 3600,
                    'error' => null,
                ]);
                return array_merge($scheduler, $this->recordThreadWait($thread, $step));
            }

            $earliestDispatch = $this->earliestWorkerDispatchAt($thread, $now);
            if ($earliestDispatch > $now) {
                $step->setFields([
                    'observed_result' => [
                        'reason' => 'worker_rate_limited',
                        'detail' => sprintf('The previous model dispatch for this thread was under %d seconds ago. This thread retains its configured dispatch floor.', $this->threadBudgetInt($thread, 'min_worker_interval_seconds', Executive::MIN_WORKER_INTERVAL_SECONDS)),
                    ],
                    'next_wake_at' => $earliestDispatch,
                    'error' => null,
                ]);
                return array_merge($scheduler, $this->recordThreadWait($thread, $step));
            }

            if ($thread->thread_key === Executive::MIND_STREAM_THREAD_KEY) {
                return array_merge($scheduler, $this->evaluateMindStreamThread($thread, $step, $now));
            }

            if ($thread->thread_key === Executive::EPISTEMIC_ADVANCE_THREAD_KEY) {
                return array_merge($scheduler, $this->evaluateEpistemicAdvanceThread($thread, $step, $now));
            }

            return array_merge($scheduler, $this->evaluateSelfPresenceThread($thread, $step, $now));
        } catch (Throwable $throwable) {
            $step->setFields([ 'observed_result' => ['reason' => 'evaluation_error', 'detail' => $throwable->getMessage()], 'next_wake_at' => time() + 900, 'error' => $throwable->getMessage(), ]);
            return array_merge($scheduler, $this->recordThreadWait($thread, $step));
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

        $run = $claim['run'];
        try {
            if ($rhythm->cognitive_layer === CognitiveLayer::LOW->value) {
                if ($rhythmKey === 'intentions_10m') {
                    $work = (new NarrativeSynthesis($this->executive))->enqueueIntentions('Scheduled ten-minute reconsideration of open intentions.', (int) $run->id);
                    return $this->completeCycleRun($run, $rhythm, $nodeId, [ 'state' => 'intention_narrative_queued', 'work_item' => $work['work_item'] ?? null, ]);
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

    public function highBrainBusy(): bool
    {
        return CycleRun::getAllByWhere([ 'status' => 'running', 'cognitive_layer' => CognitiveLayer::HIGH->value, ], ['limit' => 1]) !== [];
    }
}
