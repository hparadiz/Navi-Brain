<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use InvalidArgumentException;
use NaviBrain\Core\ExecutiveCore;
use NaviBrain\Model\Sense;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\SensorySource;
use RuntimeException;
use Throwable;

/**
 * Perception intake, edge detection, and sense self-tuning.
 *
 * The governing asymmetry: sources are granted by the user and are never
 * creatable here, while senses over an already-granted source may be invented
 * freely by the executive. Inventing a sense changes what Navi notices; it can
 * never change what Navi can reach.
 *
 * The unit that matters is the edge, not the sample. A reading that repeats
 * carries no information however often it arrives, so raw readings expire
 * quickly and only transitions become durable sense events.
 */
final class SensoryCortex
{
    /** A sense must clear this precision to keep firing once it has a sample. */
    private const PRECISION_FLOOR = 0.15;
    private const TUNING_MIN_SAMPLE = 12;
    private const AUTO_PAUSE_SAMPLE = 40;
    private const MAX_REFRACTORY_SECONDS = 21600;

    /**
     * How long something said to Navi stays something Navi owes an answer to.
     *
     * Four minutes: long enough to survive a slow generation queued behind
     * other work, short enough that Navi never answers a remark the person has
     * already walked away from. Answering late is its own kind of wrong, not a
     * partial success.
     */
    private const ADDRESSED_TTL_SECONDS = 240;

    private ?ForwardModel $forwardModel = null;

    public function __construct(private readonly ExecutiveCore $core)
    {
    }

    public function forwardModel(): ForwardModel
    {
        return $this->forwardModel ??= new ForwardModel($this->core);
    }

    /**
     * Grant a raw perception channel. User authority only, by construction:
     * this is the boundary endogenous sense creation must not cross.
     *
     * @return array<string, mixed>
     */
    public function authorizeSource(
        string $sourceKey,
        string $description,
        string $reveals,
        string $acquisition = 'continuous',
        int $sampleIntervalSeconds = 60,
        int $readingTtlSeconds = 3600,
        string $authority = 'user'
    ): array {
        $this->requireKey($sourceKey, 'source key');
        if ($description === '' || $reveals === '') {
            throw new InvalidArgumentException('A source must state what it is and what it reveals.');
        }
        if (!in_array($authority, ['user', 'developer'], true)) {
            throw new RuntimeException(
                'Sensory sources require user or developer authority; the executive cannot grant itself perception.'
            );
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

        /** @var SensorySource $source */
        $source = $this->core->insertRecord(SensorySource::class, [
            'source_key' => $sourceKey,
            'description' => $description,
            'reveals' => $reveals,
            'authority' => $authority,
            'effect_ceiling' => 'observe',
            'acquisition' => $acquisition,
            'sample_interval_seconds' => max(1, $sampleIntervalSeconds),
            'reading_ttl_seconds' => max(60, $readingTtlSeconds),
            'status' => 'active',
            'updated_at' => time(),
        ]);
        $this->core->emitEvent('sense.source.authorized', [
            'source_key' => $sourceKey,
            'authority' => $authority,
            'reveals' => $reveals,
            'acquisition' => $acquisition,
        ]);
        return ['source' => $source->getData(), 'created' => true];
    }

    public function setSourceStatus(string $sourceKey, string $status): array
    {
        if (!in_array($status, ['active', 'paused', 'revoked'], true)) {
            throw new InvalidArgumentException('status must be active, paused, or revoked.');
        }
        $source = $this->requireSource($sourceKey);
        $source->setFields(['status' => $status, 'updated_at' => time()]);
        $source->save();
        $this->core->emitEvent('sense.source.status', ['source_key' => $sourceKey, 'status' => $status]);

        // Revoking access must also silence everything derived from it.
        if ($status !== 'active') {
            foreach (Sense::getAllByWhere(['source_key' => $sourceKey, 'status' => 'active']) as $sense) {
                $sense->setFields([
                    'status' => 'paused',
                    'last_tuning_reason' => 'Source ' . $sourceKey . ' is no longer active.',
                    'updated_at' => time(),
                ]);
                $sense->save();
            }
            $this->forwardModel()->sourceStatusChanged($sourceKey, $status);
            $this->core->otherModel()->sourceStatusChanged($sourceKey, $status);
        }
        return $source->getData();
    }

    /**
     * Define a detector over an authorized source. This is the part the
     * executive may do on its own initiative.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function defineSense(
        string $senseKey,
        string $sourceKey,
        string $notices,
        string $detector,
        array $config = [],
        int $refractorySeconds = 60,
        string $author = 'agent'
    ): array {
        $this->requireKey($senseKey, 'sense key');
        if ($notices === '') {
            throw new InvalidArgumentException('A sense must state what it notices.');
        }
        if (!in_array($detector, ['change', 'threshold', 'absence', 'rate', 'pattern'], true)) {
            throw new InvalidArgumentException(
                'detector must be one of: change, threshold, absence, rate, pattern.'
            );
        }

        // The authority boundary: a sense may only exist over a live grant.
        $source = $this->requireSource($sourceKey);
        if ($source->status !== 'active') {
            throw new RuntimeException(sprintf(
                'Source %s is %s; a sense cannot be defined over an inactive grant.',
                $sourceKey,
                $source->status
            ));
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

        /** @var Sense $sense */
        $sense = $this->core->insertRecord(Sense::class, [
            'sense_key' => $senseKey,
            'source_key' => $sourceKey,
            'author' => $author,
            'notices' => $notices,
            'detector' => $detector,
            'config' => $config,
            'refractory_seconds' => max(0, $refractorySeconds),
            'status' => 'active',
            'events_emitted' => 0,
            'events_useful' => 0,
            'events_wasted' => 0,
            'precision_estimate' => 0.0,
            'tuning_version' => 0,
            'updated_at' => time(),
        ]);
        $this->core->emitEvent('sense.defined', [
            'sense_key' => $senseKey,
            'source_key' => $sourceKey,
            'author' => $author,
            'detector' => $detector,
            'grants_new_access' => false,
        ]);
        return ['sense' => $sense->getData(), 'created' => true];
    }

