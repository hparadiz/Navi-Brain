<?php

declare(strict_types=1);

namespace NaviBrain\Core\OtherModel;

use NaviBrain\Core\OtherModel;

use InvalidArgumentException;
use NaviBrain\Model\OtherAgentFrameFact;
use NaviBrain\Model\OtherModelCycle;
use NaviBrain\Model\OtherModelHypothesis;
use NaviBrain\Model\OtherModelPrediction;
use NaviBrain\Model\SensorySource;

class Hypotheses extends Component
{

    /**
     * @param array<string, mixed> $observation
     * @return array<string, mixed>|null
     */
    public function deterministicCandidate(array $observation): ?array {
        $statement = trim((string) ($observation['direct_statement'] ?? ''));
        if ($statement === '') {
            return null;
        }
        $patterns = [
            'goal' => '/\b(?:i want|i need|i am trying|i\x{2019}m trying|i\'m trying|we need|let\x{2019}s|let\'s)\s+(.+)/iu',
            'belief' => '/\b(?:i think|i believe|i know|i suspect)\s+(.+)/iu',
            'plan' => '/\b(?:i am going to|i\x{2019}m going to|i\'m going to|i plan to|we are going to|we\x{2019}re going to|we\'re going to)\s+(.+)/iu',
            'constraint' => '/\b(?:i cannot|i can\x{2019}t|i can\'t|i will not|i won\x{2019}t|i won\'t)\s+(.+)/iu',
        ];
        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $statement, $matches) !== 1) {
                continue;
            }
            $proposition = trim((string) ($matches[1] ?? ''));
            if ($proposition === '') {
                continue;
            }
            return [
                'kind' => $kind,
                'proposition' => $proposition,
                'evidence_quote' => trim((string) $matches[0]),
                'confidence' => 0.95,
                'provenance_kind' => 'direct_statement',
            ];
        }
        return null;
    }

    /** @param array<string, mixed> $candidate */
    public function recordHypothesis(OtherModelCycle $cycle, array $candidate): ?OtherModelHypothesis {
        $kind = (string) ($candidate['kind'] ?? '');
        $proposition = mb_substr(trim((string) ($candidate['proposition'] ?? '')), 0, 600);
        if (!in_array($kind, ['attention', 'goal', 'belief', 'plan', 'error', 'constraint'], true)
            || $proposition === ''
        ) {
            return null;
        }
        $normalized = $this->normalizeText($proposition);
        foreach ($this->liveHypotheses() as $existing) {
            if ($existing->kind === $kind
                && $this->normalizeText((string) $existing->proposition) === $normalized
            ) {
                $existing->setFields([
                    'posterior' => round(min(0.99, max( (float) $existing->posterior, (float) ($candidate['confidence'] ?? 0.5) )), 4),
                    'expires_at' => time() + $this->hypothesisTtl($kind),
                    'updated_at' => time(),
                ]);
                $existing->save();
                return $existing;
            }
        }

        $observation = is_array($cycle->observation) ? $cycle->observation : [];
        $reported = in_array((string) ($candidate['provenance_kind'] ?? ''), ['direct_statement', 'worker_proposal'], true);
        $prior = $reported
            ? max(0.5, min(0.9, (float) ($candidate['confidence'] ?? 0.65)))
            : max(0.1, min(0.5, (float) ($candidate['confidence'] ?? 0.25)));
        $now = time();
        $this->makeHypothesisRoom();

        $hypothesis = new OtherModelHypothesis([
            'updated_at' => $now,
            'origin_cycle_id' => (int) $cycle->id,
            'actor' => 'primary_user',
            'recursion_order' => 1,
            'kind' => $kind,
            'proposition' => $proposition,
            'structured' => [
                'type' => $kind,
                'grounded_terms' => $this->groundedTerms($proposition, (string) ($observation['direct_statement'] ?? '')),
                'error_mode' => $candidate['error_mode'] ?? null,
                'error_likelihoods' => $candidate['error_likelihoods'] ?? [],
            ],
            'prior' => round($prior, 4),
            'posterior' => round($prior, 4),
            'knowledge_access' => $reported ? 'reported' : 'inferred',
            'representation' => $reported ? 'stated' : 'inferred',
            'provenance_kind' => (string) ($candidate['provenance_kind'] ?? 'deterministic_inference'),
            'provenance_id' => $observation['reading_id'] ?? $observation['outcome_id'] ?? null,
            'evidence' => [
                'cycle_id' => (int) $cycle->id,
                'quote' => mb_substr((string) ($candidate['evidence_quote'] ?? ''), 0, 600),
                'observation' => $observation['evidence'] ?? [],
                'prediction_ids' => $candidate['prediction_ids'] ?? [],
            ],
            'forward_score' => 0.0,

            'backward_score' => $reported ? 0.9 : 0.65,
            'predictions_resolved' => 0,
            'validation' => 'pending',
            'status' => 'active',
            'valid_from' => $now,
            'expires_at' => $now + $this->hypothesisTtl($kind),
        ], true, true);
        $hypothesis->save();
        $this->enforceHypothesisLimit();
        $this->core->emitEvent('other_model.hypothesis.created', [
            'hypothesis_id' => (int) $hypothesis->id,
            'cycle_id' => (int) $cycle->id,
            'kind' => $kind,
            'representation' => $reported ? 'stated' : 'inferred',
            'usable' => false,
        ]);
        return $hypothesis;
    }

    /** @param list<array<string, mixed>> $resolved */
    public function shouldRepresentError(array $resolved): bool {
        $violated = array_filter($resolved, static fn (array $row): bool => ($row['status'] ?? null) === 'violated' && (float) ($row['log_loss'] ?? 0.0) >= 1.2);
        return count($violated) >= 2;
    }

    /**
     * @param list<array<string, mixed>> $resolved
     * @return array<string, mixed>
     */
    public function errorCandidate(array $resolved): array {
        $ids = array_values(array_map( static fn (array $row): int => (int) ($row['id'] ?? 0), array_filter($resolved, static fn (array $row): bool => ($row['status'] ?? null) === 'violated') ));
        $violations = count($ids);
        $matches = count(array_filter( $resolved, static fn (array $row): bool => ($row['status'] ?? null) === 'matched' ));
        $likelihoods = [
            'action_slip' => $matches > 0 ? 0.35 : 0.1,
            'bounded_plan_failure' => $violations >= 2 ? 0.4 : 0.25,
            'temporary_goal_confusion' => 0.2,
            'genuine_goal_change' => $violations >= 3 ? 0.35 : 0.15,
        ];
        $disabled = array_values(array_filter(array_map( 'trim', explode(',', (string) (getenv('NAVI_BRAIN_OTHER_MODEL_ERROR_ABLATE') ?: '')) )));
        foreach ($disabled as $mode) {
            if (array_key_exists($mode, $likelihoods)) {
                $likelihoods[$mode] = 0.0;
            }
        }
        $sum = array_sum($likelihoods);
        if ($sum > 0.0) {
            $likelihoods = array_map(static fn (float $value): float => round($value / $sum, 4), $likelihoods);
        }
        arsort($likelihoods);
        $leadingMode = (string) array_key_first($likelihoods);
        return [
            'kind' => 'error',
            'proposition' => 'Recent behaviour did not fit the active bounded plan model; the error mode remains uncertain.',
            'evidence_quote' => '',
            'confidence' => 0.3,
            'provenance_kind' => 'deterministic_inference',
            'error_mode' => $leadingMode,
            'error_likelihoods' => $likelihoods,
            'prediction_ids' => $ids,
        ];
    }

    /** @param list<OtherModelHypothesis> $hypotheses */
    public function validateHypotheses(array $hypotheses): void {
        foreach ($hypotheses as $hypothesis) {
            $resolved = (int) $hypothesis->predictions_resolved;
            $forward = (float) $hypothesis->forward_score;
            $posterior = (float) $hypothesis->posterior;

            $hasProvenance = (float) $hypothesis->backward_score > 0.0
                && trim((string) $hypothesis->provenance_kind) !== '';
            $validation = 'pending';
            $status = 'active';
            if ($resolved >= 1
                && $hasProvenance
                && $forward >= OtherModel::USABLE_FORWARD_SCORE
                && $posterior >= OtherModel::USABLE_POSTERIOR
            ) {
                $validation = 'usable';
            } elseif ($resolved >= 2 && ($forward < 0.25 || $posterior < 0.2)) {
                $validation = 'invalid';
                $status = 'rejected';
            }
            if ($hypothesis->validation !== $validation || $hypothesis->status !== $status) {
                $hypothesis->setFields([ 'validation' => $validation, 'status' => $status, 'updated_at' => time(), ]);
                $hypothesis->save();
            }
        }
    }

    /** @param list<OtherModelHypothesis> $hypotheses */
    public function selectHypothesis(array $hypotheses): ?OtherModelHypothesis {
        $usable = array_values(array_filter(
            $hypotheses,
            static fn (OtherModelHypothesis $hypothesis): bool => $hypothesis->status === 'active'
                && $hypothesis->validation === 'usable'
                && (float) $hypothesis->posterior >= OtherModel::USABLE_POSTERIOR
        ));
        usort($usable, static fn (OtherModelHypothesis $left, OtherModelHypothesis $right): int =>
            (float) $right->posterior <=> (float) $left->posterior
                ?: (float) $right->forward_score <=> (float) $left->forward_score
                ?: (int) $right->id <=> (int) $left->id
        );
        return $usable[0] ?? null;
    }

    /**
     * @param list<OtherModelHypothesis> $hypotheses
     * @return array<string, mixed>
     */
    public function publishedState(OtherModelCycle $cycle, array $hypotheses, ?OtherModelHypothesis $selected): array {
        $observation = is_array($cycle->observation) ? $cycle->observation : [];
        $context = is_array($cycle->baseline) ? (array) ($cycle->baseline['context'] ?? []) : [];
        $observedAt = $this->timestamp($observation['observed_at'] ?? null) ?? time();
        $ttl = $this->stateTtl((string) ($observation['source_key'] ?? ''));
        $reportedPending = [];
        foreach ($hypotheses as $hypothesis) {
            if ($hypothesis->status !== 'active'
                || $hypothesis->representation !== 'stated'
                || $hypothesis->validation === 'usable'
            ) {
                continue;
            }
            $reportedPending[] = [
                'id' => (int) $hypothesis->id,
                'kind' => (string) $hypothesis->kind,
                'proposition' => (string) $hypothesis->proposition,
                'status' => 'reported_not_prediction_validated',
                'assessment' => $this->hypothesisAssessment($hypothesis->getData()),
                'expires_at' => $this->timestamp($hypothesis->expires_at),
            ];
        }
        $sourceDependencies = is_array($context['source_dependencies'] ?? null)
            ? array_values(array_map('strval', $context['source_dependencies']))
            : [];
        $triggerSource = (string) ($observation['source_key'] ?? '');
        if ($triggerSource !== '' && SensorySource::getByField('source_key', $triggerSource) instanceof SensorySource) {
            $sourceDependencies[] = $triggerSource;
        }
        $sourceDependencies = array_values(array_unique($sourceDependencies));

        return [
            'actor' => 'primary_user',
            'recursion_order' => 1,
            'cycle_id' => (int) $cycle->id,
            'observed_at' => $observedAt,
            'expires_at' => $observedAt + $ttl,
            'reachability' => $context['reachability'] ?? 'unknown',
            'attention_mode' => $context['attention_mode'] ?? 'uncertain',
            'confidence' => round((float) ($cycle->observability['weight'] ?? 0.0), 4),
            'assessment_semantics' => OtherModel::ASSESSMENT_SEMANTICS,
            'selected_hypothesis' => $selected instanceof OtherModelHypothesis ? [
                'id' => (int) $selected->id,
                'kind' => (string) $selected->kind,
                'proposition' => (string) $selected->proposition,
                'posterior' => (float) $selected->posterior,
                'forward_score' => (float) $selected->forward_score,
                'backward_score' => (float) $selected->backward_score,
                'assessment' => $this->hypothesisAssessment($selected->getData()),
                'expires_at' => $this->timestamp($selected->expires_at),
            ] : null,
            'reported_not_validated' => array_slice($reportedPending, 0, OtherModel::MAX_HYPOTHESES),
            'abstention_reason' => $selected instanceof OtherModelHypothesis
                ? null
                : 'no_rational_interpretation',
            'provenance' => [
                'trigger_kind' => (string) $cycle->trigger_kind,
                'trigger_source' => (string) $cycle->trigger_source,
                'trigger_id' => (int) $cycle->trigger_id,
                'source_dependencies' => $sourceDependencies,
                'mental_state_observed' => false,
            ],
        ];
    }

    /** @return list<OtherModelHypothesis> */
    public function liveHypotheses(): array {
        $this->expireState(time());
        $rows = OtherModelHypothesis::getAllByWhere(['actor' => 'primary_user', 'status' => 'active'], ['order' => ['posterior' => 'DESC'], 'limit' => OtherModel::MAX_HYPOTHESES]);
        return array_values(array_filter( $rows, static fn (OtherModelHypothesis $hypothesis): bool => (int) $hypothesis->recursion_order === 1 ));
    }

    public function enforceHypothesisLimit(): void {
        $rows = OtherModelHypothesis::getAllByWhere(['actor' => 'primary_user', 'status' => 'active'], ['order' => ['posterior' => 'DESC'], 'limit' => 100]);
        foreach (array_slice($rows, OtherModel::MAX_HYPOTHESES) as $hypothesis) {
            $hypothesis->setFields(['status' => 'expired', 'updated_at' => time()]);
            $hypothesis->save();
        }
    }

    public function makeHypothesisRoom(): void {
        $rows = OtherModelHypothesis::getAllByWhere(['actor' => 'primary_user', 'status' => 'active'], ['order' => ['posterior' => 'ASC', 'id' => 'ASC'], 'limit' => 100]);
        while (count($rows) >= OtherModel::MAX_HYPOTHESES) {
            $lowest = array_shift($rows);
            if (!$lowest instanceof OtherModelHypothesis) {
                break;
            }
            $lowest->setFields(['status' => 'expired', 'updated_at' => time()]);
            $lowest->save();
        }
    }

    public function expireState(int $now): void {
        foreach (OtherModelHypothesis::getAllByWhere(['status' => 'active']) as $hypothesis) {
            $expiresAt = $this->timestamp($hypothesis->expires_at);
            if ($expiresAt !== null && $expiresAt <= $now) {
                $hypothesis->setFields(['status' => 'expired', 'updated_at' => $now]);
                $hypothesis->save();
            }
        }
        foreach (OtherAgentFrameFact::getAllByWhere(['status' => 'active']) as $fact) {
            $expiresAt = $this->timestamp($fact->expires_at);
            if ($expiresAt !== null && $expiresAt <= $now) {
                $fact->setFields(['status' => 'expired', 'updated_at' => $now]);
                $fact->save();
            }
        }
    }

    /** @return array<string, mixed> */
    public function correctHypothesis(int $hypothesisId, string $correction): array {
        $hypothesis = OtherModelHypothesis::getByID($hypothesisId);
        if (!$hypothesis instanceof OtherModelHypothesis || $hypothesis->actor !== 'primary_user') {
            throw new InvalidArgumentException('No such primary-user hypothesis.');
        }
        $correction = trim($correction);
        if ($correction === '') {
            throw new InvalidArgumentException('A correction cannot be empty.');
        }
        $now = time();
        $hypothesis->setFields([ 'status' => 'corrected', 'validation' => 'invalid', 'corrected_at' => $now, 'updated_at' => $now, ]);
        $hypothesis->save();
        foreach (OtherModelPrediction::getAllByWhere([ 'hypothesis_id' => $hypothesisId, 'status' => 'pending', ]) as $prediction) {
            $prediction->setField('status', 'expired');
            $prediction->save();
        }

        $fact = new OtherAgentFrameFact([
            'updated_at' => $now,
            'actor' => 'primary_user',
            'recursion_order' => 1,
            'fact_key' => 'correction.hypothesis.' . $hypothesisId,
            'proposition' => mb_substr($correction, 0, 1000),
            'structured' => [
                'corrected_hypothesis_id' => $hypothesisId,
                'rejected_proposition' => (string) $hypothesis->proposition,
            ],
            'confidence' => 1.0,
            'knowledge_access' => 'reported',
            'representation' => 'stated',
            'provenance_kind' => 'user_correction',
            'provenance_id' => $hypothesisId,
            'evidence' => ['correction' => mb_substr($correction, 0, 1000)],
            'status' => 'active',
        ], true, true);
        $fact->save();
        $cycleResult = $this->runObservation(
            'correction',
            'user_correction',
            (int) $fact->id,
            'user_correction:' . (int) $fact->id,
            [
                'source_key' => 'user_correction',
                'feature_key' => 'correction_state',
                'value' => 'applied',
                'domain' => ['applied'],
                'observable' => true,
                'weight' => 1.0,
                'observed_at' => $now,
                'evidence' => ['frame_fact_id' => (int) $fact->id],
            ]
        );
        $this->core->emitEvent('other_model.hypothesis.corrected', [ 'hypothesis_id' => $hypothesisId, 'frame_fact_id' => (int) $fact->id, ]);
        return [
            'status' => 'corrected',
            'hypothesis' => $hypothesis->getData(),
            'frame_fact' => $fact->getData(),
            'cycle' => $cycleResult['cycle'] ?? null,
        ];
    }

    public function hypothesisTtl(string $kind): int {
        return match ($kind) {
            'attention', 'error' => 300,
            'belief', 'plan' => 900,
            'goal' => 1800,
            'constraint' => 3600,
            default => 300,
        };
    }

    public function grounded(string $proposition, string $statement): bool {
        $terms = $this->groundedTerms($proposition, $statement);
        $propositionTerms = $this->contentTerms($proposition);
        return $propositionTerms !== [] && count($terms) / count($propositionTerms) >= 0.5;
    }

    /** @return list<string> */
    public function groundedTerms(string $proposition, string $statement): array {
        return array_values(array_intersect( $this->contentTerms($proposition), $this->contentTerms($statement) ));
    }

    /** @return list<string> */
    public function contentTerms(string $text): array {
        $stop = array_flip(['that', 'this', 'with', 'from', 'have', 'will', 'would', 'should', 'could', 'about']);
        return array_values(array_unique(array_filter( preg_split('/[^\p{L}\p{N}]+/u', $this->normalizeText($text)) ?: [], static fn (string $term): bool => mb_strlen($term) >= 3 && !isset($stop[$term]) )));
    }

    public function normalizeText(string $text): string {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower( preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text )) ?? '');
    }

    /**
     * @param array<string, mixed> $hypothesis
     * @return array<string, mixed>
     */
    public function hypothesisAssessment(array $hypothesis): array {
        return [
            'forecast_scope' => 'kind_and_context_conditioned_observable_category',
            'forecast_support' => $hypothesis['posterior'] ?? null,
            'forecast_quality' => (int) ($hypothesis['predictions_resolved'] ?? 0) > 0
                ? ($hypothesis['forward_score'] ?? null)
                : null,
            'validation_scope' => 'forecast_eligibility_only',
            'proposition_support' => ($hypothesis['status'] ?? null) === 'corrected'
                ? 'corrected_by_user'
                : match ($hypothesis['representation'] ?? null) {
                    'stated' => 'reported_unverified',
                    'inferred' => 'inferred_unverified',
                    default => 'unverified',
                },
            'proposition_probability' => null,
            'provenance_kind' => $hypothesis['provenance_kind'] ?? null,
            'provenance_weight' => (float) ($hypothesis['backward_score'] ?? 0.0) > 0.0
                ? $hypothesis['backward_score']
                : null,
            'inverse_consistency_score' => null,
            'legacy_fields' => OtherModel::ASSESSMENT_SEMANTICS,
        ];
    }
}
