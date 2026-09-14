<?php

declare(strict_types=1);

namespace NaviBrain\Core\OtherModel;

use NaviBrain\Model\WorkItem;

use NaviBrain\Core\OtherModel;

use NaviBrain\Model\OtherModelCycle;
use NaviBrain\Model\OtherModelHypothesis;
use NaviBrain\Model\OtherModelPrediction;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\UtteranceOutcome;
use NaviBrain\Support\PlainText;
use NaviBrain\Model\WorkingMemorySlot;
use RuntimeException;
use Throwable;
use NaviBrain\Core\ProbabilityScorer;

class Observations extends Component
{

    /** @return array<string, mixed> */
    public function observeReading(SenseReading $reading): array {
        $observation = $this->projector->reading($reading);
        if ($observation === null) {
            return [
                'status' => 'irrelevant_observation',
                'source_key' => (string) $reading->source_key,
                'reading_id' => (int) $reading->id,
            ];
        }
        return $this->runObservation('sense_reading', (string) $reading->source_key, (int) $reading->id, sprintf('sense_reading:%s:%d', (string) $reading->source_key, (int) $reading->id), $observation);
    }

    /** @return array<string, mixed> */
    public function observeUtteranceOutcome(UtteranceOutcome $outcome): array {
        return $this->runObservation(
            'utterance_outcome',
            'utterance_outcome',
            (int) $outcome->id,
            sprintf('utterance_outcome:%d:%s', (int) $outcome->id, (string) $outcome->status),
            $this->projector->outcome($outcome)
        );
    }

