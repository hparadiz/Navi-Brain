<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Model\CapsuleSlot;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\ContextCapsule;
use NaviBrain\Model\MetricSnapshot;
use NaviBrain\Model\ThreadStep;
use RuntimeException;

class ContextMetrics extends Component
{
    public function listContextCapsules(?int $threadId = null, int $limit = 10): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }
        $conditions = $threadId === null ? [] : ['thread_id' => $threadId];
        return $this->records(ContextCapsule::getAllByWhere( $conditions, ['order' => ['id' => 'DESC'], 'limit' => $limit] ));
    }

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

        $serialized = $this->executive->capsuleAssembler->serialize((int) $capsule->id);
        $slots = $this->records(CapsuleSlot::getAllByWhere( ['capsule_id' => (int) $capsule->id], ['order' => ['id' => 'ASC']] ));

        return [
            'capsule' => $capsule->getData(),
            'checksum_intact' => hash_equals((string) $capsule->checksum, $this->executive->capsuleAssembler->checksum($serialized)),
            'max_carryover_depth' => $this->executive->capsuleAssembler->maxCarryoverDepth((int) $capsule->id),
            'serialized' => $serialized,
            'slots' => $slots,
        ];
    }

    public function recordMetricSnapshot(string $nodeId, ?string $scopeKey = null): array
    {
        $this->requireText($nodeId, 'node id');
        $manifest = $this->metricsManifest(Executive::METRICS_PROTOCOL_V1);
        $vector = $this->computeMetricVector($scopeKey);
        ksort($vector);
        $checksum = hash('sha256', implode("\n", [ Executive::METRICS_PROTOCOL_V1, $manifest, json_encode($vector, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ]));

        $snapshot = new MetricSnapshot([
            'protocol_version' => Executive::METRICS_PROTOCOL_V1,
            'scope_key' => $scopeKey,
            'node_id' => $nodeId,
            'vector' => $vector,
            'manifest' => $manifest,
            'checksum' => $checksum,
        ], true, true);
        $snapshot->save();
        $event = $this->emit('metrics.recorded', [
            'metric_snapshot_id' => $snapshot->id,
            'protocol_version' => Executive::METRICS_PROTOCOL_V1,
            'scope_key' => $scopeKey,
            'node_id' => $nodeId,
            'checksum' => $checksum,
        ]);
        return ['snapshot' => $snapshot->getData(), 'event' => $event->getData()];
    }

    public function metricsReport(int $limit = 10, ?string $scopeKey = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }
        $conditions = ['protocol_version' => Executive::METRICS_PROTOCOL_V1];
        if ($scopeKey !== null) {
            $conditions['scope_key'] = $scopeKey;
        }
        $snapshots = MetricSnapshot::getAllByWhere($conditions, ['order' => ['created_at' => 'DESC', 'id' => 'DESC'], 'limit' => $limit]);

        $history = [];
        $tampered = [];
        foreach ($snapshots as $snapshot) {
            $vector = is_array($snapshot->vector) ? $snapshot->vector : [];
            ksort($vector);
            $expected = hash('sha256', implode("\n", [ (string) $snapshot->protocol_version, (string) $snapshot->manifest, json_encode($vector, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ]));
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
            'protocol_version' => Executive::METRICS_PROTOCOL_V1,
            'manifest' => $this->metricsManifest(Executive::METRICS_PROTOCOL_V1),
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

    public function metricsManifest(string $protocolVersion): string
    {
        if ($protocolVersion !== Executive::METRICS_PROTOCOL_V1) {
            throw new InvalidArgumentException('Unknown metrics protocol version: ' . $protocolVersion);
        }
        $manifest = Executive::METRICS_MANIFEST_V1;
        if (!hash_equals(Executive::METRICS_MANIFEST_V1_SHA256, hash('sha256', $manifest))) {
            throw new RuntimeException('The cognitive-v1 metrics manifest was edited in place; mint a new protocol version instead.');
        }
        return $manifest;
    }

    public function computeMetricVector(?string $scopeKey): array
    {
        $threads = $scopeKey === null
            ? CognitiveThread::getAll()
            : CognitiveThread::getAllByWhere(['thread_key' => $scopeKey]);
        $threadIds = array_map(static fn (CognitiveThread $thread): int => (int) $thread->id, $threads);

        $stepsByThread = [];
        foreach ($threadIds as $threadId) {
            $stepsByThread[$threadId] = ThreadStep::getAllByWhere(['thread_id' => $threadId], ['order' => ['id' => 'ASC']]);
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

    public function ratio(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : round($numerator / $denominator, 4);
    }
}
