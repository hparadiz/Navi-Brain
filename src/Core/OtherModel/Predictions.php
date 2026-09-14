<?php

declare(strict_types=1);

namespace NaviBrain\Core\OtherModel;

use NaviBrain\Core\OtherModel;

use NaviBrain\Model\OtherModelCycle;
use NaviBrain\Model\OtherModelHypothesis;
use NaviBrain\Model\OtherModelPrediction;
use NaviBrain\Perception\AgentObservationProjector;
use NaviBrain\Core\ProbabilityScorer;

class Predictions extends Component
{

    /**
     * @param list<OtherModelHypothesis> $hypotheses
     * @return list<array<string, mixed>>
     */
    public function sealPredictions(OtherModelCycle $cycle, array $hypotheses): array {
        $observation = is_array($cycle->observation) ? $cycle->observation : [];
        $baseline = is_array($cycle->baseline) ? $cycle->baseline : [];
        $predictions = [];
        if (($observation['observable'] ?? false) === true
            && (array) ($baseline['distribution'] ?? []) !== []
        ) {
            $predictions[] = $this->seal(new OtherModelPrediction([
                'cycle_id' => (int) $cycle->id,
                'hypothesis_id' => null,
                'model_kind' => 'baseline',
                'source_key' => (string) $observation['source_key'],
                'feature_key' => (string) $observation['feature_key'],
                'distribution' => (array) $baseline['distribution'],
                'baseline_distribution' => [],
            ], true, true), (array) $observation['domain']);
        }

        foreach (array_slice($hypotheses, 0, OtherModel::MAX_HYPOTHESES) as $hypothesis) {
            if ($hypothesis->status !== 'active') {
                continue;
            }
            $target = $this->predictionTarget($hypothesis, $observation);
            if ($target === null) {
                continue;
            }
            $targetObservation = [
                'source_key' => $target['source_key'],
                'feature_key' => $target['feature_key'],
                'value' => $this->latestValue($target['source_key'], $target['feature_key'], null, time())
                    ?? $target['domain'][0],
                'domain' => $target['domain'],
            ];
            $context = is_array($baseline['context'] ?? null) ? $baseline['context'] : [];
            $targetBaseline = $this->baseline($cycle, $targetObservation, $context)['distribution'];
            $distribution = $this->hypothesisDistribution($hypothesis, $target, $targetBaseline);
            $predictions[] = $this->seal(new OtherModelPrediction([
                'cycle_id' => (int) $cycle->id,
                'hypothesis_id' => (int) $hypothesis->id,
                'model_kind' => 'hypothesis',
                'source_key' => $target['source_key'],
                'feature_key' => $target['feature_key'],
                'distribution' => $distribution,
                'baseline_distribution' => $targetBaseline,
            ], true, true), $target['domain']);
        }
        return array_values(array_map( static fn (OtherModelPrediction $prediction): array => $prediction->getData(), $predictions ));
    }

    /** @param list<string> $domain */
    public function seal(OtherModelPrediction $prediction, array $domain): OtherModelPrediction {
        $distribution = ProbabilityScorer::normalize((array) $prediction->distribution, $domain);
        $top = ProbabilityScorer::top($distribution);
        $now = time();
        $prediction->setFields([
            'operator' => 'equals',
            'expected_value' => (string) $top['value'],
            'distribution' => $distribution,
            'baseline_distribution' => ProbabilityScorer::normalize((array) $prediction->baseline_distribution, $domain),
            'probability' => (float) $top['probability'],
            'specificity' => round(1.0 - ProbabilityScorer::entropy($distribution), 4),
            'sealed_at' => $now,
            'deadline' => $now + $this->predictionHorizon((string) $prediction->source_key),
            'status' => 'pending',
        ]);
        $prediction->save();
        return $prediction;
    }