    /**
     * Accept one sample and run every active sense over it.
     *
     * Samplers call this out of process on their own cadence, so no sense can
     * block a cognitive wake.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function ingest(
        string $sourceKey,
        array $payload,
        ?int $sequence = null,
        ?int $observedAt = null
    ): array {
        $source = $this->requireSource($sourceKey);
        if ($source->status !== 'active') {
            return ['status' => 'source_inactive', 'source_key' => $sourceKey];
        }

        $now = time();
        $observedAt ??= $now;
        $digest = hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        $previous = $this->latestReading($sourceKey);

        /** @var SenseReading $reading */
        $reading = $this->core->insertRecord(SenseReading::class, [
            'source_key' => $sourceKey,
            'observed_at' => $observedAt,
            'sequence' => $sequence,
            'payload' => $payload,
            'digest' => $digest,
            'expires_at' => $now + max(60, (int) $source->reading_ttl_seconds),
        ]);
        $source->setFields(['last_reading_at' => $observedAt, 'last_error' => null, 'updated_at' => $now]);
        $source->save();

        $forecast = $this->forwardModel()->observe(
            $sourceKey,
            $reading,
            $previous,
            (int) $source->sample_interval_seconds
        );

        $events = [];
        foreach (Sense::getAllByWhere(['source_key' => $sourceKey, 'status' => 'active']) as $sense) {
            $event = $this->evaluate($sense, $reading, $previous, $now, $forecast['resolved']);
            if ($event !== null) {
                $row = $event->getData();
                $row['prediction_id'] = $forecast['resolved']['id'] ?? null;
                $row['prediction_error'] = $this->forwardModel()->edgeError(
                    is_array($event->after) ? $event->after : [],
                    $forecast['resolved']
                );
                $row['prediction_precision'] = $forecast['resolved']['precision'] ?? null;
                $events[] = $row;
            }
        }

        // The other-agent loop observes the same authorized reading after the
        // sensory forecast has resolved. It receives no new channel and cannot
        // make this ingest fail if its own bounded cycle abstains or degrades.
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

