<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use InvalidArgumentException;
use NaviBrain\Core\ExecutiveCore\Executive;
use NaviBrain\Model\Sense;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\SensorySource;
use RuntimeException;
use Throwable;

class SensoryCortex
{
    private const DOMAINS = [
        'evaluate' => SensoryCortex\Detection::class,
        'interruptReason' => SensoryCortex\Detection::class,
        'detectChange' => SensoryCortex\Detection::class,
        'detectThreshold' => SensoryCortex\Detection::class,
        'detectAbsence' => SensoryCortex\Detection::class,
        'detectRate' => SensoryCortex\Detection::class,
        'detectPattern' => SensoryCortex\Detection::class,
        'validateDetectorConfig' => SensoryCortex\Detection::class,
        'senseAccepts' => SensoryCortex\Detection::class,
        'requirementsMet' => SensoryCortex\Detection::class,
        'ignored' => SensoryCortex\Detection::class,
        'pendingEvents' => SensoryCortex\EventManagement::class,
        'predictabilityByRecentSense' => SensoryCortex\EventManagement::class,
        'predictabilityBySense' => SensoryCortex\EventManagement::class,
        'expireStaleAddressed' => SensoryCortex\EventManagement::class,
        'addressedSenseKeys' => SensoryCortex\EventManagement::class,
        'recordOutcome' => SensoryCortex\EventManagement::class,
        'decay' => SensoryCortex\EventManagement::class,
        'tune' => SensoryCortex\EventManagement::class,
    ];

    private array $components = [];

    public function __call(string $name, array $arguments): mixed {
        if (method_exists($this, $name)) {
            return $this->{$name}(...$arguments);
        }
        $class = self::DOMAINS[$name] ?? throw new \BadMethodCallException('Unknown SensoryCortex method: ' . $name);
        $component = $this->components[$class] ??= new $class($this);
        return $component->{$name}(...$arguments);
    }

    public function __get(string $name): mixed {
        return match ($name) {
            'core' => $this->core,
            default => throw new \OutOfBoundsException('Unknown SensoryCortex state: ' . $name),
        };
    }

    public const PRECISION_FLOOR = 0.15;
    public const TUNING_MIN_SAMPLE = 12;
    public const AUTO_PAUSE_SAMPLE = 40;
    public const MAX_REFRACTORY_SECONDS = 21600;

    public const ADDRESSED_TTL_SECONDS = 240;

    private ?ForwardModel $forwardModel = null;

    public function __construct(private readonly Executive $core) {
    }

    public function forwardModel(): ForwardModel {
        return $this->forwardModel ??= new ForwardModel($this->core);
    }

    /** @return array<string, mixed> */
    public function authorizeSource(SensorySource $source): array {
        $sourceKey = (string) $source->source_key;
        $description = (string) $source->description;
        $reveals = (string) $source->reveals;
        $acquisition = (string) $source->acquisition;
        $authority = (string) $source->authority;
        $sampleIntervalSeconds = (int) $source->sample_interval_seconds;
        $readingTtlSeconds = (int) $source->reading_ttl_seconds;
        $this->requireKey($sourceKey, 'source key');
        if ($description === '' || $reveals === '') {
            throw new InvalidArgumentException('A source must state what it is and what it reveals.');
        }
        if (!in_array($authority, ['user', 'developer'], true)) {
            throw new RuntimeException('Sensory sources require user or developer authority; the executive cannot grant itself perception.');
        }
        if (!in_array($acquisition, ['continuous', 'on_demand'], true)) {
            throw new InvalidArgumentException('acquisition must be continuous or on_demand.');
        }

        $existing = SensorySource::getByField('source_key', $sourceKey);
        if ($existing instanceof SensorySource) {
            $existing->setFields([
                'description' => $description,
                'reveals' => $reveals,
                'acquisition' => $acquisition,
                'sample_interval_seconds' => max(1, $sampleIntervalSeconds),
                'reading_ttl_seconds' => max(60, $readingTtlSeconds),
                'updated_at' => time(),
            ]);
            $existing->save();
            return ['source' => $existing->getData(), 'created' => false];
        }

        $source->setFields([
            'effect_ceiling' => 'observe',
            'sample_interval_seconds' => max(1, $sampleIntervalSeconds),
            'reading_ttl_seconds' => max(60, $readingTtlSeconds),
            'status' => 'active', 'updated_at' => time(),
        ]);
        $source->save();
        $this->core->emitEvent('sense.source.authorized', [ 'source_key' => $sourceKey, 'authority' => $authority, 'reveals' => $reveals, 'acquisition' => $acquisition, ]);
        return ['source' => $source->getData(), 'created' => true];
    }

