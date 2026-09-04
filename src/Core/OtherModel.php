<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use InvalidArgumentException;
use NaviBrain\Model\MetricSnapshot;
use NaviBrain\Model\OtherAgentFrameFact;
use NaviBrain\Model\OtherModelCycle;
use NaviBrain\Model\OtherModelHypothesis;
use NaviBrain\Model\OtherModelPrediction;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\SensorySource;
use NaviBrain\Model\UtteranceOutcome;
use NaviBrain\Perception\AgentObservationProjector;
use NaviBrain\Support\PlainText;
use RuntimeException;
use Throwable;

/**
 * A bounded, depth-one predictive model of the primary user.
 *
 * Mental propositions are never observations. Authorized readings establish a
 * small behavioural state; hypotheses remain expiring candidates until sealed
 * predictions resolve and forward/backward checks make them usable.
 */
final class OtherModel
{
    public const WORK_TYPE = 'other_model_parse';
    private const MAX_HYPOTHESES = 3;
    private const HISTORY_LIMIT = 512;
    private const PSEUDOCOUNT = 1.0;
    private const USABLE_POSTERIOR = 0.55;
    private const USABLE_FORWARD_SCORE = 0.5;
    private const USABLE_BACKWARD_SCORE = 0.6;

    private AgentObservationProjector $projector;

    public function __construct(private readonly ExecutiveCore $core)
    {
        $this->projector = new AgentObservationProjector($core);
    }

