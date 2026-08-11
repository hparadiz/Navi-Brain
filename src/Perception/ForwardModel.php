<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use NaviBrain\Core\ExecutiveCore;
use NaviBrain\Model\ForwardPrediction;
use NaviBrain\Model\SenseReading;

/**
 * One-step predictive model over authorized sensory streams.
 *
 * Friston (2010) describes predictive coding as reciprocal prediction and
 * precision-weighted error. This is the deliberately modest systems version:
 * persist the last one-step forecast, compare it with the next observation,
 * calibrate precision from recent errors, then extrapolate the next state.
 */
final class ForwardModel
{
    private const HISTORY = 12;
    private const RETENTION_PER_SOURCE = 64;
    private const MATCH_ERROR = 0.2;

    public function __construct(private readonly ExecutiveCore $core)
    {
    }

    /**
     * @return array{
     *   resolved: array<string, mixed>|null,
     *   next: array<string, mixed>
     * }
     */
    public function observe(
        string $sourceKey,
        SenseReading $reading,
        ?SenseReading $previous,
        int $nominalIntervalSeconds
    ): array {
        $now = time();
        $payload = is_array($reading->payload) ? $reading->payload : [];
        $resolved = $this->resolvePending($sourceKey, $reading, $payload, $now);
        $precision = $this->precision($sourceKey);
        $previousPayload = $previous instanceof SenseReading && is_array($previous->payload)
            ? $previous->payload
            : [];
        $predicted = $this->predict($payload, $previousPayload);
        $observedAt = $this->timestamp($reading->observed_at) ?? $now;

        /** @var ForwardPrediction $next */
        $next = $this->core->insertRecord(ForwardPrediction::class, [
            'source_key' => $sourceKey,
            'based_on_reading_id' => (int) $reading->id,
            'expected_at' => $observedAt + max(1, $nominalIntervalSeconds),
            'predicted' => $predicted,
            'precision' => $precision,
            'observed' => [],
            'status' => 'pending',
        ]);

        $this->prune($sourceKey);

        return ['resolved' => $resolved, 'next' => $next->getData()];
    }

    /**
     * Error for the fields an edge says changed. Returns null before a source
     * has made and resolved its first forecast.
     *
     * @param array<string, mixed> $after
     * @param array<string, mixed>|null $resolved
     */
    public function edgeError(array $after, ?array $resolved): ?float
    {
        if ($resolved === null || !is_array($resolved['predicted'] ?? null)) {
            return null;
        }
        $predicted = $resolved['predicted'];
        $subset = [];
        foreach (array_keys($after) as $field) {
            if (array_key_exists($field, $predicted)) {
                $subset[$field] = $predicted[$field];
            }
        }
        if ($subset === []) {
            return null;
        }
        return round($this->error($subset, $after), 4);
    }

    /** @return array<string, mixed>|null */
    public function resolvedForReading(int $readingId): ?array
    {
        $prediction = ForwardPrediction::getByField('observed_reading_id', $readingId);
        return $prediction instanceof ForwardPrediction ? $prediction->getData() : null;
    }

    /** Retire forecasts that can no longer receive authorized evidence. */
    public function sourceStatusChanged(string $sourceKey, string $status): int
    {
        if ($status === 'active') {
            return 0;
        }
        $expired = 0;
        foreach (ForwardPrediction::getAllByWhere([
            'source_key' => $sourceKey,
            'status' => 'pending',
        ]) as $prediction) {
            $prediction->setField('status', 'expired');
            $prediction->save();
            $expired++;
        }
        if ($expired > 0) {
            $this->core->emitEvent('forward.source_inactive', [
                'source_key' => $sourceKey,
                'source_status' => $status,
                'predictions_expired' => $expired,
            ]);
        }
        return $expired;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $pending = ForwardPrediction::getAllByWhere(
            ['status' => 'pending'],
            ['order' => ['id' => 'DESC'], 'limit' => 25]
        );
        $recent = ForwardPrediction::getAll([
            'order' => ['id' => 'DESC'],
            'limit' => 100,
        ]);
        $resolved = array_values(array_filter(
            $recent,
            static fn (ForwardPrediction $prediction): bool => in_array(
                $prediction->status,
                ['matched', 'violated'],
                true
            )
        ));
        $errors = array_map(
            static fn (ForwardPrediction $prediction): float => (float) $prediction->prediction_error,
            $resolved
        );
        return [
            'pending' => count($pending),
            'resolved' => count($resolved),
            'mean_error' => $errors === [] ? null : round(array_sum($errors) / count($errors), 4),
            'recent' => array_map(
                static fn (ForwardPrediction $prediction): array => $prediction->getData(),
                array_slice($recent, 0, 12)
            ),
        ];
    }

