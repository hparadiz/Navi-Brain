<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use NaviBrain\Model\SenseReading;
use NaviBrain\Model\UtteranceOutcome;

class HearingStream
{

    private const WINDOW_SECONDS = 180;

    private const RUN_GAP_SECONDS = 15;

    private const ECHO_LOOKBACK_SECONDS = 90;

    private const ECHO_OVERLAP = 0.6;

    private const ECHO_MIN_WORDS = 4;

    /** @var list<array{at: int, seconds: float}> */
    private array $window = [];

    private ?int $lastAt = null;
    private ?int $runStartedAt = null;

    /** @var list<string>|null */
    private ?array $ownSpeech = null;
    private int $ownSpeechFetchedAt = 0;

    public function seed(?int $now = null): int
    {
        $now ??= time();
        $rows = [];
        foreach (SenseReading::getAllByWhere(['source_key' => 'pet_hearing'], ['order' => ['id' => 'DESC'], 'limit' => 200]) as $reading) {
            $at = $this->timestamp($reading->observed_at);
            if ($at === null || ($now - $at) > self::WINDOW_SECONDS) {
                continue;
            }
            $payload = is_array($reading->payload) ? $reading->payload : [];

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

    /** @return array<string, mixed> */
    public function annotate(string $text, int $durationMs, int $observedAt): array
    {
        $selfEcho = $this->isOwnVoice($text, $observedAt);
        $gap = $this->lastAt === null ? null : max(0, $observedAt - $this->lastAt);

        if (!$selfEcho) {
            $this->window[] = ['at' => $observedAt, 'seconds' => $durationMs / 1000];
            if ($this->lastAt === null || $gap === null || $gap > self::RUN_GAP_SECONDS) {
                $this->runStartedAt = $observedAt;
            }
            $this->lastAt = $observedAt;
        }

        $cutoff = $observedAt - self::WINDOW_SECONDS;
        $this->window = array_values(array_filter( $this->window, static fn (array $row): bool => $row['at'] >= $cutoff ));

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

            'speech_density' => round(min(1.0, $speechSeconds / self::WINDOW_SECONDS), 4),
            'run_seconds' => $this->runStartedAt === null || $selfEcho
                ? 0
                : max(0, $observedAt - $this->runStartedAt),
        ];
    }

    /** @return array<string, mixed> */
    public function silence(int $now): array
    {
        $cutoff = $now - self::WINDOW_SECONDS;
        $this->window = array_values(array_filter( $this->window, static fn (array $row): bool => $row['at'] >= $cutoff ));
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