    /**
     * @param array<string, mixed> $observation
     * @return list<array<string, mixed>>
     */
    public function resolvePredictions(OtherModelCycle $cycle, array $observation): array {
        $source = (string) ($observation['source_key'] ?? '');
        $feature = (string) ($observation['feature_key'] ?? '');
        $observed = (string) ($observation['value'] ?? '');
        $observedAt = $this->timestamp($observation['observed_at'] ?? null) ?? time();
        $resolved = [];

        foreach (OtherModelPrediction::getAllByWhere(['status' => 'pending'], ['order' => ['id' => 'ASC'], 'limit' => 500]) as $prediction) {
            $deadline = $this->timestamp($prediction->deadline) ?? 0;
            if ($deadline < $observedAt) {
                $prediction->setField('status', 'expired');
                $prediction->save();
                continue;
            }
            if ($prediction->source_key !== $source || $prediction->feature_key !== $feature) {
                continue;
            }
            $sealedAt = $this->timestamp($prediction->sealed_at) ?? PHP_INT_MAX;
            if ($sealedAt >= $observedAt || $observed === '') {
                continue;
            }
            $domain = AgentObservationProjector::domain($feature);
            if ($domain === [] || !in_array($observed, $domain, true)) {
                $prediction->setFields([ 'observed_cycle_id' => (int) $cycle->id, 'outcome_value' => mb_substr($observed, 0, 96), 'observed_at' => $observedAt, 'status' => 'unresolvable', ]);
                $prediction->save();
                continue;
            }
            $distribution = ProbabilityScorer::normalize(is_array($prediction->distribution) ? $prediction->distribution : [], $domain);
            $brier = ProbabilityScorer::brier($distribution, $observed);
            $logLoss = ProbabilityScorer::logLoss($distribution, $observed);
            $status = $prediction->expected_value === $observed ? 'matched' : 'violated';
            $prediction->setFields([
                'observed_cycle_id' => (int) $cycle->id,
                'outcome_value' => $observed,
                'observed_at' => $observedAt,
                'brier_score' => $brier,
                'log_loss' => $logLoss,
                'status' => $status,
            ]);
            $prediction->save();
            if ($prediction->hypothesis_id !== null) {
                $this->updateHypothesisFromPrediction($prediction, $observed);
            }
            $resolved[] = $prediction->getData();
        }
        return $resolved;
    }

    public function updateHypothesisFromPrediction(OtherModelPrediction $prediction, string $observed): void {
        $hypothesis = OtherModelHypothesis::getByID((int) $prediction->hypothesis_id);
        if (!$hypothesis instanceof OtherModelHypothesis || $hypothesis->status !== 'active') {
            return;
        }
        $distribution = is_array($prediction->distribution) ? $prediction->distribution : [];
        $baseline = is_array($prediction->baseline_distribution) ? $prediction->baseline_distribution : [];
        $likelihood = ProbabilityScorer::probability($distribution, $observed);
        $baselineLikelihood = ProbabilityScorer::probability($baseline, $observed);

        $forecastSupport = max(0.001, min(0.999, (float) $hypothesis->posterior));
        $odds = ($forecastSupport / (1.0 - $forecastSupport)) * max(0.1, min(10.0, $likelihood / $baselineLikelihood));
        $forecastSupport = $odds / (1.0 + $odds);
        $resolved = (int) $hypothesis->predictions_resolved;
        $quality = 1.0 - min(1.0, ((float) $prediction->brier_score) / 2.0);
        $forward = (($resolved * (float) $hypothesis->forward_score) + $quality) / ($resolved + 1);
        $hypothesis->setFields([ 'posterior' => round($forecastSupport, 4), 'forward_score' => round($forward, 4), 'predictions_resolved' => $resolved + 1, 'updated_at' => time(), ]);
        $hypothesis->save();
    }

