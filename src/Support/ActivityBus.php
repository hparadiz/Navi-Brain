<?php

declare(strict_types=1);

namespace NaviBrain\Support;

/**
 * Best-effort local activity broadcast for optional observers such as Navi-Body.
 *
 * Subscribers bind Unix datagram sockets named activity-*.sock inside the
 * private runtime directory. Publishing never creates a service, waits for a
 * listener, or exposes operation arguments and results.
 */
final class ActivityBus
{
    private const MAX_OPERATION_LENGTH = 96;

    public function publish(
        string $source,
        string $phase,
        string $operation,
        ?string $domain = null,
        int $durationMs = 0,
        string $outcome = 'ok',
        array $context = [],
        ?string $flow = null
    ): void {
        if (!function_exists('socket_create')) {
            return;
        }

        $directory = self::runtimeDirectory();
        if (!is_dir($directory)) {
            return;
        }

        $targets = glob($directory . '/activity-*.sock');
        if (!is_array($targets) || $targets === []) {
            return;
        }

        $operation = self::token($operation, 'activity');
        $path = implode('>', self::pathFor($operation, $context));
        $packet = implode("\n", [
            'version 1',
            'at_ms ' . (string) ((int) floor(microtime(true) * 1000)),
            'source ' . self::token($source, 'brain'),
            'flow ' . self::token($flow ?? ($source . '-' . $operation), 'activity'),
            'phase ' . self::token($phase, 'event'),
            'operation ' . $operation,
            'domain ' . self::token($domain ?? self::domainFor($operation), 'brain'),
            'path ' . $path,
            'duration_ms ' . (string) max(0, $durationMs),
            'outcome ' . self::token($outcome, 'ok'),
            '',
        ]);

        foreach ($targets as $target) {
            $socket = @socket_create(AF_UNIX, SOCK_DGRAM, 0);
            if ($socket === false) {
                return;
            }
            @socket_sendto($socket, $packet, strlen($packet), 0, $target);
            socket_close($socket);
        }
    }

    public static function runtimeDirectory(): string
    {
        $override = getenv('NAVI_BRAIN_ACTIVITY_DIR');
        if (is_string($override) && trim($override) !== '') {
            return rtrim(trim($override), '/');
        }

        $runtime = getenv('XDG_RUNTIME_DIR');
        if (is_string($runtime) && trim($runtime) !== '') {
            return rtrim(trim($runtime), '/') . '/navi-brain';
        }

        $user = getenv('USER');
        $user = is_string($user) ? self::token($user, 'local') : 'local';
        return '/tmp/navi-brain-' . $user;
    }