    /** @return array<string, float> keyed by source */
    public function predictabilityBySource(): array
    {
        $errors = [];
        foreach (ForwardPrediction::getAll(['order' => ['id' => 'DESC'], 'limit' => 2048]) as $prediction) {
            if (!in_array($prediction->status, ['matched', 'violated'], true)) {
                continue;
            }
            $source = (string) $prediction->source_key;
            $errors[$source] ??= [];
            if (count($errors[$source]) < self::HISTORY) {
                $errors[$source][] = (float) $prediction->prediction_error;
            }
        }
        $result = [];
        foreach ($errors as $source => $sourceErrors) {
            if (count($sourceErrors) < 3) {
                continue;
            }
            $result[$source] = round(max(
                0.0,
                min(1.0, 1.0 - (array_sum($sourceErrors) / count($sourceErrors)))
            ), 4);
        }
        return $result;
    }

    /** @return array<string, mixed>|null */
    private function resolvePending(
        string $sourceKey,
        SenseReading $reading,
        array $payload,
        int $now
    ): ?array {
        $pending = ForwardPrediction::getAllByWhere(
            ['source_key' => $sourceKey, 'status' => 'pending'],
            ['order' => ['id' => 'DESC']]
        );
        $prediction = array_shift($pending);
        foreach ($pending as $stale) {
            $stale->setField('status', 'expired');
            $stale->save();
        }
        if (!$prediction instanceof ForwardPrediction) {
            return null;
        }

        $predicted = is_array($prediction->predicted) ? $prediction->predicted : [];
        $error = round($this->error($predicted, $payload), 4);
        $status = $error <= self::MATCH_ERROR ? 'matched' : 'violated';
        $prediction->setFields([
            'observed_reading_id' => (int) $reading->id,
            'observed_at' => $this->timestamp($reading->observed_at) ?? $now,
            'observed' => $payload,
            'prediction_error' => $error,
            'status' => $status,
        ]);
        $prediction->save();

        // Prediction rows retain ordinary matches. Only a violated expectation
        // becomes an event; otherwise a continuous source would double the
        // event log merely by behaving exactly as expected.
        if ($status === 'violated') {
            $this->core->emitEvent('forward.violated', [
                'prediction_id' => (int) $prediction->id,
                'source_key' => $sourceKey,
                'observed_reading_id' => (int) $reading->id,
                'prediction_error' => $error,
                'precision' => (float) $prediction->precision,
                'precision_weighted_error' => round($error * (float) $prediction->precision, 4),
            ]);
        }

        return $prediction->getData();
    }

    private function precision(string $sourceKey): float
    {
        $errors = [];
        foreach (ForwardPrediction::getAllByWhere(
            ['source_key' => $sourceKey],
            ['order' => ['id' => 'DESC'], 'limit' => self::HISTORY * 2]
        ) as $prediction) {
            if (!in_array($prediction->status, ['matched', 'violated'], true)) {
                continue;
            }
            $errors[] = (float) $prediction->prediction_error;
            if (count($errors) >= self::HISTORY) {
                break;
            }
        }
        if (count($errors) < 3) {
            return 0.1;
        }
        return round(max(0.1, min(1.0, 1.0 - (array_sum($errors) / count($errors)))), 4);
    }

    private function prune(string $sourceKey): void
    {
        $predictions = ForwardPrediction::getAllByWhere(
            ['source_key' => $sourceKey],
            ['order' => ['id' => 'DESC']]
        );
        foreach (array_slice($predictions, self::RETENTION_PER_SOURCE) as $prediction) {
            $prediction->destroy();
        }
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $previous */
    private function predict(array $current, array $previous): array
    {
        $predicted = [];
        foreach ($current as $field => $value) {
            $prior = $previous[$field] ?? null;
            if (is_int($value) || is_float($value)) {
                $predicted[$field] = is_int($prior) || is_float($prior)
                    ? $value + ($value - $prior)
                    : $value;
                continue;
            }
            if (is_array($value) && !array_is_list($value)) {
                $predicted[$field] = $this->predict(
                    $value,
                    is_array($prior) && !array_is_list($prior) ? $prior : []
                );
                continue;
            }
            // Persistence is the safest categorical/text baseline. It makes a
            // change an explicit violated expectation without inventing one.
            $predicted[$field] = $value;
        }
        return $predicted;
    }

    private function error(mixed $predicted, mixed $observed): float
    {
        if ((is_int($predicted) || is_float($predicted))
            && (is_int($observed) || is_float($observed))
        ) {
            return min(1.0, abs((float) $observed - (float) $predicted)
                / max(1.0, abs((float) $observed), abs((float) $predicted)));
        }
        if (is_array($predicted) && is_array($observed)) {
            $keys = array_values(array_unique(array_merge(array_keys($predicted), array_keys($observed))));
            if ($keys === []) {
                return 0.0;
            }
            $sum = 0.0;
            foreach ($keys as $key) {
                if (!array_key_exists($key, $predicted) || !array_key_exists($key, $observed)) {
                    $sum += 1.0;
                    continue;
                }
                $sum += $this->error($predicted[$key], $observed[$key]);
            }
            return min(1.0, $sum / count($keys));
        }
        return $predicted === $observed ? 0.0 : 1.0;
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $parsed = strtotime($value);
        return $parsed === false ? null : $parsed;
    }
}
