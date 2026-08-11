<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use NaviBrain\Core\ExecutiveCore;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\UtteranceOutcome;

/** Projects authorized observables into the deliberately small ToM grammar. */
final class AgentObservationProjector
{
    /** @var array<string, list<string>> */
    private const DOMAINS = [
        'reachability' => ['present', 'away', 'unknown'],
        'input_state' => ['active', 'inactive', 'unknown'],
        'conversation_activity' => ['recent', 'cooling', 'stale', 'unknown'],
        'shell_activity' => ['recent', 'cooling', 'stale', 'unknown'],
        'terminal_activity' => ['recent', 'cooling', 'stale', 'unknown'],
        'process_activity' => ['recent', 'cooling', 'stale', 'unknown'],
        'audio_state' => ['playing', 'stopped', 'unknown'],
        'session_state' => ['locked', 'unlocked', 'unknown'],
        'speech_state' => ['direct_speech', 'ambient_speech', 'quiet'],
        'interaction_outcome' => ['reply_and_reaction', 'reply', 'reaction', 'no_reply'],
    ];

    public function __construct(private readonly ExecutiveCore $core)
    {
    }

    /** @return array<string, mixed>|null */
    public function reading(SenseReading $reading): ?array
    {
        $source = (string) $reading->source_key;
        $payload = is_array($reading->payload) ? $reading->payload : [];
        $at = $this->timestamp($reading->observed_at) ?? time();
        $projection = match ($source) {
            'desktop_presence' => $this->booleanProjection(
                'reachability',
                $payload['using_computer'] ?? null,
                'present',
                'away'
            ),
            'input_activity' => $this->booleanProjection(
                'input_state',
                $payload['active_in_window'] ?? null,
                'active',
                'inactive'
            ),
            'conversation_activity' => $this->ageProjection(
                'conversation_activity',
                $payload['seconds_since_write'] ?? null
            ),
            'shell_activity' => $this->ageProjection(
                'shell_activity',
                $payload['seconds_since_write'] ?? null
            ),
            'terminal_activity' => $this->ageProjection(
                'terminal_activity',
                $payload['seconds_since_write'] ?? null
            ),
            'process_activity' => $this->ageProjection(
                'process_activity',
                $payload['seconds_since_newest'] ?? null
            ),
            'audio_playback' => $this->booleanProjection(
                'audio_state',
                $payload['playing'] ?? null,
                'playing',
                'stopped'
            ),
            'session_lock' => $this->booleanProjection(
                'session_state',
                ($payload['known'] ?? false) === true ? ($payload['locked'] ?? null) : null,
                'locked',
                'unlocked'
            ),
            'pet_hearing' => $this->hearingProjection($payload),
            default => null,
        };
        if ($projection === null) {
            return null;
        }
        return array_merge($projection, [
            'source_key' => $source,
            'reading_id' => (int) $reading->id,
            'observed_at' => $at,
            'evidence' => $this->boundedEvidence($source, $payload),
        ]);
    }

    /** @return array<string, mixed> */
    public function outcome(UtteranceOutcome $outcome): array
    {
        $responses = (int) $outcome->responses_in_window;
        $reactions = (int) $outcome->reactions_in_window;
        $value = match (true) {
            $responses > 0 && $reactions > 0 => 'reply_and_reaction',
            $responses > 0 => 'reply',
            $reactions > 0 => 'reaction',
            default => 'no_reply',
        };
        return [
            'source_key' => 'utterance_outcome',
            'feature_key' => 'interaction_outcome',
            'value' => $value,
            'domain' => self::DOMAINS['interaction_outcome'],
            'observable' => (float) $outcome->observability_weight > 0.0,
            'weight' => max(0.0, min(1.0, (float) $outcome->observability_weight)),
            'outcome_id' => (int) $outcome->id,
            'observed_at' => $this->timestamp($outcome->spoken_at) + (int) $outcome->window_seconds,
            'evidence' => [
                'responses' => $responses,
                'reactions' => $reactions,
                'latency_seconds' => $outcome->response_latency_seconds,
                'present_at_utterance' => $outcome->present_at_utterance,
                'presence_state_at_utterance' => $outcome->presence_state_at_utterance,
                'observability_basis' => $outcome->observability_basis,
            ],
        ];
    }

    /** @return list<string> */
    public static function domain(string $feature): array
    {
        return self::DOMAINS[$feature] ?? [];
    }

    /** @return array<string, mixed> */
    private function booleanProjection(
        string $feature,
        mixed $raw,
        string $trueValue,
        string $falseValue
    ): array {
        $known = is_bool($raw) || $raw === 0 || $raw === 1 || $raw === '0' || $raw === '1';
        return [
            'feature_key' => $feature,
            'value' => !$known ? 'unknown' : ((bool) $raw ? $trueValue : $falseValue),
            'domain' => self::DOMAINS[$feature],
            'observable' => $known,
            'weight' => $known ? 1.0 : 0.0,
        ];
    }

    /** @return array<string, mixed> */
    private function ageProjection(string $feature, mixed $raw): array
    {
        $known = is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric($raw));
        $age = $known ? max(0, (int) $raw) : null;
        $value = match (true) {
            $age === null => 'unknown',
            $age <= 90 => 'recent',
            $age <= 600 => 'cooling',
            default => 'stale',
        };
        return [
            'feature_key' => $feature,
            'value' => $value,
            'domain' => self::DOMAINS[$feature],
            'observable' => $known,
            'weight' => $known ? 0.85 : 0.0,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed>|null */
    private function hearingProjection(array $payload): ?array
    {
        if (($payload['self_echo'] ?? false) === true) {
            return null;
        }
        $quiet = ($payload['silence'] ?? false) === true;
        $direct = !$quiet && $this->core->sensoryCortex()->senseAccepts('heard_speech', $payload);
        return [
            'feature_key' => 'speech_state',
            'value' => $quiet ? 'quiet' : ($direct ? 'direct_speech' : 'ambient_speech'),
            'domain' => self::DOMAINS['speech_state'],
            'observable' => true,
            'weight' => $direct ? 1.0 : 0.8,
            'direct_statement' => $direct ? trim((string) ($payload['text'] ?? '')) : null,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function boundedEvidence(string $source, array $payload): array
    {
        $allowed = match ($source) {
            'desktop_presence' => ['using_computer', 'active_within_seconds'],
            'input_activity' => ['active_in_window', 'window_seconds'],
            'conversation_activity', 'shell_activity', 'terminal_activity' => ['seconds_since_write', 'observed'],
            'process_activity' => ['seconds_since_newest', 'owned_processes'],
            'audio_playback' => ['playing', 'status'],
            'session_lock' => ['locked', 'known'],
            'pet_hearing' => ['text', 'silence', 'self_echo', 'chunks_in_window', 'speech_density', 'run_seconds'],
            default => [],
        };
        $evidence = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $value = $payload[$key];
                $evidence[$key] = is_string($value) ? mb_substr($value, 0, 600) : $value;
            }
        }
        return $evidence;
    }

    private function timestamp(mixed $value): int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        $parsed = is_string($value) ? strtotime($value) : false;
        return $parsed === false ? time() : $parsed;
    }
}