    /** @return array<string, int|bool|string> */
    public function sourceStatusChanged(string $sourceKey, string $status): array {
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
        foreach (OtherModelCycle::getAllByWhere([ 'trigger_source' => $sourceKey, 'status' => 'waiting', ]) as $cycle) {
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
    public function runObservation(string $triggerKind, string $triggerSource, int $triggerId, string $idempotencyKey, array $observation): array {
        $existing = OtherModelCycle::getByField('idempotency_key', $idempotencyKey);
        if ($existing instanceof OtherModelCycle) {
            return ['status' => 'deduplicated', 'cycle' => $existing->getData()];
        }

        $now = time();
        try {

            $cycle = new OtherModelCycle([
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
            ], true, true);
            $cycle->save();
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
            $surprises = array_values(array_filter(array_map( static fn (array $row): ?float => isset($row['log_loss']) ? (float) $row['log_loss'] : null, $resolved ), static fn (?float $value): bool => $value !== null));
            $surprise = $surprises === []
                ? 0.0
                : min(1.0, array_sum($surprises) / count($surprises) / 4.0);
            $cycle->setField('surprise', round($surprise, 6));
            $cycle->save();
            $this->advance($cycle, 'update_subintentional_model', 'resolve_prior_predictions', $started);

            $started = hrtime(true);
            $context = $this->context($cycle, $observation);
            $baseline = $this->baseline($cycle, $observation, $context);
            $cycle->setFields([ 'baseline' => $baseline, 'entropy' => ProbabilityScorer::entropy($baseline['distribution']), ]);
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
    public function queueParser(OtherModelCycle $cycle, string $statement): array {
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
        $queued = $this->core->enqueueWork(new WorkItem([
            'parent_run_id' => null,
            'parent_intention_id' => null,
            'work_type' => OtherModel::WORK_TYPE,
            'prompt' => $prompt,
            'input_refs' => [
                'other_model_cycle_id' => (int) $cycle->id,
                'reading_id' => $observation['reading_id'] ?? null,
                'operation' => 'other_model_proposition',
                'context_scope' => 'no_workspace',
            ],
            'token_budget' => 256,
            'wall_budget_seconds' => 180,
            'idempotency_key' => 'other_model_cycle:' . (int) $cycle->id . ':parser',
            'depth' => 0,
            'max_depth' => 0
        ], true, true));
        $cycle->setFields([ 'parser_work_item_id' => (int) ($queued['work_item']['id'] ?? 0), 'model_calls' => 1, 'status' => 'waiting', 'updated_at' => time(), ]);
        $cycle->save();
        $this->core->emitEvent('other_model.cycle.waiting', [ 'other_model_cycle_id' => (int) $cycle->id, 'state' => 'infer_minimal_model', 'work_item_id' => (int) $cycle->parser_work_item_id, ]);
        return ['status' => 'waiting', 'cycle' => $cycle->getData(), 'work_item' => $queued['work_item'] ?? null];
    }

    /**
     * @param array<string, mixed> $work
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function integrateParserProposal(array $work, array $proposal, ?string $model): array {
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

    /**
     * @param array<string, mixed> $work
     * @return array<string, mixed>
     */
    public function failParserWork(array $work, string $error): array {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $cycle = OtherModelCycle::getByID((int) ($refs['other_model_cycle_id'] ?? 0));
        if (!$cycle instanceof OtherModelCycle) {
            return ['status' => 'missing_cycle'];
        }
        if ($cycle->status !== 'waiting') {
            return ['status' => 'already_integrated', 'cycle' => $cycle->getData()];
        }
        $cycle->setFields([ 'status' => 'running', 'error' => mb_substr('Parser unavailable; baseline completed safely: ' . $error, 0, 1000), 'updated_at' => time(), ]);
        $cycle->save();
        return $this->finishInference($cycle, null, []);
    }

    /**
     * @param array<string, mixed>|null $candidate
     * @param list<array<string, mixed>> $resolved
     * @return array<string, mixed>
     */
    public function finishInference(OtherModelCycle $cycle, ?array $candidate, array $resolved): array {
        $started = hrtime(true);
        $created = $candidate === null ? null : $this->recordHypothesis($cycle, $candidate);
        if ($candidate === null && $this->shouldRepresentError($resolved)) {
            $created = $this->recordHypothesis($cycle, $this->errorCandidate($resolved));
        }
        $live = $this->liveHypotheses();
        $cycle->setField('hypothesis_ids', array_values(array_map( static fn (OtherModelHypothesis $hypothesis): int => (int) $hypothesis->id, $live )));
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
        $cycle->setFields([ 'selected_hypothesis_id' => $selected?->id, 'abstention_reason' => $abstention, ]);
        $cycle->save();
        $this->advance($cycle, 'publish_current_state', 'select_or_abstain', $started);

        $started = hrtime(true);
        $published = $this->publishedState($cycle, $live, $selected);
        $cycle->setField('published_state', $published);
        $cycle->save();
        $this->core->workingMemory()->publish(new WorkingMemorySlot([
            'slot_role' => 'other_agent_state',
            'claim' => PlainText::render($published, 3000, 12),
            'record_type' => 'other_model_cycle',
            'record_id' => (int) $cycle->id,
            'confidence' => (float) ($published['confidence'] ?? 0.0),
            'expires_at' => (int) ($published['expires_at'] ?? time()),
            'scope_key' => 'shared',
        ], true, true));
        $this->advance($cycle, 'seal_next_predictions', 'publish_current_state', $started);

        $started = hrtime(true);
        $predictions = $this->sealPredictions($cycle, $live);
        $this->advance($cycle, 'complete', 'seal_next_predictions', $started);

        $now = time();
        $cycle->setFields([ 'status' => $selected instanceof OtherModelHypothesis ? 'completed' : 'abstained', 'completed_at' => $now, 'updated_at' => $now, ]);
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

    public function fail(OtherModelCycle $cycle, string $error): array {
        $failedState = (string) $cycle->state;
        $now = time();
        $cycle->setFields([ 'state' => 'failed', 'status' => 'failed', 'completed_at' => $now, 'updated_at' => $now, 'error' => mb_substr($error, 0, 2000), ]);
        $cycle->save();
        $this->core->emitEvent('other_model.cycle.failed', [ 'other_model_cycle_id' => (int) $cycle->id, 'failed_state' => $failedState, 'error' => $error, ]);
        return ['status' => 'failed', 'cycle' => $cycle->getData(), 'error' => $error];
    }

    public function advance(OtherModelCycle $cycle, string $next, string $completed, int $started): void {
        $elapsed = (int) round((hrtime(true) - $started) / 1_000_000);
        $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
        $timings[$completed . '_ms'] = (int) ($timings[$completed . '_ms'] ?? 0) + max(0, $elapsed);
        $cycle->setFields([ 'state' => $next, 'stage_timings' => $timings, 'updated_at' => time(), ]);
        $cycle->save();

    }
}