    public function isAblated(): bool
    {
        $raw = mb_strtolower(trim((string) (getenv('NAVI_BRAIN_OTHER_MODEL_ABLATED') ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, mixed> */
    public function observeReading(SenseReading $reading): array
    {
        $observation = $this->projector->reading($reading);
        if ($observation === null) {
            return [
                'status' => 'irrelevant_observation',
                'source_key' => (string) $reading->source_key,
                'reading_id' => (int) $reading->id,
            ];
        }
        return $this->runObservation(
            'sense_reading',
            (string) $reading->source_key,
            (int) $reading->id,
            sprintf('sense_reading:%s:%d', (string) $reading->source_key, (int) $reading->id),
            $observation
        );
    }

    /** @return array<string, mixed> */
    public function observeUtteranceOutcome(UtteranceOutcome $outcome): array
    {
        return $this->runObservation(
            'utterance_outcome',
            'utterance_outcome',
            (int) $outcome->id,
            sprintf('utterance_outcome:%d:%s', (int) $outcome->id, (string) $outcome->status),
            $this->projector->outcome($outcome)
        );
    }

    /**
     * Invalidate live inferences when the perception grant that grounded them
     * is paused or revoked. Historical cycles remain as an audit trail.
     *
     * @return array<string, int|bool|string>
     */
    public function sourceStatusChanged(string $sourceKey, string $status): array
    {
        if ($status === 'active') {
            return [
                'source_key' => $sourceKey,
                'source_status' => $status,
                'hypotheses_expired' => 0,
                'predictions_expired' => 0,
                'waiting_cycles_abstained' => 0,
                'working_memory_expired' => false,
            ];
        }

        $now = time();
        $hypothesisIds = [];
        foreach (OtherModelHypothesis::getAllByWhere(['status' => 'active']) as $hypothesis) {
            $origin = OtherModelCycle::getByID((int) $hypothesis->origin_cycle_id);
            if (!$origin instanceof OtherModelCycle || $origin->trigger_source !== $sourceKey) {
                continue;
            }
            $hypothesis->setFields(['status' => 'expired', 'updated_at' => $now]);
            $hypothesis->save();
            $hypothesisIds[(int) $hypothesis->id] = true;
        }

        $predictionsExpired = 0;
        foreach (OtherModelPrediction::getAllByWhere(['status' => 'pending']) as $prediction) {
            $fromExpiredHypothesis = $prediction->hypothesis_id !== null
                && isset($hypothesisIds[(int) $prediction->hypothesis_id]);
            if ($prediction->source_key !== $sourceKey && !$fromExpiredHypothesis) {
                continue;
            }
            $prediction->setField('status', 'expired');
            $prediction->save();
            $predictionsExpired++;
        }

        $cyclesAbstained = 0;
        foreach (OtherModelCycle::getAllByWhere([
            'trigger_source' => $sourceKey,
            'status' => 'waiting',
        ]) as $cycle) {
            $cycle->setFields([
                'state' => 'complete',
                'status' => 'abstained',
                'published_state' => [],
                'selected_hypothesis_id' => null,
                'abstention_reason' => 'source_inactive',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
            $cycle->save();
            $cyclesAbstained++;
        }

        $workingMemoryExpired = $this->core->workingMemory()->expireRole('other_agent_state');
        $result = [
            'source_key' => $sourceKey,
            'source_status' => $status,
            'hypotheses_expired' => count($hypothesisIds),
            'predictions_expired' => $predictionsExpired,
            'waiting_cycles_abstained' => $cyclesAbstained,
            'working_memory_expired' => $workingMemoryExpired,
        ];
        $this->core->emitEvent('other_model.source_inactive', $result);
        return $result;
    }

    /** @return array<string, mixed> */
    private function runObservation(
        string $triggerKind,
        string $triggerSource,
        int $triggerId,
        string $idempotencyKey,
        array $observation
    ): array {
        $existing = OtherModelCycle::getByField('idempotency_key', $idempotencyKey);
        if ($existing instanceof OtherModelCycle) {
            return ['status' => 'deduplicated', 'cycle' => $existing->getData()];
        }

        $now = time();
        try {
            /** @var OtherModelCycle $cycle */
            $cycle = $this->core->insertRecord(OtherModelCycle::class, [
                'updated_at' => $now,
                'trigger_kind' => $triggerKind,
                'trigger_source' => $triggerSource,
                'trigger_id' => $triggerId,
                'idempotency_key' => mb_substr($idempotencyKey, 0, 160),
                'state' => 'observe',
                'status' => 'running',
                'observation' => $observation,
                'observability' => [],
                'baseline' => [],
                'hypothesis_ids' => [],
                'published_state' => [],
                'model_depth' => 1,
                'entropy' => 0.0,
                'surprise' => 0.0,
                'model_calls' => 0,
                'stage_timings' => [],
            ]);
        } catch (Throwable $throwable) {
            $raced = OtherModelCycle::getByField('idempotency_key', $idempotencyKey);
            if ($raced instanceof OtherModelCycle) {
                return ['status' => 'deduplicated', 'cycle' => $raced->getData()];
            }
            throw $throwable;
        }
        $this->core->emitEvent('other_model.cycle.started', [
            'other_model_cycle_id' => (int) $cycle->id,
            'trigger_kind' => $triggerKind,
            'trigger_source' => $triggerSource,
            'trigger_id' => $triggerId,
        ]);

        try {
            $started = hrtime(true);
            $this->advance($cycle, 'establish_observability', 'observe', $started);

            $started = hrtime(true);
            $observability = [
                'observable' => ($observation['observable'] ?? false) === true,
                'weight' => round(max(0.0, min(1.0, (float) ($observation['weight'] ?? 0.0))), 4),
                'source_key' => $observation['source_key'] ?? null,
                'feature_key' => $observation['feature_key'] ?? null,
                'observed_at' => $observation['observed_at'] ?? null,
            ];
            $cycle->setField('observability', $observability);
            $cycle->save();
            $this->advance($cycle, 'resolve_prior_predictions', 'establish_observability', $started);

            $started = hrtime(true);
            $resolved = $this->resolvePredictions($cycle, $observation);
            $surprises = array_values(array_filter(array_map(
                static fn (array $row): ?float => isset($row['log_loss']) ? (float) $row['log_loss'] : null,
                $resolved
            ), static fn (?float $value): bool => $value !== null));
            $surprise = $surprises === []
                ? 0.0
                : min(1.0, array_sum($surprises) / count($surprises) / 4.0);
            $cycle->setField('surprise', round($surprise, 6));
            $cycle->save();
            $this->advance($cycle, 'update_subintentional_model', 'resolve_prior_predictions', $started);

            $started = hrtime(true);
            $context = $this->context($cycle, $observation);
            $baseline = $this->baseline($cycle, $observation, $context);
            $cycle->setFields([
                'baseline' => $baseline,
                'entropy' => ProbabilityScorer::entropy($baseline['distribution']),
            ]);
            $cycle->save();
            $this->expireState($this->timestamp($observation['observed_at'] ?? null) ?? $now);
            $this->advance($cycle, 'infer_minimal_model', 'update_subintentional_model', $started);

            $candidate = $this->deterministicCandidate($observation);
            if ($candidate !== null) {
                return $this->finishInference($cycle, $candidate, $resolved);
            }

            $statement = trim((string) ($observation['direct_statement'] ?? ''));
            if ($statement !== '') {
                return $this->queueParser($cycle, $statement);
            }
            return $this->finishInference($cycle, null, $resolved);
        } catch (Throwable $throwable) {
            return $this->fail($cycle, $throwable->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function queueParser(OtherModelCycle $cycle, string $statement): array
    {
        $observation = is_array($cycle->observation) ? $cycle->observation : [];
        $prompt = implode("\n", [
            'Extract at most one explicit proposition from this accepted direct utterance.',
            'Return exactly kind=other_model_proposition.',
            'content must be minified JSON with exactly type, proposition, evidence_quote.',
            'type is goal, belief, plan, constraint, or none.',
            'Use none when the line does not explicitly report one of those things.',
            'Do not infer an unstated motive. evidence_quote must be a verbatim fragment of the utterance.',
            'Do not emit actor, recursion, access, representation, confidence, or an action.',
            'Utterance: ' . PlainText::sanitize($statement),
        ]);
        $queued = $this->core->enqueueWork(
            parentRunId: null,
            parentIntentionId: null,
            workType: self::WORK_TYPE,
            prompt: $prompt,
            inputRefs: [
                'other_model_cycle_id' => (int) $cycle->id,
                'reading_id' => $observation['reading_id'] ?? null,
                'operation' => 'other_model_proposition',
                'context_scope' => 'no_workspace',
            ],
            tokenBudget: 256,
            wallBudgetSeconds: 180,
            idempotencyKey: 'other_model_cycle:' . (int) $cycle->id . ':parser',
            depth: 0,
            maxDepth: 0
        );
        $cycle->setFields([
            'parser_work_item_id' => (int) ($queued['work_item']['id'] ?? 0),
            'model_calls' => 1,
            'status' => 'waiting',
            'updated_at' => time(),
        ]);
        $cycle->save();
        $this->core->emitEvent('other_model.cycle.waiting', [
            'other_model_cycle_id' => (int) $cycle->id,
            'state' => 'infer_minimal_model',
            'work_item_id' => (int) $cycle->parser_work_item_id,
        ]);
        return ['status' => 'waiting', 'cycle' => $cycle->getData(), 'work_item' => $queued['work_item'] ?? null];
    }

    /** @param array<string, mixed> $work @param array<string, mixed> $proposal
     *  @return array<string, mixed>
     */
    public function integrateParserProposal(array $work, array $proposal, ?string $model): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $cycle = OtherModelCycle::getByID((int) ($refs['other_model_cycle_id'] ?? 0));
        if (!$cycle instanceof OtherModelCycle) {
            throw new RuntimeException('Other-model parser work references a missing cycle.');
        }
        if ($cycle->status !== 'waiting' || $cycle->state !== 'infer_minimal_model') {
            return ['status' => 'already_integrated', 'cycle' => $cycle->getData()];
        }
        if ((int) $cycle->parser_work_item_id !== (int) ($work['id'] ?? 0)) {
            throw new RuntimeException('Other-model parser work item does not match its cycle.');
        }
        if (($proposal['kind'] ?? null) !== 'other_model_proposition') {
            throw new RuntimeException('Other-model parser returned the wrong proposal kind.');
        }

        try {
            $parsed = json_decode((string) ($proposal['content'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Other-model parser content is not exact JSON.', 0, $throwable);
        }
        $keys = is_array($parsed) ? array_keys($parsed) : [];
        $expectedKeys = ['type', 'proposition', 'evidence_quote'];
        sort($keys);
        sort($expectedKeys);
        if (!is_array($parsed) || $keys !== $expectedKeys) {
            throw new RuntimeException('Other-model parser content must contain exactly type, proposition, evidence_quote.');
        }
        $type = (string) $parsed['type'];
        if (!in_array($type, ['goal', 'belief', 'plan', 'constraint', 'none'], true)) {
            throw new RuntimeException('Other-model parser emitted an unsupported proposition type.');
        }
        $cycle->setFields(['model_id' => $model, 'status' => 'running', 'updated_at' => time()]);
        $cycle->save();
        if ($type === 'none') {
            return $this->finishInference($cycle, null, []);
        }

        $observation = is_array($cycle->observation) ? $cycle->observation : [];
        $statement = trim((string) ($observation['direct_statement'] ?? ''));
        $proposition = trim((string) $parsed['proposition']);
        $quote = trim((string) $parsed['evidence_quote']);
        if ($statement === '' || $proposition === '' || $quote === ''
            || !str_contains($this->normalizeText($statement), $this->normalizeText($quote))
            || !$this->grounded($proposition, $statement)
        ) {
            return $this->finishInference($cycle, null, []);
        }
        return $this->finishInference($cycle, [
            'kind' => $type,
            'proposition' => $proposition,
            'evidence_quote' => $quote,
            'confidence' => max(0.0, min(1.0, (float) ($proposal['confidence'] ?? 0.0))),
            'provenance_kind' => 'worker_proposal',
        ], []);
    }

    /** @param array<string, mixed> $work @return array<string, mixed> */
    public function failParserWork(array $work, string $error): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $cycle = OtherModelCycle::getByID((int) ($refs['other_model_cycle_id'] ?? 0));
        if (!$cycle instanceof OtherModelCycle) {
            return ['status' => 'missing_cycle'];
        }
        if ($cycle->status !== 'waiting') {
            return ['status' => 'already_integrated', 'cycle' => $cycle->getData()];
        }
        $cycle->setFields([
            'status' => 'running',
            'error' => mb_substr('Parser unavailable; baseline completed safely: ' . $error, 0, 1000),
            'updated_at' => time(),
        ]);
        $cycle->save();
        return $this->finishInference($cycle, null, []);
    }

    /** @param array<string, mixed>|null $candidate @param list<array<string, mixed>> $resolved
     *  @return array<string, mixed>
     */
    private function finishInference(
        OtherModelCycle $cycle,
        ?array $candidate,
        array $resolved
    ): array {
        $started = hrtime(true);
        $created = $candidate === null ? null : $this->recordHypothesis($cycle, $candidate);
        if ($candidate === null && $this->shouldRepresentError($resolved)) {
            $created = $this->recordHypothesis($cycle, $this->errorCandidate($resolved));
        }
        $live = $this->liveHypotheses();
        $cycle->setField('hypothesis_ids', array_values(array_map(
            static fn (OtherModelHypothesis $hypothesis): int => (int) $hypothesis->id,
            $live
        )));
        $cycle->save();
        $this->advance($cycle, 'validate_forward_backward', 'infer_minimal_model', $started);

        $started = hrtime(true);
        $this->validateHypotheses($live);
        $live = $this->liveHypotheses();
        $this->advance($cycle, 'select_or_abstain', 'validate_forward_backward', $started);

        $started = hrtime(true);
        $selected = $this->selectHypothesis($live);
        $abstention = $selected instanceof OtherModelHypothesis
            ? null
            : 'no_rational_interpretation';
        $cycle->setFields([
            'selected_hypothesis_id' => $selected?->id,
            'abstention_reason' => $abstention,
        ]);
        $cycle->save();
        $this->advance($cycle, 'publish_current_state', 'select_or_abstain', $started);

        $started = hrtime(true);
        $published = $this->publishedState($cycle, $live, $selected);
        $cycle->setField('published_state', $published);
        $cycle->save();
        $this->core->workingMemory()->publish(
            role: 'other_agent_state',
            claim: PlainText::render($published, 3000, 12),
            recordType: 'other_model_cycle',
            recordId: (int) $cycle->id,
            confidence: (float) ($published['confidence'] ?? 0.0),
            ttlSeconds: max(30, (int) ($published['expires_at'] ?? time()) - time()),
            scope: 'shared'
        );
        $this->advance($cycle, 'seal_next_predictions', 'publish_current_state', $started);

        $started = hrtime(true);
        $predictions = $this->sealPredictions($cycle, $live);
        $this->advance($cycle, 'complete', 'seal_next_predictions', $started);

        $now = time();
        $cycle->setFields([
            'status' => $selected instanceof OtherModelHypothesis ? 'completed' : 'abstained',
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
        $cycle->save();
        $this->core->emitEvent('other_model.cycle.completed', [
            'other_model_cycle_id' => (int) $cycle->id,
            'status' => (string) $cycle->status,
            'selected_hypothesis_id' => $selected?->id,
            'abstention_reason' => $abstention,
            'predictions_sealed' => count($predictions),
            'candidate_created' => $created?->id,
        ]);
        return [
            'status' => (string) $cycle->status,
            'cycle' => $cycle->getData(),
            'predictions' => $predictions,
        ];
    }

    /** @param array<string, mixed> $observation @return array<string, mixed>|null */
    private function deterministicCandidate(array $observation): ?array
    {
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
    private function recordHypothesis(
        OtherModelCycle $cycle,
        array $candidate
    ): ?OtherModelHypothesis {
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
                    'posterior' => round(min(0.99, max(
                        (float) $existing->posterior,
                        (float) ($candidate['confidence'] ?? 0.5)
                    )), 4),
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
        /** @var OtherModelHypothesis $hypothesis */
        $hypothesis = $this->core->insertRecord(OtherModelHypothesis::class, [
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
        ]);
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
    private function shouldRepresentError(array $resolved): bool
    {
        $violated = array_filter(
            $resolved,
            static fn (array $row): bool => ($row['status'] ?? null) === 'violated'
                && (float) ($row['log_loss'] ?? 0.0) >= 1.2
        );
        return count($violated) >= 2;
    }

    /** @param list<array<string, mixed>> $resolved @return array<string, mixed> */
    private function errorCandidate(array $resolved): array
    {
        $ids = array_values(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            array_filter($resolved, static fn (array $row): bool => ($row['status'] ?? null) === 'violated')
        ));
        $violations = count($ids);
        $matches = count(array_filter(
            $resolved,
            static fn (array $row): bool => ($row['status'] ?? null) === 'matched'
        ));
        $likelihoods = [
            'action_slip' => $matches > 0 ? 0.35 : 0.1,
            'bounded_plan_failure' => $violations >= 2 ? 0.4 : 0.25,
            'temporary_goal_confusion' => 0.2,
            'genuine_goal_change' => $violations >= 3 ? 0.35 : 0.15,
        ];
        $disabled = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) (getenv('NAVI_BRAIN_OTHER_MODEL_ERROR_ABLATE') ?: ''))
        )));
        foreach ($disabled as $mode) {
            if (array_key_exists($mode, $likelihoods)) {
                $likelihoods[$mode] = 0.0;
            }
        }
        $sum = array_sum($likelihoods);
        if ($sum > 0.0) {
            $likelihoods = array_map(
                static fn (float $value): float => round($value / $sum, 4),
                $likelihoods
            );
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
    private function validateHypotheses(array $hypotheses): void
    {
        foreach ($hypotheses as $hypothesis) {
            $resolved = (int) $hypothesis->predictions_resolved;
            $forward = (float) $hypothesis->forward_score;
            $backward = (float) $hypothesis->backward_score;
            $posterior = (float) $hypothesis->posterior;
            $validation = 'pending';
            $status = 'active';
            if ($resolved >= 1
                && $forward >= self::USABLE_FORWARD_SCORE
                && $backward >= self::USABLE_BACKWARD_SCORE
                && $posterior >= self::USABLE_POSTERIOR
            ) {
                $validation = 'usable';
            } elseif ($resolved >= 2 && ($forward < 0.25 || $posterior < 0.2)) {
                $validation = 'invalid';
                $status = 'rejected';
            }
            if ($hypothesis->validation !== $validation || $hypothesis->status !== $status) {
                $hypothesis->setFields([
                    'validation' => $validation,
                    'status' => $status,
                    'updated_at' => time(),
                ]);
                $hypothesis->save();
            }
        }
    }

    /** @param list<OtherModelHypothesis> $hypotheses */
    private function selectHypothesis(array $hypotheses): ?OtherModelHypothesis
    {
        $usable = array_values(array_filter(
            $hypotheses,
            static fn (OtherModelHypothesis $hypothesis): bool => $hypothesis->status === 'active'
                && $hypothesis->validation === 'usable'
                && (float) $hypothesis->posterior >= self::USABLE_POSTERIOR
        ));
        usort($usable, static fn (OtherModelHypothesis $left, OtherModelHypothesis $right): int =>
            (float) $right->posterior <=> (float) $left->posterior
                ?: (float) $right->forward_score <=> (float) $left->forward_score
                ?: (int) $right->id <=> (int) $left->id
        );
        return $usable[0] ?? null;
    }

    /** @param list<OtherModelHypothesis> $hypotheses @return array<string, mixed> */
    private function publishedState(
        OtherModelCycle $cycle,
        array $hypotheses,
        ?OtherModelHypothesis $selected
    ): array {
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
            'selected_hypothesis' => $selected instanceof OtherModelHypothesis ? [
                'id' => (int) $selected->id,
                'kind' => (string) $selected->kind,
                'proposition' => (string) $selected->proposition,
                'posterior' => (float) $selected->posterior,
                'forward_score' => (float) $selected->forward_score,
                'backward_score' => (float) $selected->backward_score,
                'expires_at' => $this->timestamp($selected->expires_at),
            ] : null,
            'reported_not_validated' => array_slice($reportedPending, 0, self::MAX_HYPOTHESES),
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

    /** @param list<OtherModelHypothesis> $hypotheses @return list<array<string, mixed>> */
    private function sealPredictions(OtherModelCycle $cycle, array $hypotheses): array
    {
        $observation = is_array($cycle->observation) ? $cycle->observation : [];
        $baseline = is_array($cycle->baseline) ? $cycle->baseline : [];
        $predictions = [];
        if (($observation['observable'] ?? false) === true
            && (array) ($baseline['distribution'] ?? []) !== []
        ) {
            $predictions[] = $this->seal(
                $cycle,
                null,
                'baseline',
                (string) $observation['source_key'],
                (string) $observation['feature_key'],
                (array) $baseline['distribution'],
                [],
                (array) $observation['domain']
            );
        }

        foreach (array_slice($hypotheses, 0, self::MAX_HYPOTHESES) as $hypothesis) {
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
            $predictions[] = $this->seal(
                $cycle,
                $hypothesis,
                'hypothesis',
                $target['source_key'],
                $target['feature_key'],
                $distribution,
                $targetBaseline,
                $target['domain']
            );
        }
        return array_values(array_map(
            static fn (OtherModelPrediction $prediction): array => $prediction->getData(),
            $predictions
        ));
    }

    /** @param array<string, float> $distribution @param array<string, float> $baseline
     *  @param list<string> $domain
     */
    private function seal(
        OtherModelCycle $cycle,
        ?OtherModelHypothesis $hypothesis,
        string $modelKind,
        string $source,
        string $feature,
        array $distribution,
        array $baseline,
        array $domain
    ): OtherModelPrediction {
        $distribution = ProbabilityScorer::normalize($distribution, $domain);
        $top = ProbabilityScorer::top($distribution);
        $now = time();
        /** @var OtherModelPrediction $prediction */
        $prediction = $this->core->insertRecord(OtherModelPrediction::class, [
            'cycle_id' => (int) $cycle->id,
            'hypothesis_id' => $hypothesis?->id,
            'model_kind' => $modelKind,
            'source_key' => $source,
            'feature_key' => $feature,
            'operator' => 'equals',
            'expected_value' => (string) $top['value'],
            'distribution' => $distribution,
            'baseline_distribution' => ProbabilityScorer::normalize($baseline, $domain),
            'probability' => (float) $top['probability'],
            'specificity' => round(1.0 - ProbabilityScorer::entropy($distribution), 4),
            'sealed_at' => $now,
            'deadline' => $now + $this->predictionHorizon($source),
            'status' => 'pending',
        ]);
        return $prediction;
    }

    /** @param array<string, mixed> $observation @return list<array<string, mixed>> */
    private function resolvePredictions(OtherModelCycle $cycle, array $observation): array
    {
        $source = (string) ($observation['source_key'] ?? '');
        $feature = (string) ($observation['feature_key'] ?? '');
        $observed = (string) ($observation['value'] ?? '');
        $observedAt = $this->timestamp($observation['observed_at'] ?? null) ?? time();
        $resolved = [];

        foreach (OtherModelPrediction::getAllByWhere(
            ['status' => 'pending'],
            ['order' => ['id' => 'ASC'], 'limit' => 500]
        ) as $prediction) {
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
                $prediction->setFields([
                    'observed_cycle_id' => (int) $cycle->id,
                    'outcome_value' => mb_substr($observed, 0, 96),
                    'observed_at' => $observedAt,
                    'status' => 'unresolvable',
                ]);
                $prediction->save();
                continue;
            }
            $distribution = ProbabilityScorer::normalize(
                is_array($prediction->distribution) ? $prediction->distribution : [],
                $domain
            );
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

    private function updateHypothesisFromPrediction(
        OtherModelPrediction $prediction,
        string $observed
    ): void {
        $hypothesis = OtherModelHypothesis::getByID((int) $prediction->hypothesis_id);
        if (!$hypothesis instanceof OtherModelHypothesis || $hypothesis->status !== 'active') {
            return;
        }
        $distribution = is_array($prediction->distribution) ? $prediction->distribution : [];
        $baseline = is_array($prediction->baseline_distribution) ? $prediction->baseline_distribution : [];
        $likelihood = ProbabilityScorer::probability($distribution, $observed);
        $baselineLikelihood = ProbabilityScorer::probability($baseline, $observed);
        $posterior = max(0.001, min(0.999, (float) $hypothesis->posterior));
        $odds = ($posterior / (1.0 - $posterior)) * max(0.1, min(10.0, $likelihood / $baselineLikelihood));
        $posterior = $odds / (1.0 + $odds);
        $resolved = (int) $hypothesis->predictions_resolved;
        $quality = 1.0 - min(1.0, ((float) $prediction->brier_score) / 2.0);
        $forward = (($resolved * (float) $hypothesis->forward_score) + $quality) / ($resolved + 1);
        $hypothesis->setFields([
            'posterior' => round($posterior, 4),
            'forward_score' => round($forward, 4),
            'predictions_resolved' => $resolved + 1,
            'updated_at' => time(),
        ]);
        $hypothesis->save();
    }

    /** @param array<string, mixed> $observation @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    private function baseline(
        OtherModelCycle $current,
        array $observation,
        array $context
    ): array {
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
        foreach ([24, 64, 128, 256, self::HISTORY_LIMIT] as $window) {
            $windowRows = array_slice($series, -$window);
            $conditional = array_fill_keys($domain, self::PSEUDOCOUNT);
            $unconditional = array_fill_keys($domain, self::PSEUDOCOUNT);
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
            $conditionalEntropy = ProbabilityScorer::entropy(
                ProbabilityScorer::normalize($conditional, $domain)
            );
            if ($historyUsed === count($series)
                || ($transitions >= 3 && $conditionalEntropy <= 0.85)
            ) {
                break;
            }
        }
        $selected = $transitions >= 3 ? 'context_conditioned' : 'unconditional';
        $counts = $selected === 'context_conditioned' ? $conditional : $unconditional;
        $distribution = ProbabilityScorer::normalize($counts, $domain);
        $persistence = array_fill_keys($domain, self::PSEUDOCOUNT);
        $currentValue = (string) ($observation['value'] ?? '');
        if (isset($persistence[$currentValue])) {
            $persistence[$currentValue] += 4.0;
        }
        return [
            'model' => $selected,
            'pseudocount' => self::PSEUDOCOUNT,
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

    /** @param array<string, mixed> $observation @return array<string, mixed> */
    private function context(OtherModelCycle $current, array $observation): array
    {
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
    private function observationSeries(string $source, string $feature, int $beforeCycleId): array
    {
        $cycles = OtherModelCycle::getAll([
            'order' => ['id' => 'DESC'],
            'limit' => self::HISTORY_LIMIT,
        ]);
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

    private function latestValue(
        string $source,
        string $feature,
        ?int $beforeCycleId = null,
        ?int $at = null
    ): ?string
    {
        if (!$this->sourceIsActive($source)) {
            return null;
        }
        foreach (OtherModelCycle::getAll([
            'order' => ['id' => 'DESC'],
            'limit' => 200,
        ]) as $cycle) {
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

    /** @param array<string, mixed> $observation @return array<string, mixed>|null */
    private function predictionTarget(
        OtherModelHypothesis $hypothesis,
        array $observation
    ): ?array {
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

    /** @param array<string, mixed> $target @param array<string, float> $baseline
     *  @return array<string, float>
     */
    private function hypothesisDistribution(
        OtherModelHypothesis $hypothesis,
        array $target,
        array $baseline
    ): array {
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

    /** @return list<OtherModelHypothesis> */
    private function liveHypotheses(): array
    {
        $this->expireState(time());
        $rows = OtherModelHypothesis::getAllByWhere(
            ['actor' => 'primary_user', 'status' => 'active'],
            ['order' => ['posterior' => 'DESC'], 'limit' => self::MAX_HYPOTHESES]
        );
        return array_values(array_filter(
            $rows,
            static fn (OtherModelHypothesis $hypothesis): bool => (int) $hypothesis->recursion_order === 1
        ));
    }

    private function enforceHypothesisLimit(): void
    {
        $rows = OtherModelHypothesis::getAllByWhere(
            ['actor' => 'primary_user', 'status' => 'active'],
            ['order' => ['posterior' => 'DESC'], 'limit' => 100]
        );
        foreach (array_slice($rows, self::MAX_HYPOTHESES) as $hypothesis) {
            $hypothesis->setFields(['status' => 'expired', 'updated_at' => time()]);
            $hypothesis->save();
        }
    }

    private function makeHypothesisRoom(): void
    {
        $rows = OtherModelHypothesis::getAllByWhere(
            ['actor' => 'primary_user', 'status' => 'active'],
            ['order' => ['posterior' => 'ASC', 'id' => 'ASC'], 'limit' => 100]
        );
        while (count($rows) >= self::MAX_HYPOTHESES) {
            $lowest = array_shift($rows);
            if (!$lowest instanceof OtherModelHypothesis) {
                break;
            }
            $lowest->setFields(['status' => 'expired', 'updated_at' => time()]);
            $lowest->save();
        }
    }

    private function expireState(int $now): void
    {
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
    public function correctHypothesis(int $hypothesisId, string $correction): array
    {
        $hypothesis = OtherModelHypothesis::getByID($hypothesisId);
        if (!$hypothesis instanceof OtherModelHypothesis || $hypothesis->actor !== 'primary_user') {
            throw new InvalidArgumentException('No such primary-user hypothesis.');
        }
        $correction = trim($correction);
        if ($correction === '') {
            throw new InvalidArgumentException('A correction cannot be empty.');
        }
        $now = time();
        $hypothesis->setFields([
            'status' => 'corrected',
            'validation' => 'invalid',
            'corrected_at' => $now,
            'updated_at' => $now,
        ]);
        $hypothesis->save();
        foreach (OtherModelPrediction::getAllByWhere([
            'hypothesis_id' => $hypothesisId,
            'status' => 'pending',
        ]) as $prediction) {
            $prediction->setField('status', 'expired');
            $prediction->save();
        }
        /** @var OtherAgentFrameFact $fact */
        $fact = $this->core->insertRecord(OtherAgentFrameFact::class, [
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
        ]);
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
        $this->core->emitEvent('other_model.hypothesis.corrected', [
            'hypothesis_id' => $hypothesisId,
            'frame_fact_id' => (int) $fact->id,
        ]);
        return [
            'status' => 'corrected',
            'hypothesis' => $hypothesis->getData(),
            'frame_fact' => $fact->getData(),
            'cycle' => $cycleResult['cycle'] ?? null,
        ];
    }

    /** @return array{weight: float, basis: string} */
    public function utteranceObservability(
        UtteranceOutcome $outcome,
        int $responses,
        int $reactions
    ): array {
        if ($responses > 0 || $reactions > 0) {
            return ['weight' => 1.0, 'basis' => 'A response or reaction was directly observed.'];
        }
        $presenceState = (string) $outcome->presence_state_at_utterance;
        $presentRaw = $outcome->present_at_utterance;
        $present = match ($presenceState) {
            'present' => true,
            'away' => false,
            default => $presentRaw === null ? null : (bool) $presentRaw,
        };
        if ($present === false) {
            return ['weight' => 0.0, 'basis' => 'Desktop presence reported the user away.'];
        }
        $mode = 'uncertain';
        $spokenAt = $this->timestamp($outcome->spoken_at) ?? time();
        foreach (OtherModelCycle::getAll([
            'order' => ['id' => 'DESC'],
            'limit' => 200,
        ]) as $cycle) {
            $state = is_array($cycle->published_state) ? $cycle->published_state : [];
            $observedAt = $this->timestamp($state['observed_at'] ?? null);
            if ($observedAt !== null && $observedAt <= $spokenAt) {
                $mode = (string) ($state['attention_mode'] ?? 'uncertain');
                break;
            }
        }
        $weight = match ($mode) {
            'available' => 0.95,
            'conversing' => 0.75,
            'occupied' => 0.35,
            'away' => 0.0,
            default => $present === true
                ? 0.55
                : 0.2,
        };

        $hearing = SensorySource::getByField('source_key', 'pet_hearing');
        $hearingAt = $hearing instanceof SensorySource
            ? $this->timestamp($hearing->last_reading_at)
            : null;
        $hearingFreshFor = $hearing instanceof SensorySource
            ? max(180, (int) $hearing->sample_interval_seconds * 3)
            : 0;
        $hearingLive = $hearing instanceof SensorySource
            && $hearing->status === 'active'
            && $hearingAt !== null
            && (time() - $hearingAt) <= $hearingFreshFor;
        if (!$hearingLive) {
            $weight *= 0.25;
        }
        return [
            'weight' => round($weight, 4),
            'basis' => sprintf(
                'Reachability mode was %s; hearing source was %s.',
                $mode,
                $hearingLive ? 'active and fresh' : 'inactive or stale'
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function decisionState(): array
    {
        $state = null;
        foreach (OtherModelCycle::getAll([
            'order' => ['id' => 'DESC'],
            'limit' => 50,
        ]) as $cycle) {
            if (!in_array($cycle->status, ['completed', 'abstained'], true)) {
                continue;
            }
            $candidate = is_array($cycle->published_state) ? $cycle->published_state : [];
            $provenance = is_array($candidate['provenance'] ?? null) ? $candidate['provenance'] : [];
            $dependencies = is_array($provenance['source_dependencies'] ?? null)
                ? $provenance['source_dependencies']
                : [(string) ($provenance['trigger_source'] ?? '')];
            $authorized = true;
            foreach (array_filter(array_map('strval', $dependencies)) as $sourceKey) {
                if (!$this->sourceIsActive($sourceKey)) {
                    $authorized = false;
                    break;
                }
            }
            if (!$authorized) {
                continue;
            }
            $expiresAt = $this->timestamp($candidate['expires_at'] ?? null);
            if ($candidate !== [] && $expiresAt !== null && $expiresAt > time()) {
                $state = $candidate;
                break;
            }
        }
        $mode = (string) ($state['attention_mode'] ?? 'unmodeled');
        $confidence = (float) ($state['confidence'] ?? 0.0);
        $counterfactualFactor = $confidence < 0.25 ? 1.0 : match ($mode) {
            'away' => 0.1,
            'occupied' => 0.3,
            'conversing' => 0.65,
            'uncertain' => 0.65,
            default => 1.0,
        };
        return [
            'state' => $state,
            'attention_mode' => $mode,
            'counterfactual_speech_factor' => $counterfactualFactor,
            'speech_factor' => $this->isAblated() ? 1.0 : $counterfactualFactor,
            'ablated' => $this->isAblated(),
        ];
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $this->expireState(time());
        return [
            'actor' => 'primary_user',
            'maximum_recursion_order' => 1,
            'ablated' => $this->isAblated(),
            'decision_state' => $this->decisionState(),
            'frame_facts' => $this->listFrameFacts(),
            'live_hypotheses' => $this->listHypotheses('active'),
            'pending_predictions' => $this->listPredictions('pending', 25),
            'recent_cycles' => $this->listCycles(null, 10),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listFrameFacts(?string $status = 'active', int $limit = 100): array
    {
        $conditions = $status === null ? [] : ['status' => $status];
        return $this->recordData(OtherAgentFrameFact::getAllByWhere(
            $conditions,
            ['order' => ['id' => 'DESC'], 'limit' => $this->limit($limit)]
        ));
    }

    /** @return list<array<string, mixed>> */
    public function listHypotheses(?string $status = null, int $limit = 100): array
    {
        $conditions = $status === null ? [] : ['status' => $status];
        return $this->recordData(OtherModelHypothesis::getAllByWhere(
            $conditions,
            ['order' => ['id' => 'DESC'], 'limit' => $this->limit($limit)]
        ));
    }

    /** @return list<array<string, mixed>> */
    public function listPredictions(?string $status = null, int $limit = 100): array
    {
        $conditions = $status === null ? [] : ['status' => $status];
        return $this->recordData(OtherModelPrediction::getAllByWhere(
            $conditions,
            ['order' => ['id' => 'DESC'], 'limit' => $this->limit($limit)]
        ));
    }

    /** @return list<array<string, mixed>> */
    public function listCycles(?string $status = null, int $limit = 20): array
    {
        $conditions = $status === null ? [] : ['status' => $status];
        return $this->recordData(OtherModelCycle::getAllByWhere(
            $conditions,
            ['order' => ['id' => 'DESC'], 'limit' => $this->limit($limit)]
        ));
    }

    /** @return array<string, mixed> */
    public function replay(int $limit = 500): array
    {
        return (new OtherModelEvaluator())->report($this->limit($limit, 2000));
    }

    /** Capture the pre-ToM social proxy once, before new cycles can change it. */
    public function captureBaseline(): ?array
    {
        $existing = MetricSnapshot::getByWhere([
            'protocol_version' => 'other-model-pre-v1',
            'scope_key' => 'social-feedback',
        ], ['order' => ['id' => 'ASC']]);
        if ($existing instanceof MetricSnapshot) {
            return $existing->getData();
        }
        $counts = [];
        $reflectedDescriptors = [];
        foreach (UtteranceOutcome::getAll() as $outcome) {
            $status = (string) $outcome->status;
            $counts[$status] = (int) ($counts[$status] ?? 0) + 1;
            if ($status === 'reflected' && trim((string) $outcome->descriptor) !== '') {
                $reflectedDescriptors[(string) $outcome->descriptor] = true;
            }
        }
        ksort($counts);
        $vector = [
            'captured_before_other_model_cycles' => OtherModelCycle::getAll() === [],
            'utterance_outcomes_by_status' => $counts,
            'reflected_descriptor_count' => count($reflectedDescriptors),
        ];
        $manifest = json_encode([
            'meaning' => 'Frozen pre-ToM social-proxy counts; raw rows remain unchanged.',
            'engagement_is_not_reward' => true,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        /** @var MetricSnapshot $snapshot */
        $snapshot = $this->core->insertRecord(MetricSnapshot::class, [
            'protocol_version' => 'other-model-pre-v1',
            'scope_key' => 'social-feedback',
            'node_id' => gethostname() ?: 'unknown',
            'vector' => $vector,
            'manifest' => $manifest,
            'checksum' => hash('sha256', json_encode($vector, JSON_THROW_ON_ERROR) . $manifest),
        ]);
        return $snapshot->getData();
    }

    private function fail(OtherModelCycle $cycle, string $error): array
    {
        $failedState = (string) $cycle->state;
        $now = time();
        $cycle->setFields([
            'state' => 'failed',
            'status' => 'failed',
            'completed_at' => $now,
            'updated_at' => $now,
            'error' => mb_substr($error, 0, 2000),
        ]);
        $cycle->save();
        $this->core->emitEvent('other_model.cycle.failed', [
            'other_model_cycle_id' => (int) $cycle->id,
            'failed_state' => $failedState,
            'error' => $error,
        ]);
        return ['status' => 'failed', 'cycle' => $cycle->getData(), 'error' => $error];
    }

    private function advance(
        OtherModelCycle $cycle,
        string $next,
        string $completed,
        int $started
    ): void {
        $elapsed = (int) round((hrtime(true) - $started) / 1_000_000);
        $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
        $timings[$completed . '_ms'] = (int) ($timings[$completed . '_ms'] ?? 0) + max(0, $elapsed);
        $cycle->setFields([
            'state' => $next,
            'stage_timings' => $timings,
            'updated_at' => time(),
        ]);
        $cycle->save();
        // The cycle row already durably records both the current state and all
        // stage timings. Emitting another event at every internal boundary
        // multiplied ordinary sensory writes without preserving extra state.
    }

    /** @param array<string, mixed> $context */
    private function contextKey(array $context, string $source, string $feature): string
    {
        return implode('|', [
            'source=' . $source,
            'feature=' . $feature,
            'reachability=' . (string) ($context['reachability'] ?? 'unknown'),
            'attention=' . (string) ($context['attention_mode'] ?? 'uncertain'),
        ]);
    }

    private function hypothesisTtl(string $kind): int
    {
        return match ($kind) {
            'attention', 'error' => 300,
            'belief', 'plan' => 900,
            'goal' => 1800,
            'constraint' => 3600,
            default => 300,
        };
    }

    private function predictionHorizon(string $source): int
    {
        return match ($source) {
            'pet_hearing', 'utterance_outcome' => 180,
            'desktop_presence', 'input_activity' => 90,
            default => 180,
        };
    }

    private function stateTtl(string $source): int
    {
        return match ($source) {
            'pet_hearing' => 180,
            'desktop_presence', 'input_activity' => 120,
            default => 300,
        };
    }

    private function sourceIsActive(string $sourceKey): bool
    {
        if (in_array($sourceKey, ['utterance_outcome', 'user_correction'], true)) {
            return true;
        }
        $source = SensorySource::getByField('source_key', $sourceKey);
        return $source instanceof SensorySource && $source->status === 'active';
    }

    private function grounded(string $proposition, string $statement): bool
    {
        $terms = $this->groundedTerms($proposition, $statement);
        $propositionTerms = $this->contentTerms($proposition);
        return $propositionTerms !== [] && count($terms) / count($propositionTerms) >= 0.5;
    }

    /** @return list<string> */
    private function groundedTerms(string $proposition, string $statement): array
    {
        return array_values(array_intersect(
            $this->contentTerms($proposition),
            $this->contentTerms($statement)
        ));
    }

    /** @return list<string> */
    private function contentTerms(string $text): array
    {
        $stop = array_flip(['that', 'this', 'with', 'from', 'have', 'will', 'would', 'should', 'could', 'about']);
        return array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $this->normalizeText($text)) ?: [],
            static fn (string $term): bool => mb_strlen($term) >= 3 && !isset($stop[$term])
        )));
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(
            preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text
        )) ?? '');
    }

    private function limit(int $limit, int $maximum = 500): int
    {
        if ($limit < 1 || $limit > $maximum) {
            throw new InvalidArgumentException(sprintf('limit must be between 1 and %d.', $maximum));
        }
        return $limit;
    }

    /** @param list<object> $records @return list<array<string, mixed>> */
    private function recordData(array $records): array
    {
        return array_values(array_map(
            static fn (object $record): array => $record->getData(),
            $records
        ));
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