    /**
     * @param array<string, mixed> $observation
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function baseline(OtherModelCycle $current, array $observation, array $context): array {
        $source = (string) ($observation['source_key'] ?? '');
        $feature = (string) ($observation['feature_key'] ?? '');
        $domain = is_array($observation['domain'] ?? null)
            ? array_values(array_map('strval', $observation['domain']))
            : AgentObservationProjector::domain($feature);
        if ($domain === []) {
            $domain = [(string) ($observation['value'] ?? 'unknown')];
        }
        $contextKey = $this->contextKey($context, $source, $feature);
        $series = $this->observationSeries($source, $feature, (int) $current->id);
        $conditional = [];
        $unconditional = [];
        $transitions = 0;
        $historyUsed = 0;
        foreach ([24, 64, 128, 256, OtherModel::HISTORY_LIMIT] as $window) {
            $windowRows = array_slice($series, -$window);
            $conditional = array_fill_keys($domain, OtherModel::PSEUDOCOUNT);
            $unconditional = array_fill_keys($domain, OtherModel::PSEUDOCOUNT);
            $transitions = 0;
            $previous = null;
            foreach ($windowRows as $row) {
                $value = (string) ($row['value'] ?? '');
                if (isset($unconditional[$value])) {
                    $unconditional[$value]++;
                }
                if ($previous !== null
                    && ($previous['context_key'] ?? null) === $contextKey
                    && isset($conditional[$value])
                ) {
                    $conditional[$value]++;
                    $transitions++;
                }
                $previous = $row;
            }
            $historyUsed = count($windowRows);
            $conditionalEntropy = ProbabilityScorer::entropy(ProbabilityScorer::normalize($conditional, $domain));
            if ($historyUsed === count($series)
                || ($transitions >= 3 && $conditionalEntropy <= 0.85)
            ) {
                break;
            }
        }
        $selected = $transitions >= 3 ? 'context_conditioned' : 'unconditional';
        $counts = $selected === 'context_conditioned' ? $conditional : $unconditional;
        $distribution = ProbabilityScorer::normalize($counts, $domain);
        $persistence = array_fill_keys($domain, OtherModel::PSEUDOCOUNT);
        $currentValue = (string) ($observation['value'] ?? '');
        if (isset($persistence[$currentValue])) {
            $persistence[$currentValue] += 4.0;
        }
        return [
            'model' => $selected,
            'pseudocount' => OtherModel::PSEUDOCOUNT,
            'context' => $context,
            'context_key' => $contextKey,
            'matching_transitions' => $transitions,
            'history_observations' => $historyUsed,
            'counts' => $counts,
            'distribution' => $distribution,
            'unconditional_distribution' => ProbabilityScorer::normalize($unconditional, $domain),
            'persistence_distribution' => ProbabilityScorer::normalize($persistence, $domain),
        ];
    }

    /**
     * @param array<string, mixed> $observation
     * @return array<string, mixed>
     */
    public function context(OtherModelCycle $current, array $observation): array {
        $observedAt = $this->timestamp($observation['observed_at'] ?? null) ?? time();
        $feature = (string) ($observation['feature_key'] ?? '');
        $reachabilityObservation = $feature === 'reachability'
            ? (string) ($observation['value'] ?? 'unknown')
            : $this->latestValue('desktop_presence', 'reachability', (int) $current->id, $observedAt);
        $speechObservation = $feature === 'speech_state'
            ? (string) ($observation['value'] ?? 'quiet')
            : $this->latestValue('pet_hearing', 'speech_state', (int) $current->id, $observedAt);
        $conversationObservation = $feature === 'conversation_activity'
            ? (string) ($observation['value'] ?? 'unknown')
            : $this->latestValue('conversation_activity', 'conversation_activity', (int) $current->id, $observedAt);
        $reachability = $reachabilityObservation ?? 'unknown';
        $speech = $speechObservation ?? 'quiet';
        $conversation = $conversationObservation ?? 'unknown';
        $attention = match (true) {
            $reachability === 'away' => 'away',
            $speech === 'ambient_speech' => 'occupied',
            $speech === 'direct_speech' => 'conversing',
            $reachability === 'present' && in_array($conversation, ['recent', 'cooling'], true) => 'occupied',
            $reachability === 'present' => 'available',
            default => 'uncertain',
        };
        return [
            'reachability' => $reachability,
            'attention_mode' => $attention,
            'speech_state' => $speech,
            'conversation_activity' => $conversation,
            'source_dependencies' => array_values(array_filter([
                $reachabilityObservation === null ? null : 'desktop_presence',
                $speechObservation === null ? null : 'pet_hearing',
                $conversationObservation === null ? null : 'conversation_activity',
            ])),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function observationSeries(string $source, string $feature, int $beforeCycleId): array {
        $cycles = OtherModelCycle::getAll([ 'order' => ['id' => 'DESC'], 'limit' => OtherModel::HISTORY_LIMIT, ]);
        $rows = [];
        foreach (array_reverse($cycles) as $cycle) {
            if ((int) $cycle->id >= $beforeCycleId
                || !in_array($cycle->status, ['completed', 'abstained'], true)
            ) {
                continue;
            }
            $observation = is_array($cycle->observation) ? $cycle->observation : [];
            if (($observation['source_key'] ?? null) !== $source
                || ($observation['feature_key'] ?? null) !== $feature
            ) {
                continue;
            }
            $baseline = is_array($cycle->baseline) ? $cycle->baseline : [];
            $rows[] = [
                'value' => (string) ($observation['value'] ?? ''),
                'context_key' => (string) ($baseline['context_key'] ?? ''),
            ];
        }
        return $rows;
    }

    public function latestValue(string $source, string $feature, ?int $beforeCycleId = null, ?int $at = null): ?string {
        if (!$this->sourceIsActive($source)) {
            return null;
        }
        foreach (OtherModelCycle::getAll([ 'order' => ['id' => 'DESC'], 'limit' => 200, ]) as $cycle) {
            if ($beforeCycleId !== null && (int) $cycle->id >= $beforeCycleId) {
                continue;
            }
            if (!in_array($cycle->status, ['completed', 'abstained'], true)) {
                continue;
            }
            $observation = is_array($cycle->observation) ? $cycle->observation : [];
            if (($observation['source_key'] ?? null) === $source
                && ($observation['feature_key'] ?? null) === $feature
            ) {
                $observedAt = $this->timestamp($observation['observed_at'] ?? null);
                if ($at !== null
                    && ($observedAt === null || ($at - $observedAt) > $this->stateTtl($source))
                ) {
                    return null;
                }
                return (string) ($observation['value'] ?? '');
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $observation
     * @return array<string, mixed>|null
     */
    public function predictionTarget(OtherModelHypothesis $hypothesis, array $observation): ?array {
        return match ((string) $hypothesis->kind) {
            'goal' => [
                'source_key' => 'conversation_activity',
                'feature_key' => 'conversation_activity',
                'domain' => AgentObservationProjector::domain('conversation_activity'),
            ],
            'plan' => [
                'source_key' => 'shell_activity',
                'feature_key' => 'shell_activity',
                'domain' => AgentObservationProjector::domain('shell_activity'),
            ],
            'belief', 'constraint' => [
                'source_key' => 'pet_hearing',
                'feature_key' => 'speech_state',
                'domain' => AgentObservationProjector::domain('speech_state'),
            ],
            'attention', 'error' => isset($observation['source_key'], $observation['feature_key']) ? [
                'source_key' => (string) $observation['source_key'],
                'feature_key' => (string) $observation['feature_key'],
                'domain' => is_array($observation['domain'] ?? null) ? $observation['domain'] : [],
            ] : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, float> $baseline
     * @return array<string, float>
     */
    public function hypothesisDistribution(OtherModelHypothesis $hypothesis, array $target, array $baseline): array {
        $weights = $baseline;
        $boost = match ((string) $hypothesis->kind) {
            'goal' => ['recent' => 2.0, 'cooling' => 0.5],
            'plan' => ['recent' => 2.0, 'cooling' => 0.5],
            'belief' => ['direct_speech' => 0.75, 'quiet' => 0.25],
            'constraint' => ['quiet' => 1.25],
            'error' => [],
            default => [],
        };
        foreach ($boost as $value => $amount) {
            if (array_key_exists($value, $weights)) {
                $weights[$value] += $amount;
            }
        }
        if ($hypothesis->kind === 'error') {
            $weights = array_map(static fn (float $value): float => sqrt(max(0.0, $value)), $weights);
        }
        return ProbabilityScorer::normalize($weights, $target['domain']);
    }

    /** @param array<string, mixed> $context */
    public function contextKey(array $context, string $source, string $feature): string {
        return implode('|', [
            'source=' . $source,
            'feature=' . $feature,
            'reachability=' . (string) ($context['reachability'] ?? 'unknown'),
            'attention=' . (string) ($context['attention_mode'] ?? 'uncertain'),
        ]);
    }

    public function predictionHorizon(string $source): int {
        return match ($source) {
            'pet_hearing', 'utterance_outcome' => 180,
            'desktop_presence', 'input_activity' => 90,
            default => 180,
        };
    }
}
