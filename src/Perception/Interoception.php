<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use NaviBrain\Core\CodexSparkWorker;
use NaviBrain\Core\ExecutiveCore\Executive;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Model\WorkItem;

class Interoception
{
    public function __construct(private readonly Executive $core)
    {
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $now = time();
        $root = dirname(__DIR__, 2);

        $supervisorAge = null;
        $restarts = null;
        $consecutiveFailures = null;
        $healthPath = $root . '/var/supervisor-health.json';
        if (is_readable($healthPath)) {
            $decoded = json_decode((string) file_get_contents($healthPath), true);
            if (is_array($decoded)) {
                $lastOutput = (int) ($decoded['last_output_at'] ?? 0);
                $supervisorAge = $lastOutput > 0 ? $now - $lastOutput : null;
                $restarts = (int) ($decoded['restart_count'] ?? 0);
                $consecutiveFailures = (int) ($decoded['consecutive_failures'] ?? 0);
            }
        }

        $modelHealthy = false;
        foreach ($this->core->listModelEndpoints() as $endpoint) {
            if (($endpoint['model_id'] ?? null) !== CodexSparkWorker::MODEL_ID) {
                continue;
            }
            $modelHealthy = ($endpoint['status'] ?? null) === 'available'
                && (int) ($endpoint['consecutive_failures'] ?? 0) === 0;
            break;
        }

        $dbPath = getenv('NAVI_BRAIN_DB') ?: $root . '/var/navi-brain.sqlite';
        $dbBytes = is_readable($dbPath) ? (int) filesize($dbPath) : 0;

        $recentFailures = 0;
        $recentTotal = 0;
        foreach (WorkItem::getAll(['order' => ['id' => 'DESC'], 'limit' => 20]) as $work) {
            if (!in_array($work->status, ['completed', 'failed'], true)) {
                continue;
            }
            $recentTotal++;
            if ($work->status === 'failed') {
                $recentFailures++;
            }
        }

        $activeThreads = 0;
        $blockedThreads = 0;
        foreach (CognitiveThread::getAll() as $thread) {
            if (in_array($thread->status, ['active', 'waiting'], true)) {
                $activeThreads++;
            } elseif ($thread->status === 'blocked') {
                $blockedThreads++;
            }
        }

        $pendingEdges = count(SenseEvent::getAllByWhere( ['outcome' => 'pending'], ['order' => ['id' => 'DESC'], 'limit' => 200] ));

        return [
            'heartbeat_silent_seconds' => $supervisorAge,
            'heartbeat_restarts' => $restarts,
            'heartbeat_consecutive_failures' => $consecutiveFailures,
            'spark_model_healthy' => $modelHealthy,
            'store_megabytes' => (int) round($dbBytes / 1048576),
            'worker_failure_rate' => $recentTotal === 0
                ? 0.0
                : round($recentFailures / $recentTotal, 3),
            'active_threads' => $activeThreads,
            'blocked_threads' => $blockedThreads,
            'pending_edges' => $pendingEdges,
        ];
    }

    /** @return array<string, mixed> */
    public function time(): array
    {
        $now = time();
        $local = new \DateTimeImmutable('@' . $now, new \DateTimeZone('UTC'));
        $local = $local->setTimezone(new \DateTimeZone( (string) (getenv('NAVI_BRAIN_TZ') ?: 'America/Los_Angeles') ));

        return [
            'hour' => (int) $local->format('G'),
            'day_of_week' => (int) $local->format('N'),
            'is_weekend' => in_array((int) $local->format('N'), [6, 7], true),
            'seconds_since_heard' => $this->secondsSinceEdge('heard_speech', $now),
            'seconds_since_any_edge' => $this->secondsSinceAnyEdge($now),
            'seconds_since_spoke' => $this->secondsSinceSpoke($now),
            'seconds_since_thought' => $this->secondsSinceThought($now),
        ];
    }

    private function secondsSinceEdge(string $senseKey, int $now): ?int
    {
        foreach (SenseEvent::getAllByWhere(['sense_key' => $senseKey], ['order' => ['id' => 'DESC'], 'limit' => 1]) as $event) {
            $at = $this->timestamp($event->observed_at);
            return $at === null ? null : max(0, $now - $at);
        }
        return null;
    }

    private function secondsSinceAnyEdge(int $now): ?int
    {
        foreach (SenseEvent::getAll(['order' => ['id' => 'DESC'], 'limit' => 1]) as $event) {
            $at = $this->timestamp($event->observed_at);
            return $at === null ? null : max(0, $now - $at);
        }
        return null;
    }

    private function secondsSinceSpoke(int $now): ?int
    {
        foreach (ThreadStep::getAllByWhere(['status' => 'succeeded'], ['order' => ['id' => 'DESC'], 'limit' => 150]) as $step) {
            $observed = is_array($step->observed_result) ? $step->observed_result : [];
            if (($observed['spoken'] ?? false) === true) {
                $at = $this->timestamp($step->completed_at);
                return $at === null ? null : max(0, $now - $at);
            }
        }
        return null;
    }

    private function secondsSinceThought(int $now): ?int
    {
        foreach (ThreadStep::getAllByWhere(['curator_verdict' => 'accepted'], ['order' => ['id' => 'DESC'], 'limit' => 1]) as $step) {
            $at = $this->timestamp($step->completed_at);
            return $at === null ? null : max(0, $now - $at);
        }
        return null;
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_numeric($value)) {
            return (int) $value;
        }
        $parsed = strtotime((string) $value);
        return $parsed === false ? null : $parsed;
    }
}
