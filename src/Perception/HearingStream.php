<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use NaviBrain\Model\SenseReading;
use NaviBrain\Model\UtteranceOutcome;

/**
 * Observables about the hearing stream itself.
 *
 * Pet hands over one transcribed chunk at a time and says nothing about the
 * shape of the audio it came from. That shape is the whole difference between
 * a room full of people on a call and someone turning to say something to Navi,
 * and it is measurable without asking the desktop anything: a meeting is a
 * chunk every few seconds for minutes on end, while being spoken to is a short
 * burst after quiet.
 *
 * Measured against the live stream during a call: chunks arrive with a median
 * gap of 5 seconds at 11 to 14 per minute, and the gap between that call and
 * the quiet before it was 1315 seconds. There is no ambiguous middle to
 * straddle, so density is a reliable discriminator and no microphone routing
 * probe is needed.
 *
 * Everything here is a count, a duration, or a ratio. Nothing decides what
 * "meeting" means; that judgement lives in sense configuration, where it can be
 * retuned without touching code.
 */
final class HearingStream
{
    /** Long enough to span a lull in conversation, short enough to react. */
    private const WINDOW_SECONDS = 180;

    /** Chunks closer together than this belong to one unbroken run of talking. */
    private const RUN_GAP_SECONDS = 15;

    /** How far back Navi's own speech can still be arriving through the mic. */
    private const ECHO_LOOKBACK_SECONDS = 90;

    /** Fraction of a chunk's words that must appear in Navi's own recent speech. */
    private const ECHO_OVERLAP = 0.6;

    /** Below this many words an overlap match is coincidence, not an echo. */
    private const ECHO_MIN_WORDS = 4;

    /** @var list<array{at: int, seconds: float}> */
    private array $window = [];

    private ?int $lastAt = null;
    private ?int $runStartedAt = null;

    /** @var list<string>|null */
    private ?array $ownSpeech = null;
    private int $ownSpeechFetchedAt = 0;

