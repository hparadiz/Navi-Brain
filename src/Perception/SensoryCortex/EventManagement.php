<?php

declare(strict_types=1);

namespace NaviBrain\Perception\SensoryCortex;

use NaviBrain\Perception\SensoryCortex;

use InvalidArgumentException;
use NaviBrain\Model\Sense;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\SenseReading;
use RuntimeException;

class EventManagement extends Component
{

    /** @return list<array<string, mixed>> */
    public function pendingEvents(int $limit = 10, float $minSignificance = 0.0): array {
        $addressed = $this->addressedSenseKeys();
        $now = time();
        $pending = [];
        foreach (SenseEvent::getAllByWhere(['outcome' => 'pending'], ['order' => ['id' => 'DESC'], 'limit' => 200]) as $event) {
            $observedAt = $this->timestamp($event->observed_at);

            $isAddressed = in_array((string) $event->sense_key, $addressed, true)
                && $observedAt !== null
                && ($now - $observedAt) <= SensoryCortex::ADDRESSED_TTL_SECONDS;

            if (!$isAddressed && (float) $event->significance < $minSignificance) {
                continue;
            }
            $row = $event->getData();
            $row['addressed'] = $isAddressed;
            $pending[] = $row;
        }
        foreach ($pending as $index => $row) {
            if ($row['addressed'] === true) {
                continue;
            }
            $readingId = (int) ($row['reading_id'] ?? 0);
            $resolved = $readingId > 0
                ? $this->forwardModel()->resolvedForReading($readingId)
                : null;
            $pending[$index]['prediction_id'] = $resolved['id'] ?? null;
            $pending[$index]['prediction_error'] = $this->forwardModel()->edgeError(is_array($row['after'] ?? null) ? $row['after'] : [], $resolved);
            $pending[$index]['prediction_precision'] = $resolved['precision'] ?? null;
        }

        usort($pending, static fn (array $a, array $b): int => ($b['addressed'] <=> $a['addressed']) ?: ($b['significance'] <=> $a['significance']) ?: ($b['id'] <=> $a['id']));
        return array_slice($pending, 0, max(1, $limit));
    }

    /** @return array<string, float> */
    public function predictabilityByRecentSense(): array {
        return $this->predictabilityBySense();
    }

    public function predictabilityBySense(): array {
        $bySource = $this->forwardModel()->predictabilityBySource();
        $scores = [];
        foreach (Sense::getAll() as $sense) {
            $source = (string) $sense->source_key;
            if (isset($bySource[$source])) {
                $scores[(string) $sense->sense_key] = $bySource[$source];
            }
        }
        return $scores;
    }

    /** @return list<int> */
    public function expireStaleAddressed(): array {
        $addressed = $this->addressedSenseKeys();
        if ($addressed === []) {
            return [];
        }
        $now = time();
        $expired = [];
        foreach (SenseEvent::getAllByWhere(['outcome' => 'pending'], ['order' => ['id' => 'DESC'], 'limit' => 200]) as $event) {
            if (!in_array((string) $event->sense_key, $addressed, true)) {
                continue;
            }
            $observedAt = $this->timestamp($event->observed_at);
            if ($observedAt === null || ($now - $observedAt) <= SensoryCortex::ADDRESSED_TTL_SECONDS) {
                continue;
            }
            $this->recordOutcome((int) $event->id, 'expired', sprintf('Not answered within %d seconds of being said.', SensoryCortex::ADDRESSED_TTL_SECONDS));
            $expired[] = (int) $event->id;
        }
        return $expired;
    }

    /** @return list<string> */
    public function addressedSenseKeys(): array {
        $keys = [];
        foreach (Sense::getAllByWhere(['status' => 'active']) as $sense) {
            $config = is_array($sense->config) ? $sense->config : [];
            if (is_string($config['interrupt_severity'] ?? null)) {
                $keys[] = (string) $sense->sense_key;
            }
        }
        return $keys;
    }

    /** @return array<string, mixed> */
    public function recordOutcome(int $senseEventId, string $outcome, ?string $detail = null, ?int $memoryId = null): array {
        if (!in_array($outcome, ['used', 'accepted', 'ignored', 'expired'], true)) {
            throw new InvalidArgumentException('outcome must be used, accepted, ignored, or expired.');
        }
        $event = SenseEvent::getByID($senseEventId);
        if (!$event instanceof SenseEvent) {
            throw new RuntimeException(sprintf('Sense event %d does not exist.', $senseEventId));
        }
        if ($event->outcome !== 'pending') {
            return ['status' => 'already_recorded', 'sense_event' => $event->getData()];
        }

        $event->setFields([ 'outcome' => $outcome, 'outcome_detail' => $detail, 'memory_id' => $memoryId, ]);
        $event->save();

        $sense = Sense::getByField('sense_key', (string) $event->sense_key);
        if ($sense instanceof Sense) {
            $useful = in_array($outcome, ['used', 'accepted'], true);
            $sense->setFields([ 'events_useful' => (int) $sense->events_useful + ($useful ? 1 : 0), 'events_wasted' => (int) $sense->events_wasted + ($useful ? 0 : 1), 'updated_at' => time(), ]);
            $judged = (int) $sense->events_useful + (int) $sense->events_wasted;
            $sense->setField('precision_estimate', $judged === 0 ? 0.0 : round((int) $sense->events_useful / $judged, 4));
            $sense->save();
        }

        return ['status' => 'recorded', 'sense_event' => $event->getData()];
    }

