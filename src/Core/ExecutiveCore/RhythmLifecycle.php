<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use Divergence\IO\Database\SQLite;
use NaviBrain\Core\CognitiveLayer;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\CycleRun;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Event;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Rhythm;
use NaviBrain\Model\WorkItem;
use RuntimeException;

class RhythmLifecycle extends Component
{
    public function claimRhythm(Rhythm $rhythm, string $nodeId, int $now, int $leaseSeconds): ?array
    {
        if (ExecutiveControl::status()['paused']) {
            return null;
        }
        $scheduledFor = $this->timestamp($rhythm->next_due_at) ?? $now;
        $interval = max(1, (int) $rhythm->interval_seconds);
        $missed = max(0, intdiv(max(0, $now - $scheduledFor), $interval));
        $newFence = (int) $rhythm->fencing_token + 1;

        $formattedNow = date('Y-m-d H:i:s', $now);
        $statementRecord = Rhythm::getByWhere(['id' => $rhythm->id, sprintf('next_due_at <= %s', SQLite::quote($formattedNow)), 'status != \'paused\'', sprintf('(status != \'running\' OR lease_expires_at IS NULL OR lease_expires_at <= %s)', SQLite::quote($formattedNow))]);
        if (!$statementRecord instanceof Rhythm) {
            return null;
        }
        $statementRecord->setFields([
            'status' => 'running',
            'lease_owner' => $nodeId,
            'lease_expires_at' => date('Y-m-d H:i:s', $now + $leaseSeconds),
            'last_started_at' => $formattedNow,
            'sequence' => (int) $statementRecord->sequence + 1,
            'fencing_token' => (int) $statementRecord->fencing_token + 1,
            'updated_at' => $formattedNow,
            'last_error' => null
        ]);
        $statementRecord->save();

        $run = new CycleRun([
            'rhythm_id' => $rhythm->id,
            'cognitive_layer' => $rhythm->cognitive_layer,
            'scheduled_for' => $scheduledFor,
            'node_id' => $nodeId,
            'fencing_token' => $newFence,
            'status' => 'running',
            'event_watermark' => $this->eventWatermark(),
            'budget' => $this->rhythmBudget((string) $rhythm->rhythm_key),
            'output' => ['missed_intervals' => $missed],
        ], true, true);
        $run->save();
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
    }