    public function setSourceStatus(string $sourceKey, string $status): array {
        if (!in_array($status, ['active', 'paused', 'revoked'], true)) {
            throw new InvalidArgumentException('status must be active, paused, or revoked.');
        }
        $source = $this->requireSource($sourceKey);
        $source->setFields(['status' => $status, 'updated_at' => time()]);
        $source->save();
        $this->core->emitEvent('sense.source.status', ['source_key' => $sourceKey, 'status' => $status]);

        if ($status !== 'active') {
            foreach (Sense::getAllByWhere(['source_key' => $sourceKey, 'status' => 'active']) as $sense) {
                $sense->setFields([ 'status' => 'paused', 'last_tuning_reason' => 'Source ' . $sourceKey . ' is no longer active.', 'updated_at' => time(), ]);
                $sense->save();
            }
            $this->forwardModel()->sourceStatusChanged($sourceKey, $status);
            $this->core->otherModel()->sourceStatusChanged($sourceKey, $status);
        }
        return $source->getData();
    }

    /** @return array<string, mixed> */
    public function defineSense(Sense $sense): array {
        $senseKey = (string) $sense->sense_key;
        $sourceKey = (string) $sense->source_key;
        $notices = (string) $sense->notices;
        $detector = (string) $sense->detector;
        $config = (array) $sense->config;
        $refractorySeconds = (int) $sense->refractory_seconds;
        $author = (string) $sense->author;
        $this->requireKey($senseKey, 'sense key');
        if ($notices === '') {
            throw new InvalidArgumentException('A sense must state what it notices.');
        }
        if (!in_array($detector, ['change', 'threshold', 'absence', 'rate', 'pattern'], true)) {
            throw new InvalidArgumentException('detector must be one of: change, threshold, absence, rate, pattern.');
        }

        $source = $this->requireSource($sourceKey);
        if ($source->status !== 'active') {
            throw new RuntimeException(sprintf( 'Source %s is %s; a sense cannot be defined over an inactive grant.', $sourceKey, $source->status ));
        }
        $this->validateDetectorConfig($detector, $config);

        $existing = Sense::getByField('sense_key', $senseKey);
        if ($existing instanceof Sense) {
            $existing->setFields([
                'notices' => $notices,
                'detector' => $detector,
                'config' => $config,
                'refractory_seconds' => max(0, $refractorySeconds),
                'tuning_version' => (int) $existing->tuning_version + 1,
                'last_tuning_reason' => 'Redefined by ' . $author . '.',
                'updated_at' => time(),
            ]);
            $existing->save();
            return ['sense' => $existing->getData(), 'created' => false];
        }

        $sense->setFields([
            'refractory_seconds' => max(0, $refractorySeconds),
            'status' => 'active', 'events_emitted' => 0,
            'events_useful' => 0, 'events_wasted' => 0,
            'precision_estimate' => 0.0, 'tuning_version' => 0,
            'updated_at' => time(),
        ]);
        $sense->save();
        $this->core->emitEvent('sense.defined', [ 'sense_key' => $senseKey, 'source_key' => $sourceKey, 'author' => $author, 'detector' => $detector, 'grants_new_access' => false, ]);
        return ['sense' => $sense->getData(), 'created' => true];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function ingest(string $sourceKey, array $payload, ?int $sequence = null, ?int $observedAt = null): array {
        $source = $this->requireSource($sourceKey);
        if ($source->status !== 'active') {
            return ['status' => 'source_inactive', 'source_key' => $sourceKey];
        }

        $now = time();
        $observedAt ??= $now;
        $digest = hash('sha256', json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ));
        $previous = $this->latestReading($sourceKey);

        $reading = new SenseReading([
            'source_key' => $sourceKey,
            'observed_at' => $observedAt,
            'sequence' => $sequence,
            'payload' => $payload,
            'digest' => $digest,
            'expires_at' => $now + max(60, (int) $source->reading_ttl_seconds),
        ], true, true);
        $reading->save();
        $source->setFields(['last_reading_at' => $observedAt, 'last_error' => null, 'updated_at' => $now]);
        $source->save();

        $forecast = $this->forwardModel()->observe($sourceKey, $reading, $previous, (int) $source->sample_interval_seconds);

        $events = [];
        foreach (Sense::getAllByWhere(['source_key' => $sourceKey, 'status' => 'active']) as $sense) {
            $event = $this->evaluate($sense, $reading, $previous, $now, $forecast['resolved']);
            if ($event !== null) {
                $row = $event->getData();
                $row['prediction_id'] = $forecast['resolved']['id'] ?? null;
                $row['prediction_error'] = $this->forwardModel()->edgeError(is_array($event->after) ? $event->after : [], $forecast['resolved']);
                $row['prediction_precision'] = $forecast['resolved']['precision'] ?? null;
                $events[] = $row;
            }
        }

        try {
            $otherModel = $this->core->otherModel()->observeReading($reading);
        } catch (Throwable $throwable) {
            $otherModel = ['status' => 'failed', 'error' => $throwable->getMessage()];
        }

        return [
            'status' => 'ingested',
            'reading_id' => (int) $reading->id,
            'unchanged' => $previous instanceof SenseReading && $previous->digest === $digest,
            'events' => $events,
            'forward_model' => $forecast,
            'other_model' => $otherModel,
        ];
    }

