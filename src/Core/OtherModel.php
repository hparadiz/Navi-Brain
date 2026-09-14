<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use InvalidArgumentException;
use NaviBrain\Core\ExecutiveCore\Executive;
use NaviBrain\Model\MetricSnapshot;
use NaviBrain\Model\OtherAgentFrameFact;
use NaviBrain\Model\OtherModelCycle;
use NaviBrain\Model\OtherModelHypothesis;
use NaviBrain\Model\OtherModelPrediction;
use NaviBrain\Model\SensorySource;
use NaviBrain\Model\UtteranceOutcome;
use NaviBrain\Perception\AgentObservationProjector;

class OtherModel
{
    private const DOMAINS = [
        'deterministicCandidate' => OtherModel\Hypotheses::class,
        'recordHypothesis' => OtherModel\Hypotheses::class,
        'shouldRepresentError' => OtherModel\Hypotheses::class,
        'errorCandidate' => OtherModel\Hypotheses::class,
        'validateHypotheses' => OtherModel\Hypotheses::class,
        'selectHypothesis' => OtherModel\Hypotheses::class,
        'publishedState' => OtherModel\Hypotheses::class,
        'liveHypotheses' => OtherModel\Hypotheses::class,
        'enforceHypothesisLimit' => OtherModel\Hypotheses::class,
        'makeHypothesisRoom' => OtherModel\Hypotheses::class,
        'expireState' => OtherModel\Hypotheses::class,
        'correctHypothesis' => OtherModel\Hypotheses::class,
        'hypothesisTtl' => OtherModel\Hypotheses::class,
        'grounded' => OtherModel\Hypotheses::class,
        'groundedTerms' => OtherModel\Hypotheses::class,
        'contentTerms' => OtherModel\Hypotheses::class,
        'normalizeText' => OtherModel\Hypotheses::class,
        'hypothesisAssessment' => OtherModel\Hypotheses::class,
        'observeReading' => OtherModel\Observations::class,
        'observeUtteranceOutcome' => OtherModel\Observations::class,
        'sourceStatusChanged' => OtherModel\Observations::class,
        'runObservation' => OtherModel\Observations::class,
        'queueParser' => OtherModel\Observations::class,
        'integrateParserProposal' => OtherModel\Observations::class,
        'failParserWork' => OtherModel\Observations::class,
        'finishInference' => OtherModel\Observations::class,
        'fail' => OtherModel\Observations::class,
        'advance' => OtherModel\Observations::class,
        'sealPredictions' => OtherModel\Predictions::class,
        'seal' => OtherModel\Predictions::class,
        'resolvePredictions' => OtherModel\Predictions::class,
        'updateHypothesisFromPrediction' => OtherModel\Predictions::class,
        'baseline' => OtherModel\Predictions::class,
        'context' => OtherModel\Predictions::class,
        'observationSeries' => OtherModel\Predictions::class,
        'latestValue' => OtherModel\Predictions::class,
        'predictionTarget' => OtherModel\Predictions::class,
        'hypothesisDistribution' => OtherModel\Predictions::class,
        'contextKey' => OtherModel\Predictions::class,
        'predictionHorizon' => OtherModel\Predictions::class,
    ];

    private array $components = [];

    public function __call(string $name, array $arguments): mixed {
        if (method_exists($this, $name)) {
            return $this->{$name}(...$arguments);
        }
        $class = self::DOMAINS[$name] ?? throw new \BadMethodCallException('Unknown OtherModel method: ' . $name);
        $component = $this->components[$class] ??= new $class($this);
        return $component->{$name}(...$arguments);
    }

    public function __get(string $name): mixed {
        return match ($name) {
            'core' => $this->core,
            'projector' => $this->projector,
            default => throw new \OutOfBoundsException('Unknown OtherModel state: ' . $name),
        };
    }

    public const WORK_TYPE = 'other_model_parse';
    public const MAX_HYPOTHESES = 3;
    public const HISTORY_LIMIT = 512;
    public const PSEUDOCOUNT = 1.0;
    public const USABLE_POSTERIOR = 0.55;
    public const USABLE_FORWARD_SCORE = 0.5;
    public const ASSESSMENT_SEMANTICS = [
        'usable' => 'forecast_eligible_not_semantically_validated',
        'posterior' => 'forecast_support_not_proposition_probability',
        'backward_score' => 'fixed_provenance_weight_not_inverse_consistency',
    ];

    private AgentObservationProjector $projector;
    private readonly Executive $core;

    public function __construct(Executive $core) {
        $this->core = $core;
        $this->projector = new AgentObservationProjector($core);
    }