    /**
     * Detect an edge. Returns null when nothing changed, which is the common
     * and correct outcome.
     */
    private function evaluate(
        Sense $sense,
        SenseReading $reading,
        ?SenseReading $previous,
        int $now,
        ?array $resolvedPrediction
    ): ?SenseEvent {
        $config = is_array($sense->config) ? $sense->config : [];
        $payload = is_array($reading->payload) ? $reading->payload : [];
        $previousPayload = $previous instanceof SenseReading && is_array($previous->payload)
            ? $previous->payload
            : [];

        // Context can make a real edge meaningless. Speech is the clear case:
        // the same transition means someone addressed Navi when the room is
        // quiet and means nothing at all when it is the thirtieth chunk of a
        // meeting Navi is not part of.
        if (!$this->requirementsMet($config, $payload)) {
            return null;
        }

        // Refractory windows damp ambient sensors. They must not erase the
        // second sentence in a person’s turn: an interrupting sense has already
        // passed its contextual gate and every accepted chunk is evidence the
        // reply needs. Meeting-like speech is rejected above, before this
        // exception applies.
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

        // A changed value is not automatically surprising. Compare the
        // observed edge with the forecast made before it arrived, then let the
        // source's measured precision determine how much that error matters.
        // With no calibrated forecast, retain the old salience unchanged.
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

        /** @var SenseEvent $event */
        $event = $this->core->insertRecord(SenseEvent::class, [
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
        ]);

        $sense->setFields([
            'last_fired_at' => $now,
            'events_emitted' => (int) $sense->events_emitted + 1,
            'updated_at' => $now,
        ]);
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

        // Perception writes into the same bounded hub later reasoning reads.
        // The durable SenseEvent remains the evidence; this is only its live,
        // expiring workspace presence.
        $this->core->workingMemory()->publish(
            role: 'newest_percept',
            claim: sprintf('[%s] %s', (string) $sense->sense_key, $detected['summary']),
            recordType: 'sense_edge',
            recordId: (int) $event->id,
            confidence: $significance,
            ttlSeconds: 900
        );

        // Fresh evidence may make a stalled private thought useful again. The
        // wake is harmless while work is already in flight and otherwise cuts
        // short only the repetition backoff, not any safety boundary.
        $this->core->wakeThreadNow('mind_stream');

        // Most edges can wait to be found when percepts are next read. A few
        // stop being what they are if they arrive late: an answer to something
        // said out loud is only an answer while the person is still standing
        // there. A sense that declares interrupt_severity is saying its edges
        // expire, so the edge is put where the heartbeat looks first instead of
        // waiting its turn behind whatever the cycle was already doing.
        $severity = $config['interrupt_severity'] ?? null;
        if (is_string($severity) && $severity !== '') {
            $this->core->raiseInterrupt(
                $this->interruptReason($sense, $config, $detected),
                $severity,
                'sense:' . (string) $sense->sense_key
            );
            // Raising the interrupt records that it happened. Re-duing the
            // thread is what makes the next tick act on it, instead of the
            // record sitting until the idle interval runs out.
            foreach ((array) ($config['wakes_threads'] ?? []) as $threadKey) {
                if (is_string($threadKey) && $threadKey !== '') {
                    $this->core->wakeThreadNow($threadKey, true);
                }
            }

            // An edge expires; what was said does not. Addressed speech is the
            // user's half of the conversation, and it has to become memory here
            // or the sleep passes have nothing but Navi's own lines to work on.
            $field = is_string($config['field'] ?? null) ? $config['field'] : null;
            $said = $field === null ? null : ($detected['after'][$field] ?? null);
            if (is_string($said) && trim($said) !== '') {
                $this->core->rememberHeardSpeech(
                    trim($said),
                    (int) $event->id,
                    ['sense_key' => (string) $sense->sense_key, 'sense_event_id' => (int) $event->id]
                );
            }
        }
        return $event;
    }

    /**
     * State the edge the way the cycle needs to read it.
     *
     * The generic summary ("text changed from ... to ...") describes a field
     * moving, which is the right thing to store and the wrong thing to wake up
     * to. When the sense watches a single field, lead with the value that
     * arrived, because that is the thing that actually happened.
     *
     * @param array<string, mixed> $config
     * @param array{summary: string, before: array, after: array} $detected
     */
    private function interruptReason(Sense $sense, array $config, array $detected): string
    {
        $field = is_string($config['field'] ?? null) ? $config['field'] : null;
        $arrived = $field === null ? null : ($detected['after'][$field] ?? null);
        if (is_string($arrived) && trim($arrived) !== '') {
            return sprintf('%s: %s', (string) $sense->sense_key, trim($arrived));
        }
        return sprintf('%s: %s', (string) $sense->sense_key, $detected['summary']);
    }

