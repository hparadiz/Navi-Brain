<?php

declare(strict_types=1);

namespace NaviBrain\Perception\SensoryCortex;

use InvalidArgumentException;
use NaviBrain\Model\Sense;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\WorkingMemorySlot;

class Detection extends Component
{

    public function evaluate(Sense $sense, SenseReading $reading, ?SenseReading $previous, int $now, ?array $resolvedPrediction): ?SenseEvent {
        $config = is_array($sense->config) ? $sense->config : [];
        $payload = is_array($reading->payload) ? $reading->payload : [];
        $previousPayload = $previous instanceof SenseReading && is_array($previous->payload)
            ? $previous->payload
            : [];

        if (!$this->requirementsMet($config, $payload)) {
            return null;
        }

        $lastFired = $this->timestamp($sense->last_fired_at);
        $addressed = is_string($config['interrupt_severity'] ?? null);
        if (!$addressed
            && $lastFired !== null
            && ($now - $lastFired) < (int) $sense->refractory_seconds
        ) {
            return null;
        }

        $detected = match ((string) $sense->detector) {
            'change' => $this->detectChange($config, $payload, $previousPayload),
            'threshold' => $this->detectThreshold($config, $payload, $previousPayload),
            'absence' => $this->detectAbsence($config, $sense, $previous, $now),
            'rate' => $this->detectRate($config, $sense, $now),
            'pattern' => $this->detectPattern($config, $payload),
            default => null,
        };
        if ($detected === null) {
            return null;
        }

        $base = isset($config['significance']) && is_numeric($config['significance'])
            ? (float) $config['significance']
            : 0.5;
        $significance = $base;

        $predictionError = $this->forwardModel()->edgeError($detected['after'], $resolvedPrediction);
        $predictionPrecision = $resolvedPrediction === null
            ? null
            : (float) ($resolvedPrediction['precision'] ?? 0.1);
        if ($predictionError !== null && $predictionPrecision !== null) {
            $weightedSurprise = ((1.0 - $predictionPrecision) * 0.5)
                + ($predictionPrecision * $predictionError);
            $significance *= 0.2 + (0.8 * $weightedSurprise);
        }
        $significance = round(max(0.0, min(1.0, $significance)), 4);

        $event = new SenseEvent([
            'sense_key' => (string) $sense->sense_key,
            'source_key' => (string) $sense->source_key,
            'observed_at' => $this->timestamp($reading->observed_at) ?? $now,
            'summary' => $detected['summary'],
            'before' => $detected['before'],
            'after' => $detected['after'],
            'significance' => $significance,
            'reading_id' => (int) $reading->id,
            'tuning_version' => (int) $sense->tuning_version,
            'outcome' => 'pending',
        ], true, true);
        $event->save();

        $sense->setFields([ 'last_fired_at' => $now, 'events_emitted' => (int) $sense->events_emitted + 1, 'updated_at' => $now, ]);
        $sense->save();

        $this->core->emitEvent('sense.edge', [
            'sense_key' => (string) $sense->sense_key,
            'sense_event_id' => (int) $event->id,
            'summary' => $detected['summary'],
            'significance' => $significance,
            'prediction_id' => $resolvedPrediction['id'] ?? null,
            'prediction_error' => $predictionError,
            'prediction_precision' => $predictionPrecision,
        ]);

        $this->core->workingMemory()->publish(new WorkingMemorySlot([
            'slot_role' => 'newest_percept',
            'claim' => sprintf('[%s] %s', (string) $sense->sense_key, $detected['summary']),
            'record_type' => 'sense_edge',
            'record_id' => (int) $event->id,
            'confidence' => $significance,
        ], true, true));

        $this->core->wakeThreadNow('mind_stream');

        $severity = $config['interrupt_severity'] ?? null;
        if (is_string($severity) && $severity !== '') {
            $this->core->raiseInterrupt($this->interruptReason($sense, $config, $detected), $severity, 'sense:' . (string) $sense->sense_key);

            foreach ((array) ($config['wakes_threads'] ?? []) as $threadKey) {
                if (is_string($threadKey) && $threadKey !== '') {
                    $this->core->wakeThreadNow($threadKey, true);
                }
            }

            $field = is_string($config['field'] ?? null) ? $config['field'] : null;
            $said = $field === null ? null : ($detected['after'][$field] ?? null);
            if (is_string($said) && trim($said) !== '') {
                $this->core->rememberHeardSpeech(trim($said), (int) $event->id, ['sense_key' => (string) $sense->sense_key, 'sense_event_id' => (int) $event->id]);
            }
        }
        return $event;
    }