    public function isAblated(): bool {
        $raw = mb_strtolower(trim((string) (getenv('NAVI_BRAIN_OTHER_MODEL_ABLATED') ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array{weight: float, basis: string} */
    public function utteranceObservability(UtteranceOutcome $outcome, int $responses, int $reactions): array {
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
        foreach (OtherModelCycle::getAll([ 'order' => ['id' => 'DESC'], 'limit' => 200, ]) as $cycle) {
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
            'basis' => sprintf('Reachability mode was %s; hearing source was %s.', $mode, $hearingLive ? 'active and fresh' : 'inactive or stale'),
        ];
    }

    /** @return array<string, mixed> */
    public function decisionState(): array {
        $state = null;
        foreach (OtherModelCycle::getAll([ 'order' => ['id' => 'DESC'], 'limit' => 50, ]) as $cycle) {
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

                $state['assessment_semantics'] = self::ASSESSMENT_SEMANTICS;
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
    public function status(): array {
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
    public function listFrameFacts(?string $status = 'active', int $limit = 100): array {
        $conditions = $status === null ? [] : ['status' => $status];
        return $this->recordData(OtherAgentFrameFact::getAllByWhere( $conditions, ['order' => ['id' => 'DESC'], 'limit' => $this->limit($limit)] ));
    }

    /** @return list<array<string, mixed>> */
    public function listHypotheses(?string $status = null, int $limit = 100): array {
        $conditions = $status === null ? [] : ['status' => $status];
        return $this->recordData(OtherModelHypothesis::getAllByWhere( $conditions, ['order' => ['id' => 'DESC'], 'limit' => $this->limit($limit)] ));
    }

    /** @return list<array<string, mixed>> */
    public function listPredictions(?string $status = null, int $limit = 100): array {
        $conditions = $status === null ? [] : ['status' => $status];
        return $this->recordData(OtherModelPrediction::getAllByWhere( $conditions, ['order' => ['id' => 'DESC'], 'limit' => $this->limit($limit)] ));
    }

    /** @return list<array<string, mixed>> */
    public function listCycles(?string $status = null, int $limit = 20): array {
        $conditions = $status === null ? [] : ['status' => $status];
        return $this->recordData(OtherModelCycle::getAllByWhere( $conditions, ['order' => ['id' => 'DESC'], 'limit' => $this->limit($limit)] ));
    }

    /** @return array<string, mixed> */
    public function replay(int $limit = 500): array {
        return (new OtherModelEvaluator())->report($this->limit($limit, 2000));
    }

    public function captureBaseline(): ?array {

        $existing = MetricSnapshot::getAllByWhere([ 'protocol_version' => 'other-model-pre-v1', 'scope_key' => 'social-feedback', ], ['order' => ['id' => 'ASC'], 'limit' => 1])[0] ?? null;
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

        $snapshot = new MetricSnapshot([
            'protocol_version' => 'other-model-pre-v1',
            'scope_key' => 'social-feedback',
            'node_id' => gethostname() ?: 'unknown',
            'vector' => $vector,
            'manifest' => $manifest,
            'checksum' => hash('sha256', json_encode($vector, JSON_THROW_ON_ERROR) . $manifest),
        ], true, true);
        $snapshot->save();
        return $snapshot->getData();
    }

    private function stateTtl(string $source): int {
        return match ($source) {
            'pet_hearing' => 180,
            'desktop_presence', 'input_activity' => 120,
            default => 300,
        };
    }

    private function sourceIsActive(string $sourceKey): bool {
        if (in_array($sourceKey, ['utterance_outcome', 'user_correction'], true)) {
            return true;
        }
        $source = SensorySource::getByField('source_key', $sourceKey);
        return $source instanceof SensorySource && $source->status === 'active';
    }

    private function limit(int $limit, int $maximum = 500): int {
        if ($limit < 1 || $limit > $maximum) {
            throw new InvalidArgumentException(sprintf('limit must be between 1 and %d.', $maximum));
        }
        return $limit;
    }

    /**
     * @param list<object> $records
     * @return list<array<string, mixed>>
     */
    private function recordData(array $records): array {
        return array_values(array_map(
            fn (object $record): array => $record instanceof OtherModelHypothesis
                ? array_merge($record->getData(), ['assessment' => $this->hypothesisAssessment($record->getData())])
                : $record->getData(),
            $records
        ));
    }

    private function timestamp(mixed $value): ?int {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        $parsed = is_string($value) ? strtotime($value) : false;
        return $parsed === false ? null : $parsed;
    }
}