    /** @return array<string, mixed> */
    public function status(): array {
        $sources = [];
        foreach (SensorySource::getAll(['order' => ['source_key' => 'ASC']]) as $source) {
            $sources[] = $source->getData();
        }
        $senses = [];
        foreach (Sense::getAll(['order' => ['sense_key' => 'ASC']]) as $sense) {
            $senses[] = $sense->getData();
        }
        return [
            'sources' => $sources,
            'senses' => $senses,
            'pending_edges' => count($this->pendingEvents(200)),
            'forward_model' => $this->forwardModel()->status(),
        ];
    }

    public function latestSequence(string $sourceKey): ?int {
        foreach (SenseReading::getAllByWhere(['source_key' => $sourceKey], ['order' => ['id' => 'DESC'], 'limit' => 50]) as $reading) {
            if ($reading->sequence !== null) {
                return (int) $reading->sequence;
            }
        }
        return null;
    }

    private function latestReading(string $sourceKey): ?SenseReading {
        $rows = SenseReading::getAllByWhere(['source_key' => $sourceKey], ['order' => ['id' => 'DESC'], 'limit' => 1]);
        $reading = $rows[0] ?? null;
        return $reading instanceof SenseReading ? $reading : null;
    }

    private function requireSource(string $sourceKey): SensorySource {
        $source = SensorySource::getByField('source_key', $sourceKey);
        if (!$source instanceof SensorySource) {
            throw new RuntimeException(sprintf( 'Sensory source %s is not authorized. Perception must be granted before it can be sensed.', $sourceKey ));
        }
        return $source;
    }

    private function requireKey(string $value, string $name): void {
        if (!preg_match('/^[a-z][a-z0-9_]{1,62}$/', $value)) {
            throw new InvalidArgumentException($name . ' must be lowercase snake_case, 2 to 63 characters.');
        }
    }

    private function stringify(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'nothing';
        }
        if (is_scalar($value)) {
            return mb_substr((string) $value, 0, 120);
        }
        return mb_substr(json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '', 0, 120);
    }

    private function timestamp(mixed $value): ?int {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $parsed = strtotime((string) $value);
        return $parsed === false ? null : $parsed;
    }
}