    /** @return array<string, int> */
    public function decay(?int $now = null): array {
        $now ??= time();
        $expiredReadings = 0;
        foreach (SenseReading::getAll(['order' => ['id' => 'ASC'], 'limit' => 5000]) as $reading) {
            $expiresAt = $this->timestamp($reading->expires_at);
            if ($expiresAt !== null && $expiresAt <= $now) {
                $reading->destroy();
                $expiredReadings++;
            }
        }

        $expiredEvents = 0;
        foreach (SenseEvent::getAllByWhere(['outcome' => 'pending'], ['order' => ['id' => 'ASC'], 'limit' => 500]) as $event) {
            $observedAt = $this->timestamp($event->observed_at) ?? $now;
            if (($now - $observedAt) > 86400) {
                $this->recordOutcome((int) $event->id, 'expired', 'Nothing consumed this edge within a day of it being noticed.');
                $expiredEvents++;
            }
        }

        return ['expired_readings' => $expiredReadings, 'expired_events' => $expiredEvents];
    }

    /** @return list<array<string, mixed>> */
    public function tune(?int $now = null): array {
        $now ??= time();
        $adjustments = [];

        foreach (Sense::getAllByWhere(['status' => 'active']) as $sense) {
            $judged = (int) $sense->events_useful + (int) $sense->events_wasted;
            if ($judged < SensoryCortex::TUNING_MIN_SAMPLE) {
                continue;
            }
            $precision = $judged === 0 ? 0.0 : (int) $sense->events_useful / $judged;
            $refractory = (int) $sense->refractory_seconds;

            if ($precision < SensoryCortex::PRECISION_FLOOR && $judged >= SensoryCortex::AUTO_PAUSE_SAMPLE) {
                $reason = sprintf('Paused after %d judged edges at %.1f%% precision; this sense was reliably not worth attention.', $judged, $precision * 100);
                $sense->setFields([
                    'status' => 'paused',
                    'precision_estimate' => round($precision, 4),
                    'tuning_version' => (int) $sense->tuning_version + 1,
                    'last_tuning_reason' => $reason,
                    'updated_at' => $now,
                ]);
                $sense->save();
                $this->core->emitEvent('sense.paused', [ 'sense_key' => (string) $sense->sense_key, 'precision' => round($precision, 4), 'judged' => $judged, ]);
                $adjustments[] = ['sense_key' => (string) $sense->sense_key, 'action' => 'paused', 'reason' => $reason];
                continue;
            }

            if ($precision < SensoryCortex::PRECISION_FLOOR) {
                $widened = min(SensoryCortex::MAX_REFRACTORY_SECONDS, max(60, (int) round($refractory * 2)));
                if ($widened === $refractory) {
                    continue;
                }
                $reason = sprintf('Widened refractory from %ds to %ds: %.1f%% precision over %d judged edges.', $refractory, $widened, $precision * 100, $judged);
                $sense->setFields([
                    'refractory_seconds' => $widened,
                    'precision_estimate' => round($precision, 4),
                    'tuning_version' => (int) $sense->tuning_version + 1,
                    'last_tuning_reason' => $reason,
                    'updated_at' => $now,
                ]);
                $sense->save();
                $adjustments[] = ['sense_key' => (string) $sense->sense_key, 'action' => 'widened', 'reason' => $reason];
                continue;
            }

            if ($precision > 0.6 && $refractory > 30) {
                $narrowed = max(30, (int) round($refractory / 1.5));
                $reason = sprintf('Narrowed refractory from %ds to %ds: %.1f%% precision over %d judged edges.', $refractory, $narrowed, $precision * 100, $judged);
                $sense->setFields([
                    'refractory_seconds' => $narrowed,
                    'precision_estimate' => round($precision, 4),
                    'tuning_version' => (int) $sense->tuning_version + 1,
                    'last_tuning_reason' => $reason,
                    'updated_at' => $now,
                ]);
                $sense->save();
                $adjustments[] = ['sense_key' => (string) $sense->sense_key, 'action' => 'narrowed', 'reason' => $reason];
            }
        }

        return $adjustments;
    }
}