    public function coalesceHighRhythm(Rhythm $rhythm, int $now): array
    {
        $dueAt = $this->timestamp($rhythm->next_due_at) ?? $now;
        $interval = max(1, (int) $rhythm->interval_seconds);
        $missed = max(1, intdiv(max(0, $now - $dueAt), $interval) + 1);
        $rhythm->setFields([ 'next_due_at' => $now + $interval, 'updated_at' => $now, 'status' => 'idle', ]);
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

    public function lowBrainPulse(CycleRun $run): array
    {
        $now = time();

        $integrity = $this->executive->cachedQuickCheck ?? ['deferred_to_safety_audit'];
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

    public function prepareHighBrainMoment(CycleRun $run, Rhythm $rhythm): array
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

            $focusThread = CognitiveThread::getByField('thread_key', Executive::SELF_PRESENCE_THREAD_KEY);
            $track = $focusThread instanceof CognitiveThread ? $this->heldFocus($focusThread, time()) : null;
            $intention = $track === null
                ? null
                : Intention::getByField('title', (string) ($track['following'] ?? ''));
            if (!$intention instanceof Intention || $intention->status !== 'active') {
                $fallback = array_values(array_filter(Intention::getAllByWhere(['status' => 'active'], ['order' => ['updated_at' => 'ASC']]), fn (Intention $candidate): bool => (int) $candidate->id !== $this->selfPresenceIntentionId()));
                $intention = $fallback[0] ?? null;
            }
            if (!$intention instanceof Intention) {
                return ['state' => 'no_active_intention_for_decision', 'work_item' => null];
            }

            $decision = $this->startDecisionCycle((int) $intention->id, 'Scheduled executive decision wake for the held active intention.', null, null, (int) $run->id);
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
            $triggered = array_values(array_filter( $needs, static fn (array $need): bool => $need['status'] === 'active' && $need['triggered'] ));
            if ($triggered === []) {
                return ['state' => 'slept_through_reflection', 'needs' => $needs, 'work_item' => null];
            }
            if ($this->daydreamCooldownActive()) {
                return ['state' => 'daydream_cooldown_sleep', 'needs' => $needs, 'work_item' => null];
            }
            $daydream = $this->daydream('five-minute boredom and curiosity wake trigger', (int) $run->id);
            $deterministic['daydream_artifact_id'] = $daydream['artifact']['id'];
            $inputRefs['artifact_id'] = $daydream['artifact']['id'];

            $look = $this->enqueueLookProposal((int) $run->id, time());
            $deterministic['look_work_item_id'] = $look['work_item']['id'] ?? null;
            $workType = 'curiosity_reflection';
            $prompt = 'Return one JSON object with keys kind, content, confidence, and challenged_assumption. Generate one curious, surprising alternative to a common assumption in cognitive-agent architecture. This is an unverified proposal. Do not claim observation, memory, consciousness, or permission to act.';
        } elseif ($rhythmKey === 'consolidate_hourly') {
            $sleep = $this->sleep('hourly consolidation and repair proposal pass', (int) $run->id);
            $deterministic['sleep_event_id'] = $sleep['event']['id'];
            $inputRefs['sleep_event_id'] = $sleep['event']['id'];

            $held = CognitiveThread::getByField('thread_key', Executive::SELF_PRESENCE_THREAD_KEY);
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
        $queued = $this->enqueueWork(new WorkItem([
            'parent_run_id' => (int) $run->id,
            'parent_intention_id' => null,
            'work_type' => $workType,
            'prompt' => $prompt,
            'input_refs' => $inputRefs,
            'token_budget' => $budget['tokens'],
            'wall_budget_seconds' => $budget['wall_seconds'],
            'idempotency_key' => sprintf('cycle:%d:%s', $run->id, $workType)
        ], true, true));
        $deterministic['work_item'] = $queued['work_item'];
        $run->setField('output', $deterministic);
        $run->save();

        return $deterministic;
    }

    public function rhythmBudget(string $rhythmKey): array
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

    public function eventWatermark(): int
    {
        $events = Event::getAll(['order' => ['id' => 'DESC'], 'limit' => 1]);
        return $events === [] ? 0 : (int) $events[0]->id;
    }

    public function completeCycleRun(CycleRun $run, Rhythm $rhythm, string $nodeId, array $output): array
    {
        $this->requireCurrentRhythmLease($rhythm, $run, $nodeId);
        $now = time();
        $run->setFields([ 'completed_at' => $now, 'status' => 'completed', 'output' => $output, ]);
        $run->save();

        $statementRecord = Rhythm::getByWhere(['id' => $rhythm->id, 'fencing_token' => $run->fencing_token, 'lease_owner' => $nodeId]);
        if (!$statementRecord instanceof Rhythm) {
            throw new RuntimeException('Rhythm lease was lost before cycle completion.');
        }
        $statementRecord->setFields([
            'status' => 'idle',
            'last_completed_at' => date('Y-m-d H:i:s', $now),
            'next_due_at' => date('Y-m-d H:i:s', $now + (int) $rhythm->interval_seconds),
            'lease_owner' => null,
            'lease_expires_at' => null,
            'updated_at' => date('Y-m-d H:i:s', $now),
            'last_error' => null
        ]);
        $statementRecord->save();

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
    }

    public function failCycleRun(CycleRun $run, Rhythm $rhythm, string $nodeId, string $error): void
    {
        $now = time();
        $run->setFields([ 'completed_at' => $now, 'status' => 'failed', 'error' => $error, ]);
        $run->save();

        $statementRecord = Rhythm::getByWhere(['id' => $rhythm->id, 'fencing_token' => $run->fencing_token, 'lease_owner' => $nodeId]);
        if ($statementRecord instanceof Rhythm) {
            $statementRecord->setFields([
                'status' => 'failed',
                'next_due_at' => date('Y-m-d H:i:s', $now + (int) $rhythm->interval_seconds),
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => date('Y-m-d H:i:s', $now),
                'last_error' => $error
            ]);
            $statementRecord->save();
        }
        $this->emit('cycle.failed', [
            'run_id' => $run->id,
            'rhythm_id' => $rhythm->id,
            'rhythm_key' => $rhythm->rhythm_key,
            'node_id' => $nodeId,
            'fencing_token' => $run->fencing_token,
            'error' => $error,
            'state' => 'sleeping',
        ]);
    }

    public function requireCurrentRhythmLease(Rhythm $rhythm, CycleRun $run, string $nodeId): void
    {

        $row = Rhythm::getByID($rhythm->id)?->getData();
        if (!is_array($row)
            || $row['status'] !== 'running'
            || (int) $row['fencing_token'] !== (int) $run->fencing_token
            || $row['lease_owner'] !== $nodeId
            || ($this->timestamp($row['lease_expires_at']) ?? 0) < time()
        ) {
            throw new RuntimeException('The rhythm fencing lease is no longer current.');
        }
    }

    public function recoverExpiredWorkLeases(int $now, ?int $onlyWorkId = null): array
    {
        $recovered = [];
        $conditions = ['status' => 'leased'];
        if ($onlyWorkId !== null) {
            $conditions['id'] = $onlyWorkId;
        }
        foreach (WorkItem::getAllByWhere($conditions) as $work) {
            $leaseExpires = $this->timestamp($work->lease_expires_at);
            if ($leaseExpires !== null && $leaseExpires > $now) {
                continue;
            }
            $work->setFields([ 'status' => 'queued', 'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => $now, 'error' => 'Previous worker lease expired; work returned to queue.', ]);
            $work->save();
            $this->emit('work.requeued', [ 'work_item_id' => $work->id, 'reason' => 'expired_lease', 'fencing_token' => $work->fencing_token, ]);
            $recovered[] = (int) $work->id;
        }
        return $recovered;
    }

    public function recoverExpiredCycleLeases(int $now, ?int $excludeRunId = null): array
    {
        $recovered = [];
        foreach (Rhythm::getAllByWhere(['status' => 'running']) as $rhythm) {
            $leaseExpires = $this->timestamp($rhythm->lease_expires_at);
            if ($leaseExpires !== null && $leaseExpires > $now) {
                continue;
            }

            $runs = CycleRun::getAllByWhere([ 'rhythm_id' => $rhythm->id, 'status' => 'running', ]);
            foreach ($runs as $run) {
                if ($excludeRunId !== null && (int) $run->id === $excludeRunId) {
                    continue 2;
                }
                if ((int) $run->fencing_token !== (int) $rhythm->fencing_token) {
                    $run->setFields([ 'completed_at' => $now, 'status' => 'cancelled', 'error' => 'Superseded by a newer rhythm fencing token.', ]);
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
                    $this->emit('work.cancelled', [ 'work_item_id' => $work->id, 'parent_run_id' => $run->id, 'reason' => 'parent_cycle_lease_expired', ]);
                }

                $run->setFields([ 'completed_at' => $now, 'status' => 'cancelled', 'error' => 'High-brain execution window expired; cycle was dropped instead of replayed.', ]);
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

    public function extendParentRhythmLease(WorkItem $work, int $leaseUntil): void
    {
        if ($work->parent_run_id === null) {
            return;
        }
        $run = CycleRun::getByID((int) $work->parent_run_id);
        if (!$run instanceof CycleRun || $run->status !== 'running') {
            return;
        }
        $rhythm = Rhythm::getByWhere([ 'id' => $run->rhythm_id, 'status' => 'running', 'fencing_token' => $run->fencing_token, 'lease_owner' => $run->node_id, ]);
        if ($rhythm instanceof Rhythm && ($this->timestamp($rhythm->lease_expires_at) ?? 0) < $leaseUntil) {
            $rhythm->lease_expires_at = $leaseUntil;
            $rhythm->updated_at = time();
            $rhythm->save();
        }
    }

    public function ensureDefaultRhythms(): void
    {
        $defaults = [
            ['rhythm_key' => 'pulse_30s', 'interval_seconds' => 30, 'layer' => CognitiveLayer::LOW],
            ['rhythm_key' => 'intentions_10m', 'interval_seconds' => 600, 'layer' => CognitiveLayer::LOW],
            ['rhythm_key' => 'decide_1m', 'interval_seconds' => 60, 'layer' => CognitiveLayer::HIGH],
            ['rhythm_key' => 'reflect_5m', 'interval_seconds' => 300, 'layer' => CognitiveLayer::HIGH],
            ['rhythm_key' => 'consolidate_hourly', 'interval_seconds' => 3600, 'layer' => CognitiveLayer::HIGH],
            ['rhythm_key' => 'sleep_daily', 'interval_seconds' => 86400, 'layer' => CognitiveLayer::HIGH],
        ];

        $now = time();
        foreach ($defaults as $values) {
            if (Rhythm::getByField('rhythm_key', $values['rhythm_key']) instanceof Rhythm) {
                continue;
            }

            $rhythm = new Rhythm([
                'rhythm_key' => $values['rhythm_key'],
                'interval_seconds' => $values['interval_seconds'],
                'cognitive_layer' => $values['layer']->value,
                'next_due_at' => $now + $values['interval_seconds'],
                'sequence' => 0,
                'fencing_token' => 0,
                'status' => 'idle',
                'updated_at' => $now,
            ], true, true);
            $rhythm->save();
            $this->emit('rhythm.created', [
                'rhythm_id' => $rhythm->id,
                'rhythm_key' => $rhythm->rhythm_key,
                'interval_seconds' => $rhythm->interval_seconds,
                'cognitive_layer' => $rhythm->cognitive_layer,
            ]);
        }
    }
}
