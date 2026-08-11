<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Model\Event;
use NaviBrain\Model\OtherModelCycle;
use NaviBrain\Model\OtherModelHypothesis;
use NaviBrain\Model\OtherModelPrediction;

/** Read-only replay and calibration report over the sealed other-model ledger. */
final class OtherModelEvaluator
{
    /** @return array<string, mixed> */
    public function report(int $limit = 500): array
    {
        $cycles = array_reverse(OtherModelCycle::getAll([
            'order' => ['id' => 'DESC'],
            'limit' => $limit,
        ]));
        $predictions = OtherModelPrediction::getAll([
            'order' => ['id' => 'DESC'],
            'limit' => min(2000, $limit * 4),
        ]);

        $resolved = array_values(array_filter(
            $predictions,
            static fn (OtherModelPrediction $prediction): bool => in_array(
                $prediction->status,
                ['matched', 'violated'],
                true
            )
        ));
        $byModel = [];
        $byParserModel = [];
        foreach ($resolved as $prediction) {
            $key = (string) $prediction->model_kind;
            $byModel[$key] ??= [
                'resolved' => 0,
                'matched' => 0,
                'brier_sum' => 0.0,
                'log_loss_sum' => 0.0,
                'calibration' => [],
            ];
            $byModel[$key]['resolved']++;
            $byModel[$key]['matched'] += $prediction->status === 'matched' ? 1 : 0;
            $byModel[$key]['brier_sum'] += (float) $prediction->brier_score;
            $byModel[$key]['log_loss_sum'] += (float) $prediction->log_loss;
            $probability = max(0.0, min(1.0, (float) $prediction->probability));
            $bin = min(4, (int) floor($probability * 5));
            $byModel[$key]['calibration'][$bin] ??= ['count' => 0, 'probability_sum' => 0.0, 'correct' => 0];
            $byModel[$key]['calibration'][$bin]['count']++;
            $byModel[$key]['calibration'][$bin]['probability_sum'] += $probability;
            $byModel[$key]['calibration'][$bin]['correct'] += $prediction->status === 'matched' ? 1 : 0;
            if ($prediction->hypothesis_id !== null) {
                $hypothesis = OtherModelHypothesis::getByID((int) $prediction->hypothesis_id);
                $origin = $hypothesis instanceof OtherModelHypothesis
                    ? OtherModelCycle::getByID((int) $hypothesis->origin_cycle_id)
                    : null;
                $parserModel = $origin instanceof OtherModelCycle && trim((string) $origin->model_id) !== ''
                    ? (string) $origin->model_id
                    : 'deterministic_parser';
                $byParserModel[$parserModel] ??= ['resolved' => 0, 'brier' => 0.0, 'log_loss' => 0.0];
                $byParserModel[$parserModel]['resolved']++;
                $byParserModel[$parserModel]['brier'] += (float) $prediction->brier_score;
                $byParserModel[$parserModel]['log_loss'] += (float) $prediction->log_loss;
            }
        }
        foreach ($byModel as $key => $row) {
            $count = max(1, (int) $row['resolved']);
            $calibrationError = 0.0;
            $bins = [];
            ksort($row['calibration']);
            foreach ($row['calibration'] as $index => $bin) {
                $binCount = max(1, (int) $bin['count']);
                $meanProbability = $bin['probability_sum'] / $binCount;
                $empirical = $bin['correct'] / $binCount;
                $calibrationError += ($binCount / $count) * abs($meanProbability - $empirical);
                $bins[] = [
                    'range' => sprintf('%.1f-%.1f', $index / 5, ($index + 1) / 5),
                    'count' => $binCount,
                    'mean_probability' => round($meanProbability, 4),
                    'empirical_accuracy' => round($empirical, 4),
                ];
            }
            $byModel[$key] = [
                'resolved' => $count,
                'accuracy' => round($row['matched'] / $count, 4),
                'mean_brier' => round($row['brier_sum'] / $count, 6),
                'mean_log_loss' => round($row['log_loss_sum'] / $count, 6),
                'expected_calibration_error' => round($calibrationError, 6),
                'calibration_bins' => $bins,
            ];
        }
        foreach ($byParserModel as $model => $row) {
            $count = max(1, (int) $row['resolved']);
            $byParserModel[$model] = [
                'resolved' => (int) $row['resolved'],
                'mean_brier' => round($row['brier'] / $count, 6),
                'mean_log_loss' => round($row['log_loss'] / $count, 6),
            ];
        }

        $terminal = array_values(array_filter(
            $cycles,
            static fn (OtherModelCycle $cycle): bool => in_array(
                $cycle->status,
                ['completed', 'abstained'],
                true
            )
        ));
        $abstained = count(array_filter(
            $terminal,
            static fn (OtherModelCycle $cycle): bool => $cycle->status === 'abstained'
        ));
        $modelCalls = array_sum(array_map(
            static fn (OtherModelCycle $cycle): int => (int) $cycle->model_calls,
            $terminal
        ));
        $hypotheses = OtherModelHypothesis::getAll([
            'order' => ['id' => 'DESC'],
            'limit' => min(2000, $limit * 2),
        ]);
        $integrityViolations = count(array_filter(
            $hypotheses,
            static fn (OtherModelHypothesis $hypothesis): bool => $hypothesis->actor !== 'primary_user'
                || (int) $hypothesis->recursion_order !== 1
                || ($hypothesis->representation === 'stated' && $hypothesis->knowledge_access !== 'reported')
        ));
        $forward = array_values(array_filter(array_map(
            static fn (OtherModelHypothesis $hypothesis): ?float =>
                (int) $hypothesis->predictions_resolved > 0 ? (float) $hypothesis->forward_score : null,
            $hypotheses
        ), static fn (?float $value): bool => $value !== null));
        $backward = array_values(array_map(
            static fn (OtherModelHypothesis $hypothesis): float => (float) $hypothesis->backward_score,
            $hypotheses
        ));
        $correctionLatencies = array_values(array_filter(array_map(
            fn (OtherModelHypothesis $hypothesis): ?int => $hypothesis->status === 'corrected'
                ? max(0, ($this->timestamp($hypothesis->corrected_at) ?? 0)
                    - ($this->timestamp($hypothesis->created_at) ?? 0))
                : null,
            $hypotheses
        ), static fn (?int $value): bool => $value !== null));

        return [
            'protocol' => 'other-model-replay-v1',
            'sealed_predictions' => [
                'pending' => count(array_filter(
                    $predictions,
                    static fn (OtherModelPrediction $prediction): bool => $prediction->status === 'pending'
                )),
                'resolved' => count($resolved),
                'expired' => count(array_filter(
                    $predictions,
                    static fn (OtherModelPrediction $prediction): bool => $prediction->status === 'expired'
                )),
                'by_model' => $byModel,
                'hypothesis_predictions_by_parser_model' => $byParserModel,
            ],
            'online_baselines' => $this->onlineBaselines($terminal),
            'inference' => [
                'terminal_cycles' => count($terminal),
                'abstained_cycles' => $abstained,
                'no_rational_interpretation_rate' => $terminal === []
                    ? null
                    : round($abstained / count($terminal), 4),
                'model_calls' => $modelCalls,
                'model_calls_per_cycle' => $terminal === []
                    ? null
                    : round($modelCalls / count($terminal), 4),
                'live_usable_hypotheses' => count(OtherModelHypothesis::getAllByWhere([
                    'status' => 'active',
                    'validation' => 'usable',
                ])),
                'corrections' => count(OtherModelHypothesis::getAllByWhere(['status' => 'corrected'])),
                'mean_hypothesis_forward_score' => $forward === []
                    ? null
                    : round(array_sum($forward) / count($forward), 4),
                'mean_hypothesis_backward_score' => $backward === []
                    ? null
                    : round(array_sum($backward) / count($backward), 4),
                'mean_correction_latency_seconds' => $correctionLatencies === []
                    ? null
                    : round(array_sum($correctionLatencies) / count($correctionLatencies), 2),
            ],
            'proposition_pipeline' => [
                'worker_extractions' => count(array_filter(
                    $hypotheses,
                    static fn (OtherModelHypothesis $hypothesis): bool => $hypothesis->provenance_kind === 'worker_proposal'
                )),
                'deterministic_extractions' => count(array_filter(
                    $hypotheses,
                    static fn (OtherModelHypothesis $hypothesis): bool => $hypothesis->provenance_kind === 'direct_statement'
                )),
                'prediction_validated_labels' => count(array_filter(
                    $hypotheses,
                    static fn (OtherModelHypothesis $hypothesis): bool => $hypothesis->validation === 'usable'
                )),
                'extraction_recall' => null,
                'label_accuracy' => null,
                'why_unscored' => 'Explicit correction fixtures are required; an LLM is never used to label its own ground truth.',
            ],
            'integrity' => [
                'actor_order_or_access_violations' => $integrityViolations,
                'database_guards_installed' => true,
            ],
            'functional_uptake' => $this->functionalUptake($limit),
            'guardrails' => [
                'ground_truth_source' => 'explicit_user_correction_or_machine_observation_only',
                'engagement_used_as_reward' => false,
                'recursive_depth' => 1,
                'literal_prediction_and_functional_uptake_reported_separately' => true,
            ],
        ];
    }