    /**
     * @param array<string, mixed> $config
     * @param array{summary: string, before: array, after: array} $detected
     */
    public function interruptReason(Sense $sense, array $config, array $detected): string {
        $field = is_string($config['field'] ?? null) ? $config['field'] : null;
        $arrived = $field === null ? null : ($detected['after'][$field] ?? null);
        if (is_string($arrived) && trim($arrived) !== '') {
            return sprintf('%s: %s', (string) $sense->sense_key, trim($arrived));
        }
        return sprintf('%s: %s', (string) $sense->sense_key, $detected['summary']);
    }

    /** @return array{summary: string, before: array, after: array}|null */
    public function detectChange(array $config, array $payload, array $previousPayload): ?array {
        $field = is_string($config['field'] ?? null) ? $config['field'] : null;
        if ($field === null) {
            if ($previousPayload === [] || $payload === $previousPayload) {
                return null;
            }
            return [
                'summary' => 'The reading changed.',
                'before' => $previousPayload,
                'after' => $payload,
            ];
        }

        $before = $previousPayload[$field] ?? null;
        $after = $payload[$field] ?? null;
        if ($before === $after) {
            return null;
        }

        if ($this->ignored($config, $after)) {
            return null;
        }

        if ($previousPayload === []) {
            return null;
        }

        $describeWith = is_string($config['summary_field'] ?? null) ? $config['summary_field'] : null;
        if ($describeWith !== null && array_key_exists($describeWith, $payload)) {
            $summary = sprintf('%s: %s', $describeWith, $this->stringify($payload[$describeWith]));
        } else {
            $summary = sprintf('%s changed from %s to %s.', $field, $this->stringify($before), $this->stringify($after));
        }

        return [
            'summary' => $summary,
            'before' => [$field => $before],
            'after' => [$field => $after],
        ];
    }

    /** @return array{summary: string, before: array, after: array}|null */
    public function detectThreshold(array $config, array $payload, array $previousPayload): ?array {
        $field = (string) ($config['field'] ?? '');
        $value = $payload[$field] ?? null;
        if (!is_numeric($value)) {
            return null;
        }
        $value = (float) $value;
        $previousValue = is_numeric($previousPayload[$field] ?? null)
            ? (float) $previousPayload[$field]
            : null;

        $above = isset($config['above']) && is_numeric($config['above']) ? (float) $config['above'] : null;
        $below = isset($config['below']) && is_numeric($config['below']) ? (float) $config['below'] : null;

        if ($previousValue === null && ($config['on_first_reading'] ?? true) === false) {
            return null;
        }

        if ($above !== null && $value > $above && ($previousValue === null || $previousValue <= $above)) {
            return [
                'summary' => sprintf('%s rose above %s (now %s).', $field, $this->stringify($above), $this->stringify($value)),
                'before' => [$field => $previousValue],
                'after' => [$field => $value],
            ];
        }
        if ($below !== null && $value < $below && ($previousValue === null || $previousValue >= $below)) {
            return [
                'summary' => sprintf('%s fell below %s (now %s).', $field, $this->stringify($below), $this->stringify($value)),
                'before' => [$field => $previousValue],
                'after' => [$field => $value],
            ];
        }
        return null;
    }

    /** @return array{summary: string, before: array, after: array}|null */
    public function detectAbsence(array $config, Sense $sense, ?SenseReading $previous, int $now): ?array {
        $window = max(60, (int) ($config['window_seconds'] ?? 1800));
        $previousAt = $previous instanceof SenseReading ? $this->timestamp($previous->observed_at) : null;
        if ($previousAt === null || ($now - $previousAt) < $window) {
            return null;
        }
        return [
            'summary' => sprintf('%s went quiet for %d seconds before this reading.', (string) $sense->source_key, $now - $previousAt),
            'before' => ['last_observed_at' => $previousAt],
            'after' => ['gap_seconds' => $now - $previousAt],
        ];
    }