    /** @return array{summary: string, before: array, after: array}|null */
    private function detectChange(array $config, array $payload, array $previousPayload): ?array
    {
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
        // Sources lie in characteristic ways. Whisper emits caption tokens like
        // "(upbeat music)" for non-speech audio, and treating those as
        // utterances is how a sense learns to report noise as signal.
        if ($this->ignored($config, $after)) {
            return null;
        }
        // A first reading is not a transition; there is nothing it changed from.
        if ($previousPayload === []) {
            return null;
        }
        // Some fields are good at detecting change and useless at describing
        // it. A digest is the right thing to compare and the wrong thing to
        // read: "output_digest changed from 2167b38 to 9a8b245" is a true
        // sentence that carries nothing. A sense may name a different field to
        // describe itself with, so detection and description can be the two
        // different jobs they are.
        $describeWith = is_string($config['summary_field'] ?? null) ? $config['summary_field'] : null;
        if ($describeWith !== null && array_key_exists($describeWith, $payload)) {
            $summary = sprintf(
                '%s: %s',
                $describeWith,
                $this->stringify($payload[$describeWith])
            );
        } else {
            $summary = sprintf(
                '%s changed from %s to %s.',
                $field,
                $this->stringify($before),
                $this->stringify($after)
            );
        }

        return [
            'summary' => $summary,
            'before' => [$field => $before],
            'after' => [$field => $after],
        ];
    }