    /** @param list<OtherModelCycle> $cycles @return array<string, mixed> */
    private function onlineBaselines(array $cycles): array
    {
        $series = [];
        foreach ($cycles as $cycle) {
            $observation = is_array($cycle->observation) ? $cycle->observation : [];
            $source = (string) ($observation['source_key'] ?? '');
            $feature = (string) ($observation['feature_key'] ?? '');
            $value = (string) ($observation['value'] ?? '');
            $domain = is_array($observation['domain'] ?? null)
                ? array_values(array_map('strval', $observation['domain']))
                : [];
            if ($source === '' || $feature === '' || $value === '' || $domain === []) {
                continue;
            }
            $key = $source . '|' . $feature;
            $series[$key][] = [
                'value' => $value,
                'domain' => $domain,
                'context' => (string) (($cycle->baseline['context_key'] ?? '')),
            ];
        }

        $scores = [
            'categorical_persistence' => ['n' => 0, 'brier' => 0.0, 'log_loss' => 0.0],
            'unconditional_counts' => ['n' => 0, 'brier' => 0.0, 'log_loss' => 0.0],
            'context_conditioned_counts' => ['n' => 0, 'brier' => 0.0, 'log_loss' => 0.0],
        ];
        foreach ($series as $rows) {
            $unconditional = [];
            $contextual = [];
            $previous = null;
            foreach ($rows as $row) {
                $domain = $row['domain'];
                foreach ($domain as $value) {
                    $unconditional[$value] ??= 1.0;
                }
                if ($previous !== null) {
                    $persistence = array_fill_keys($domain, 1.0);
                    if (isset($persistence[$previous['value']])) {
                        $persistence[$previous['value']] += 4.0;
                    }
                    $this->addScore($scores['categorical_persistence'], $persistence, $domain, $row['value']);
                    $this->addScore($scores['unconditional_counts'], $unconditional, $domain, $row['value']);
                    $contextual[$previous['context']] ??= array_fill_keys($domain, 1.0);
                    $this->addScore(
                        $scores['context_conditioned_counts'],
                        $contextual[$previous['context']],
                        $domain,
                        $row['value']
                    );
                    if (isset($contextual[$previous['context']][$row['value']])) {
                        $contextual[$previous['context']][$row['value']]++;
                    }
                }
                if (isset($unconditional[$row['value']])) {
                    $unconditional[$row['value']]++;
                }
                $previous = $row;
            }
        }

        foreach ($scores as $name => $row) {
            $n = max(1, (int) $row['n']);
            $scores[$name] = [
                'resolved' => (int) $row['n'],
                'mean_brier' => $row['n'] === 0 ? null : round($row['brier'] / $n, 6),
                'mean_log_loss' => $row['n'] === 0 ? null : round($row['log_loss'] / $n, 6),
            ];
        }
        return $scores;
    }