    /**
     * Rebuild the trailing window from stored readings.
     *
     * Without this a daemon restart mid-meeting reports an empty window, which
     * reads exactly like the room falling silent. Readings live well past this
     * window before compaction, so the reconstruction is complete.
     */
    public function seed(?int $now = null): int
    {
        $now ??= time();
        $rows = [];
        foreach (SenseReading::getAllByWhere(
            ['source_key' => 'pet_hearing'],
            ['order' => ['id' => 'DESC'], 'limit' => 200]
        ) as $reading) {
            $at = $this->timestamp($reading->observed_at);
            if ($at === null || ($now - $at) > self::WINDOW_SECONDS) {
                continue;
            }
            $payload = is_array($reading->payload) ? $reading->payload : [];
            // Silence markers and Navi's own echo were never part of the density
            // they are measured against, so they must not be replayed into it.
            if (($payload['self_echo'] ?? false) === true || ($payload['silence'] ?? false) === true) {
                continue;
            }
            $rows[] = ['at' => $at, 'seconds' => ((int) ($payload['duration_ms'] ?? 0)) / 1000];
        }
        usort($rows, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $this->window = $rows;

        foreach ($rows as $row) {
            if ($this->lastAt === null || ($row['at'] - $this->lastAt) > self::RUN_GAP_SECONDS) {
                $this->runStartedAt = $row['at'];
            }
            $this->lastAt = $row['at'];
        }
        return count($rows);
    }

    /**
     * Describe one chunk and the stream it arrived in.
     *
     * @return array<string, mixed>
     */
    public function annotate(string $text, int $durationMs, int $observedAt): array
    {
        $selfEcho = $this->isOwnVoice($text, $observedAt);
        $gap = $this->lastAt === null ? null : max(0, $observedAt - $this->lastAt);

        // Navi's own voice coming back through the microphone is not the room
        // talking, so it must not inflate the density it would be measured by.
        if (!$selfEcho) {
            $this->window[] = ['at' => $observedAt, 'seconds' => $durationMs / 1000];
            if ($this->lastAt === null || $gap === null || $gap > self::RUN_GAP_SECONDS) {
                $this->runStartedAt = $observedAt;
            }
            $this->lastAt = $observedAt;
        }

        $cutoff = $observedAt - self::WINDOW_SECONDS;
        $this->window = array_values(array_filter(
            $this->window,
            static fn (array $row): bool => $row['at'] >= $cutoff
        ));

        $chunks = count($this->window);
        $speechSeconds = 0.0;
        foreach ($this->window as $row) {
            $speechSeconds += $row['seconds'];
        }

        return [
            'text' => $text,
            'duration_ms' => $durationMs,
            'silence' => false,
            'self_echo' => $selfEcho,
            'seconds_since_previous' => $gap,
            'chunks_in_window' => $chunks,
            'window_seconds' => self::WINDOW_SECONDS,
            'speech_seconds_in_window' => round($speechSeconds, 2),
            // What fraction of the last three minutes was audible speech. A call
            // saturates this; a remark to Navi barely moves it.
            'speech_density' => round(min(1.0, $speechSeconds / self::WINDOW_SECONDS), 4),
            'run_seconds' => $this->runStartedAt === null || $selfEcho
                ? 0
                : max(0, $observedAt - $this->runStartedAt),
        ];
    }

    /**
     * Report the room being quiet.
     *
     * Every detector in the cortex runs on arrival, so a channel that goes
     * completely silent stops producing edges rather than producing the edge
     * that says it went silent. A call that ends abruptly would leave the last
     * reading showing forty chunks in the window and nothing would ever revise
     * it. Silence therefore has to be sampled on a clock like anything else.
     *
     * This does not disturb the run or the last-heard mark, so the gap reported
     * by the next real chunk is still the true gap since anyone last spoke.
     *
     * @return array<string, mixed>
     */
    public function silence(int $now): array
    {
        $cutoff = $now - self::WINDOW_SECONDS;
        $this->window = array_values(array_filter(
            $this->window,
            static fn (array $row): bool => $row['at'] >= $cutoff
        ));
        if ($this->lastAt !== null && ($now - $this->lastAt) > self::RUN_GAP_SECONDS) {
            $this->runStartedAt = null;
        }

        $speechSeconds = 0.0;
        foreach ($this->window as $row) {
            $speechSeconds += $row['seconds'];
        }

        return [
            'text' => '',
            'duration_ms' => 0,
            'silence' => true,
            'self_echo' => false,
            'seconds_since_previous' => $this->lastAt === null ? null : max(0, $now - $this->lastAt),
            'chunks_in_window' => count($this->window),
            'window_seconds' => self::WINDOW_SECONDS,
            'speech_seconds_in_window' => round($speechSeconds, 2),
            'speech_density' => round(min(1.0, $speechSeconds / self::WINDOW_SECONDS), 4),
            'run_seconds' => 0,
        ];
    }

    /** Seconds since anyone last actually spoke, or null if nothing was heard yet. */
    public function secondsSinceSpeech(int $now): ?int
    {
        return $this->lastAt === null ? null : max(0, $now - $this->lastAt);
    }

    /**
     * Did Navi just say this?
     *
     * Pet transcribes whatever the microphone hears, including Navi's own text to
     * speech. Left alone that closes a loop Navi is on both ends of: Navi answers
     * herself, and Navi's own voice counts as someone replying to Navi.
     *
     * The comparison is word overlap rather than equality because the mic path
     * mangles the text on the way back.
     */
    private function isOwnVoice(string $text, int $observedAt): bool
    {
        $words = $this->words($text);
        if (count($words) < self::ECHO_MIN_WORDS) {
            return false;
        }
        foreach ($this->recentOwnSpeech($observedAt) as $spoken) {
            $spokenWords = array_flip($this->words($spoken));
            if ($spokenWords === []) {
                continue;
            }
            $shared = 0;
            foreach ($words as $word) {
                if (isset($spokenWords[$word])) {
                    $shared++;
                }
            }
            if (($shared / count($words)) >= self::ECHO_OVERLAP) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function recentOwnSpeech(int $observedAt): array
    {
        if ($this->ownSpeech !== null && ($observedAt - $this->ownSpeechFetchedAt) < 10) {
            return $this->ownSpeech;
        }
        $this->ownSpeechFetchedAt = $observedAt;
        $this->ownSpeech = [];
        foreach (UtteranceOutcome::getAll(['order' => ['id' => 'DESC'], 'limit' => 8]) as $outcome) {
            $spokenAt = $this->timestamp($outcome->spoken_at);
            if ($spokenAt === null || ($observedAt - $spokenAt) > self::ECHO_LOOKBACK_SECONDS) {
                continue;
            }
            $utterance = trim((string) $outcome->utterance);
            if ($utterance !== '') {
                $this->ownSpeech[] = $utterance;
            }
        }
        return $this->ownSpeech;
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        $normalized = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text);
        $parts = preg_split('/\s+/u', trim($normalized)) ?: [];
        return array_values(array_filter($parts, static fn (string $word): bool => $word !== ''));
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_numeric($value)) {
            return (int) $value;
        }
        $parsed = strtotime((string) $value);
        return $parsed === false ? null : $parsed;
    }
}