    /** @return array{summary: string, before: array, after: array}|null */
    private function detectThreshold(array $config, array $payload, array $previousPayload): ?array
    {
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

        // With no previous sample there is no crossing, only a state. Reporting
        // it is right for a sense that answers "is this happening now" and wrong
        // for one that reports a thing ending: a counter starting at zero would
        // announce that a conversation just finished before one ever began.
        if ($previousValue === null && ($config['on_first_reading'] ?? true) === false) {
            return null;
        }

        // Fire on the crossing, not on every sample that remains over the line.
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
    private function detectAbsence(array $config, Sense $sense, ?SenseReading $previous, int $now): ?array
    {
        $window = max(60, (int) ($config['window_seconds'] ?? 1800));
        $previousAt = $previous instanceof SenseReading ? $this->timestamp($previous->observed_at) : null;
        if ($previousAt === null || ($now - $previousAt) < $window) {
            return null;
        }
        return [
            'summary' => sprintf(
                '%s went quiet for %d seconds before this reading.',
                (string) $sense->source_key,
                $now - $previousAt
            ),
            'before' => ['last_observed_at' => $previousAt],
            'after' => ['gap_seconds' => $now - $previousAt],
        ];
    }

    /** @return array{summary: string, before: array, after: array}|null */
    private function detectRate(array $config, Sense $sense, int $now): ?array
    {
        $window = max(10, (int) ($config['window_seconds'] ?? 300));
        $limit = max(1, (int) ($config['max_per_window'] ?? 10));
        $count = 0;
        foreach (SenseReading::getAllByWhere(
            ['source_key' => (string) $sense->source_key],
            ['order' => ['id' => 'DESC'], 'limit' => 200]
        ) as $reading) {
            $at = $this->timestamp($reading->observed_at);
            if ($at !== null && ($now - $at) <= $window) {
                $count++;
            }
        }
        if ($count <= $limit) {
            return null;
        }
        return [
            'summary' => sprintf(
                '%s produced %d readings in %d seconds, above its usual %d.',
                (string) $sense->source_key,
                $count,
                $window,
                $limit
            ),
            'before' => ['expected_max' => $limit],
            'after' => ['observed' => $count, 'window_seconds' => $window],
        ];
    }

    /** @return array{summary: string, before: array, after: array}|null */
    private function detectPattern(array $config, array $payload): ?array
    {
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
    private function validateDetectorConfig(string $detector, array $config): void
    {
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
                    throw new InvalidArgumentException(
                        'Each requirement needs an above, below, or equals bound.'
                    );
                }
            }
        }
        if (array_key_exists('interrupt_severity', $config)
            && !in_array($config['interrupt_severity'], ['critical', 'high', 'normal'], true)
        ) {
            throw new InvalidArgumentException(
                'interrupt_severity must be critical, high, or normal.'
            );
        }
    }

    /**
     * Unconsumed edges. Addressed ones first, then most significant.
     *
     * Significance measures how surprising a transition was, which is the right
     * ranking for ambient things and the wrong one for being spoken to. Someone
     * saying an ordinary sentence is not surprising, and under a pure
     * significance sort it loses its place to a louder change in the room while
     * the person waits for an answer. Senses that declare interrupt_severity are
     * saying their edges are addressed rather than observed, so they sort above
     * everything and cannot be crowded out of a truncated list.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingEvents(int $limit = 10, float $minSignificance = 0.0): array
    {
        $addressed = $this->addressedSenseKeys();
        $now = time();
        $pending = [];
        foreach (SenseEvent::getAllByWhere(
            ['outcome' => 'pending'],
            ['order' => ['id' => 'DESC'], 'limit' => 200]
        ) as $event) {
            $observedAt = $this->timestamp($event->observed_at);
            // Priority belongs to the sentence, not to the record of it. A
            // remark keeps its claim on attention only while someone could still
            // be waiting on it; past that it is something that was said, ranked
            // like any other thing that happened. Without this the queue fills
            // with unanswered speech and permanently outranks the present.
            $isAddressed = in_array((string) $event->sense_key, $addressed, true)
                && $observedAt !== null
                && ($now - $observedAt) <= self::ADDRESSED_TTL_SECONDS;
            // The significance floor filters ambient noise. Applying it to
            // something said to Navi would silently drop the sentence for being
            // unremarkable, which is most sentences.
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
            $pending[$index]['prediction_error'] = $this->forwardModel()->edgeError(
                is_array($row['after'] ?? null) ? $row['after'] : [],
                $resolved
            );
            $pending[$index]['prediction_precision'] = $resolved['precision'] ?? null;
        }

        usort($pending, static fn (array $a, array $b): int => ($b['addressed'] <=> $a['addressed'])
            ?: ($b['significance'] <=> $a['significance'])
            ?: ($b['id'] <=> $a['id']));
        return array_slice($pending, 0, max(1, $limit));
    }

    /**
     * How predictable each sense has become, from 0 (always news) to 1.
     *
     * Oudeyer, Kaplan and Hafner (2007) put interest on learning progress
     * rather than raw novelty. This measure is therefore exposed to appraisal
     * and monitoring; it is not itself a reward to maximize. Edge attention is
     * driven by the prediction error that existed at observation time.
     *
     * The old implementation compared normalized summary strings. That was
     * habituation, not a forward model: it could call a repeated sentence
     * predictable without ever predicting the next observation. Predictability
     * now comes from resolved one-step forecasts on the sense's source.
     *
     * @return array<string, float>
     */
    public function predictabilityByRecentSense(): array
    {
        return $this->predictabilityBySense();
    }

    private function predictabilityBySense(): array
    {
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

    /**
     * Close out speech nobody answered in time.
     *
     * An edge sitting pending forever is not a pending answer, it is a record
     * that Navi was spoken to and did not reply. Saying so is the honest outcome
     * and keeps the queue describing the present. Expired is already one of the
     * outcomes a sense learns from, so this feeds the same signal as any other
     * edge that went unused.
     *
     * @return list<int>
     */
    public function expireStaleAddressed(): array
    {
        $addressed = $this->addressedSenseKeys();
        if ($addressed === []) {
            return [];
        }
        $now = time();
        $expired = [];
        foreach (SenseEvent::getAllByWhere(
            ['outcome' => 'pending'],
            ['order' => ['id' => 'DESC'], 'limit' => 200]
        ) as $event) {
            if (!in_array((string) $event->sense_key, $addressed, true)) {
                continue;
            }
            $observedAt = $this->timestamp($event->observed_at);
            if ($observedAt === null || ($now - $observedAt) <= self::ADDRESSED_TTL_SECONDS) {
                continue;
            }
            $this->recordOutcome(
                (int) $event->id,
                'expired',
                sprintf('Not answered within %d seconds of being said.', self::ADDRESSED_TTL_SECONDS)
            );
            $expired[] = (int) $event->id;
        }
        return $expired;
    }

    /**
     * Sense keys whose edges are addressed to Navi rather than merely observed.
     *
     * @return list<string>
     */
    public function addressedSenseKeys(): array
    {
        $keys = [];
        foreach (Sense::getAllByWhere(['status' => 'active']) as $sense) {
            $config = is_array($sense->config) ? $sense->config : [];
            if (is_string($config['interrupt_severity'] ?? null)) {
                $keys[] = (string) $sense->sense_key;
            }
        }
        return $keys;
    }

    /**
     * Record what an edge actually caused. This is the only training signal
     * available without gradients, so it has to be honest: an event that
     * nothing consumed is waste, and a sense that mostly produces waste should
     * learn to fire less.
     *
     * @return array<string, mixed>
     */
    public function recordOutcome(int $senseEventId, string $outcome, ?string $detail = null, ?int $memoryId = null): array
    {
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

        $event->setFields([
            'outcome' => $outcome,
            'outcome_detail' => $detail,
            'memory_id' => $memoryId,
        ]);
        $event->save();

        $sense = Sense::getByField('sense_key', (string) $event->sense_key);
        if ($sense instanceof Sense) {
            $useful = in_array($outcome, ['used', 'accepted'], true);
            $sense->setFields([
                'events_useful' => (int) $sense->events_useful + ($useful ? 1 : 0),
                'events_wasted' => (int) $sense->events_wasted + ($useful ? 0 : 1),
                'updated_at' => time(),
            ]);
            $judged = (int) $sense->events_useful + (int) $sense->events_wasted;
            $sense->setField(
                'precision_estimate',
                $judged === 0 ? 0.0 : round((int) $sense->events_useful / $judged, 4)
            );
            $sense->save();
        }

        return ['status' => 'recorded', 'sense_event' => $event->getData()];
    }

    /**
     * Expire raw readings past their TTL and age out edges nothing consumed.
     *
     * @return array<string, int>
     */
    public function decay(?int $now = null): array
    {
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
        foreach (SenseEvent::getAllByWhere(
            ['outcome' => 'pending'],
            ['order' => ['id' => 'ASC'], 'limit' => 500]
        ) as $event) {
            $observedAt = $this->timestamp($event->observed_at) ?? $now;
            if (($now - $observedAt) > 86400) {
                $this->recordOutcome(
                    (int) $event->id,
                    'expired',
                    'Nothing consumed this edge within a day of it being noticed.'
                );
                $expiredEvents++;
            }
        }

        return ['expired_readings' => $expiredReadings, 'expired_events' => $expiredEvents];
    }

    /**
     * Tune each sense toward usefulness rather than volume.
     *
     * The objective is deliberately downstream impact, not novelty or surprise:
     * tuning for interestingness is how a perception system learns to chase
     * noise. A sense that keeps producing edges nothing uses widens its
     * refractory window, and one that keeps doing so pauses itself.
     *
     * @return list<array<string, mixed>>
     */
    public function tune(?int $now = null): array
    {
        $now ??= time();
        $adjustments = [];

        foreach (Sense::getAllByWhere(['status' => 'active']) as $sense) {
            $judged = (int) $sense->events_useful + (int) $sense->events_wasted;
            if ($judged < self::TUNING_MIN_SAMPLE) {
                continue;
            }
            $precision = $judged === 0 ? 0.0 : (int) $sense->events_useful / $judged;
            $refractory = (int) $sense->refractory_seconds;

            if ($precision < self::PRECISION_FLOOR && $judged >= self::AUTO_PAUSE_SAMPLE) {
                $reason = sprintf(
                    'Paused after %d judged edges at %.1f%% precision; this sense was reliably not worth attention.',
                    $judged,
                    $precision * 100
                );
                $sense->setFields([
                    'status' => 'paused',
                    'precision_estimate' => round($precision, 4),
                    'tuning_version' => (int) $sense->tuning_version + 1,
                    'last_tuning_reason' => $reason,
                    'updated_at' => $now,
                ]);
                $sense->save();
                $this->core->emitEvent('sense.paused', [
                    'sense_key' => (string) $sense->sense_key,
                    'precision' => round($precision, 4),
                    'judged' => $judged,
                ]);
                $adjustments[] = ['sense_key' => (string) $sense->sense_key, 'action' => 'paused', 'reason' => $reason];
                continue;
            }

            if ($precision < self::PRECISION_FLOOR) {
                $widened = min(self::MAX_REFRACTORY_SECONDS, max(60, (int) round($refractory * 2)));
                if ($widened === $refractory) {
                    continue;
                }
                $reason = sprintf(
                    'Widened refractory from %ds to %ds: %.1f%% precision over %d judged edges.',
                    $refractory,
                    $widened,
                    $precision * 100,
                    $judged
                );
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

            // Earning attention buys sensitivity back, but never below a floor
            // that would let one source flood the capsule.
            if ($precision > 0.6 && $refractory > 30) {
                $narrowed = max(30, (int) round($refractory / 1.5));
                $reason = sprintf(
                    'Narrowed refractory from %ds to %ds: %.1f%% precision over %d judged edges.',
                    $refractory,
                    $narrowed,
                    $precision * 100,
                    $judged
                );
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

    /** @return array<string, mixed> */
    public function status(): array
    {
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

    /**
     * Would this sense accept this reading? Exposed so measurement elsewhere can
     * reuse a sense's own judgement instead of keeping a divergent copy of it.
     *
     * @param array<string, mixed> $payload
     */
    public function senseAccepts(string $senseKey, array $payload): bool
    {
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
     * Conditions on the current reading that must hold for a sense to fire.
     *
     * Each entry names a field and one of above, below, or equals. An absent or
     * unreadable field satisfies the condition rather than failing it: a missing
     * observable is not evidence that the blocking situation is happening, and a
     * sense that goes permanently deaf because an annotation regressed is a
     * worse failure than one that occasionally fires during a meeting.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $payload
     */
    private function requirementsMet(array $config, array $payload): bool
    {
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

    /**
     * A sense may declare what its source reliably gets wrong. Tuning this
     * pattern is the cheapest way a sense gets better at its own job.
     *
     * @param array<string, mixed> $config
     */
    private function ignored(array $config, mixed $value): bool
    {
        $ignore = $config['ignore_pattern'] ?? null;
        if (!is_string($ignore) || $ignore === '' || !is_string($value)) {
            return false;
        }
        return @preg_match($ignore, $value) === 1;
    }

    /** Resume point for a source that provides its own monotonic cursor. */
    public function latestSequence(string $sourceKey): ?int
    {
        foreach (SenseReading::getAllByWhere(
            ['source_key' => $sourceKey],
            ['order' => ['id' => 'DESC'], 'limit' => 50]
        ) as $reading) {
            if ($reading->sequence !== null) {
                return (int) $reading->sequence;
            }
        }
        return null;
    }

    private function latestReading(string $sourceKey): ?SenseReading
    {
        $rows = SenseReading::getAllByWhere(
            ['source_key' => $sourceKey],
            ['order' => ['id' => 'DESC'], 'limit' => 1]
        );
        $reading = $rows[0] ?? null;
        return $reading instanceof SenseReading ? $reading : null;
    }

    private function requireSource(string $sourceKey): SensorySource
    {
        $source = SensorySource::getByField('source_key', $sourceKey);
        if (!$source instanceof SensorySource) {
            throw new RuntimeException(sprintf(
                'Sensory source %s is not authorized. Perception must be granted before it can be sensed.',
                $sourceKey
            ));
        }
        return $source;
    }

    private function requireKey(string $value, string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,62}$/', $value)) {
            throw new InvalidArgumentException(
                $name . ' must be lowercase snake_case, 2 to 63 characters.'
            );
        }
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'nothing';
        }
        if (is_scalar($value)) {
            return mb_substr((string) $value, 0, 120);
        }
        return mb_substr(json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '', 0, 120);
    }

    private function timestamp(mixed $value): ?int
    {
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