    /** @param array<string, int|float> $score @param array<string, int|float> $weights
     *  @param list<string> $domain
     */
    private function addScore(array &$score, array $weights, array $domain, string $observed): void
    {
        $distribution = ProbabilityScorer::normalize($weights, $domain);
        $score['n']++;
        $score['brier'] += ProbabilityScorer::brier($distribution, $observed);
        $score['log_loss'] += ProbabilityScorer::logLoss($distribution, $observed);
    }

    /** @return array<string, mixed> */
    private function functionalUptake(int $limit): array
    {
        $decisions = [];
        foreach (Event::getAllByWhere(
            ['kind' => 'action.selected'],
            ['order' => ['id' => 'DESC'], 'limit' => min(2000, $limit)]
        ) as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            if (!isset($payload['baseline_chosen'], $payload['counterfactual_other_model_chosen'])) {
                continue;
            }
            $decisions[] = $payload;
        }
        $changed = count(array_filter(
            $decisions,
            static fn (array $decision): bool => $decision['baseline_chosen']
                !== $decision['counterfactual_other_model_chosen']
        ));
        $actualCounterfactualMismatch = count(array_filter(
            $decisions,
            static fn (array $decision): bool => $decision['chosen']
                !== $decision['counterfactual_other_model_chosen']
        ));
        $modelDeltas = [];
        $actualDeltas = [];
        foreach ($decisions as $decision) {
            $baseline = is_array($decision['baseline_action_distribution'] ?? null)
                ? $decision['baseline_action_distribution']
                : [];
            $counterfactual = is_array($decision['counterfactual_other_model_action_distribution'] ?? null)
                ? $decision['counterfactual_other_model_action_distribution']
                : [];
            $actual = is_array($decision['action_distribution'] ?? null)
                ? $decision['action_distribution']
                : [];
            if ($baseline !== [] && $counterfactual !== []) {
                $modelDeltas[] = $this->totalVariation($baseline, $counterfactual);
            }
            if ($actual !== [] && $counterfactual !== []) {
                $actualDeltas[] = $this->totalVariation($actual, $counterfactual);
            }
        }
        return [
            'decisions_with_counterfactual' => count($decisions),
            'model_would_change_action' => $changed,
            'functional_action_distribution_delta' => $decisions === []
                ? null
                : round(array_sum($modelDeltas) / max(1, count($modelDeltas)), 4),
            'action_choice_change_rate' => $decisions === []
                ? null
                : round($changed / count($decisions), 4),
            'counterfactual_arbiter_mismatches' => $actualCounterfactualMismatch,
            'actual_counterfactual_distribution_delta' => $actualDeltas === []
                ? null
                : round(array_sum($actualDeltas) / count($actualDeltas), 4),
            'actual_differs_only_when_ablation_enabled' => true,
        ];
    }

    /** @param array<string, int|float> $left @param array<string, int|float> $right */
    private function totalVariation(array $left, array $right): float
    {
        $keys = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
        $sum = 0.0;
        foreach ($keys as $key) {
            $sum += abs((float) ($left[$key] ?? 0.0) - (float) ($right[$key] ?? 0.0));
        }
        return 0.5 * $sum;
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        $parsed = is_string($value) ? strtotime($value) : false;
        return $parsed === false ? null : $parsed;
    }
}