    /** @return array{summary: string, before: array, after: array}|null */
    public function detectRate(array $config, Sense $sense, int $now): ?array {
        $window = max(10, (int) ($config['window_seconds'] ?? 300));
        $limit = max(1, (int) ($config['max_per_window'] ?? 10));
        $count = 0;
        foreach (SenseReading::getAllByWhere(['source_key' => (string) $sense->source_key], ['order' => ['id' => 'DESC'], 'limit' => 200]) as $reading) {
            $at = $this->timestamp($reading->observed_at);
            if ($at !== null && ($now - $at) <= $window) {
                $count++;
            }
        }
        if ($count <= $limit) {
            return null;
        }
        return [
            'summary' => sprintf('%s produced %d readings in %d seconds, above its usual %d.', (string) $sense->source_key, $count, $window, $limit),
            'before' => ['expected_max' => $limit],
            'after' => ['observed' => $count, 'window_seconds' => $window],
        ];
    }

    /** @return array{summary: string, before: array, after: array}|null */
    public function detectPattern(array $config, array $payload): ?array {
        $field = (string) ($config['field'] ?? '');
        $pattern = (string) ($config['pattern'] ?? '');
        $text = $payload[$field] ?? null;
        if (!is_string($text) || $text === '' || $pattern === '') {
            return null;
        }
        if ($this->ignored($config, $text)) {
            return null;
        }
        if (@preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }
        return [
            'summary' => sprintf('%s matched the watched pattern.', $field),
            'before' => ['pattern' => $pattern],
            'after' => ['field' => $field, 'match' => mb_substr((string) ($matches[0] ?? ''), 0, 200)],
        ];
    }

    /** @param array<string, mixed> $config */
    public function validateDetectorConfig(string $detector, array $config): void {
        $needsField = ['threshold', 'pattern'];
        if (in_array($detector, $needsField, true) && !is_string($config['field'] ?? null)) {
            throw new InvalidArgumentException(sprintf('A %s sense requires a config field name.', $detector));
        }
        if ($detector === 'threshold' && !isset($config['above']) && !isset($config['below'])) {
            throw new InvalidArgumentException('A threshold sense requires an above or below bound.');
        }
        if ($detector === 'pattern') {
            $pattern = (string) ($config['pattern'] ?? '');
            if ($pattern === '' || @preg_match($pattern, '') === false) {
                throw new InvalidArgumentException('A pattern sense requires a valid regular expression.');
            }
        }
        if (array_key_exists('requires', $config)) {
            if (!is_array($config['requires'])) {
                throw new InvalidArgumentException('requires must be a list of conditions.');
            }
            foreach ($config['requires'] as $condition) {
                if (!is_array($condition) || !is_string($condition['field'] ?? null)) {
                    throw new InvalidArgumentException('Each requirement needs a field name.');
                }
                if (!isset($condition['above']) && !isset($condition['below'])
                    && !array_key_exists('equals', $condition)) {
                    throw new InvalidArgumentException('Each requirement needs an above, below, or equals bound.');
                }
            }
        }
        if (array_key_exists('interrupt_severity', $config)
            && !in_array($config['interrupt_severity'], ['critical', 'high', 'normal'], true)
        ) {
            throw new InvalidArgumentException('interrupt_severity must be critical, high, or normal.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function senseAccepts(string $senseKey, array $payload): bool {
        $sense = Sense::getByField('sense_key', $senseKey);
        if (!$sense instanceof Sense) {
            return true;
        }
        $config = is_array($sense->config) ? $sense->config : [];
        if (!$this->requirementsMet($config, $payload)) {
            return false;
        }
        $field = is_string($config['field'] ?? null) ? $config['field'] : null;
        return $field === null || !$this->ignored($config, $payload[$field] ?? null);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $payload
     */
    public function requirementsMet(array $config, array $payload): bool {
        $requires = $config['requires'] ?? null;
        if (!is_array($requires) || $requires === []) {
            return true;
        }
        foreach ($requires as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $field = is_string($condition['field'] ?? null) ? $condition['field'] : null;
            if ($field === null || !array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];

            if (array_key_exists('equals', $condition) && $value !== $condition['equals']) {
                return false;
            }
            if (!is_numeric($value)) {
                continue;
            }
            $value = (float) $value;
            if (isset($condition['above']) && is_numeric($condition['above'])
                && $value <= (float) $condition['above']) {
                return false;
            }
            if (isset($condition['below']) && is_numeric($condition['below'])
                && $value >= (float) $condition['below']) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $config */
    public function ignored(array $config, mixed $value): bool {
        $ignore = $config['ignore_pattern'] ?? null;
        if (!is_string($ignore) || $ignore === '' || !is_string($value)) {
            return false;
        }
        return @preg_match($ignore, $value) === 1;
    }
}