    public static function domainFor(string $operation): string
    {
        $operation = strtolower($operation);
        foreach ([
            'memory' => ['memory', 'recall', 'consolidat', 'checkpoint'],
            'self' => ['self', 'affect', 'appraisal', 'social'],
            'drives' => ['need', 'stimulat', 'boredom'],
            'senses' => ['sense', 'percept', 'presence', 'desktop', 'heard'],
            'continuity' => ['heartbeat', 'rhythm', 'sleep', 'replica'],
            'executive' => ['decision', 'intention', 'action', 'procedure', 'interrupt'],
            'cognition' => ['thread', 'stream', 'thought', 'daydream', 'worker', 'model'],
        ] as $domain => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($operation, $needle)) {
                    return $domain;
                }
            }
        }

        return 'brain';
    }

    /**
     * Content-free route through the implemented cognitive architecture.
     *
     * The route names stable subsystems, never the event payload itself. The
     * visualizer can therefore show real transitions without receiving memory,
     * prompts, observations, or model output.
     *
     * @param array<string, mixed> $context
     * @return list<string>
     */
    public static function pathFor(string $operation, array $context = []): array
    {
        $operation = strtolower($operation);

        if ($operation === 'decision_cycle.transition') {
            $from = self::decisionNode((string) ($context['completed_state'] ?? 'observe'));
            $to = self::decisionNode((string) ($context['next_state'] ?? 'retrieve'));
            return $from === $to ? [$from] : [$from, $to];
        }

        if (str_starts_with($operation, 'decision_cycle.')) {
            return match (true) {
                str_ends_with($operation, '.started') => ['intention', 'perception'],
                str_ends_with($operation, '.waiting') => ['reasoning', 'worker'],
                str_ends_with($operation, '.completed') => ['verification', 'adaptation', 'working_memory'],
                default => ['evaluation', 'attention'],
            };
        }

        foreach (self::prefixRoutes() as $prefix => $route) {
            if (str_starts_with($operation, $prefix)) {
                return $route;
            }
        }

        return match ($operation) {
            'brain_status', 'session' => ['continuity'],
            'remember_navi' => ['semantic_memory', 'retrieval', 'working_memory'],
            'brain_self_model' => ['self_model', 'working_memory'],
            'brain_remember_self' => ['feedback', 'self_model', 'semantic_memory'],
            'brain_checkpoint' => ['working_memory', 'continuity'],
            'brain_needs' => ['interoception', 'need'],
            'brain_stimulate' => ['cognitive_input', 'need', 'appraisal'],
            'brain_daydream' => ['need', 'worker', 'thought'],
            'brain_sleep' => ['sleep', 'consolidation'],
            'brain_heartbeat_status' => ['continuity', 'rhythm'],
            'brain_thoughts' => ['thought', 'working_memory'],
            'desktop_presence', 'desktop_look' => ['sensation', 'perception'],
            default => [self::domainFor($operation)],
        };
    }

    /** @return array<string, list<string>> */
    private static function prefixRoutes(): array
    {
        return [
            'sense.' => ['sensation', 'perception'],
            'percept.' => ['perception', 'working_memory'],
            'working_memory.' => ['working_memory'],
            'affect.' => ['perception', 'appraisal', 'working_memory'],
            'need.' => ['interoception', 'need', 'attention'],
            'intention.' => ['need', 'intention', 'attention'],
            'focus.' => ['attention', 'thread'],
            'interrupt.' => ['sensation', 'attention', 'thread'],
            'action.started' => ['selection', 'action'],
            'action.selected' => ['selection', 'action'],
            'action.finished' => ['action', 'feedback', 'verification'],
            'procedure.run.started' => ['procedural_memory', 'action'],
            'procedure.run.finished' => ['action', 'verification', 'procedural_memory'],
            'procedure.' => ['verification', 'procedural_memory'],
            'memory.stored' => ['working_memory', 'episodic_memory'],
            'memory.utterance' => ['social_perception', 'episodic_memory'],
            'memory.consolidated' => ['episodic_memory', 'consolidation', 'semantic_memory'],
            'memory.expired' => ['working_memory', 'continuity'],
            'self_model.' => ['feedback', 'self_model', 'working_memory'],
            'other_model.' => ['social_perception', 'other_model', 'working_memory'],
            'utterance.observed' => ['speech_input', 'social_perception', 'working_memory'],
            'utterance.reflected' => ['social_perception', 'other_model', 'appraisal'],
            'utterance.' => ['speech_input', 'social_perception'],
            'thread.created' => ['intention', 'thread'],
            'thread.step.' => ['thread', 'context', 'worker'],
            'thread.worker.' => ['worker', 'thread'],
            'thread.speech.' => ['thread', 'speech_output'],
            'thread.silence.' => ['thread', 'attention'],
            'thread.' => ['thread'],
            'work.queued' => ['context', 'worker'],
            'work.claimed' => ['worker', 'reasoning'],
            'work.' => ['worker', 'thread'],
            'thought.' => ['worker', 'thought', 'working_memory'],
            'stream.thinking' => ['working_memory', 'thought'],
            'stream.thought' => ['thought', 'working_memory'],
            'stream.murmur.' => ['thought', 'speech_output'],
            'speech.' => ['speech_output', 'feedback'],
            'session.' => ['attention', 'thread', 'context'],
            'sleep.started' => ['rhythm', 'sleep'],
            'sleep.completed' => ['sleep', 'consolidation', 'working_memory'],
            'sleep.' => ['sleep', 'continuity'],
            'backup.' => ['continuity', 'semantic_memory'],
            'rhythm.' => ['continuity', 'rhythm'],
            'cycle.started' => ['rhythm', 'attention'],
            'cycle.completed' => ['attention', 'continuity'],
            'cycle.' => ['rhythm', 'continuity'],
            'look.' => ['sensation', 'perception', 'working_memory'],
            'models.' => ['worker'],
            'safety.' => ['sensation', 'attention'],
            'metrics.' => ['feedback', 'continuity'],
            'forward.' => ['other_model', 'feedback'],
        ];
    }

    private static function decisionNode(string $state): string
    {
        return match (strtolower($state)) {
            'observe' => 'perception',
            'retrieve' => 'retrieval',
            'reason' => 'reasoning',
            'evaluate' => 'evaluation',
            'select' => 'selection',
            'execute' => 'action',
            'verify' => 'verification',
            'adapt' => 'adaptation',
            'complete' => 'working_memory',
            default => 'attention',
        };
    }

    private static function token(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        $value = trim($value, '-_.');
        if ($value === '') {
            return $fallback;
        }

        return substr($value, 0, self::MAX_OPERATION_LENGTH);
    }
}
