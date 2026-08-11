<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use NaviBrain\Model\SenseEvent;

/**
 * Raw observables only.
 *
 * This class reads instruments. It does not decide what they mean, does not
 * weight them against each other, and does not contain the word for what they
 * add up to. Every method returns a number or a boolean and nothing else.
 *
 * An earlier version of this had the interpretation baked in: it decided who
 * was where and said so in its own vocabulary. That put a conclusion in the
 * code and left nothing for Navi to learn. Each of these is now its own source,
 * senses are defined over them, and what they mean together is carried in sense
 * configuration and in memory rather than here.
 *
 * Every probe is bounded so a full sweep stays far under a second.
 */
final class SignalSampler
{
    public function __construct(
        private readonly SessionState $session = new SessionState(),
        private readonly ?DesktopAwareness $awareness = null
    ) {
    }

    /** @return array<string, array<string, mixed>> keyed by source */
    public function sampleAll(?int $now = null): array
    {
        $now ??= time();
        return [
            'session_lock' => $this->sessionLock(),
            'conversation_activity' => $this->conversationActivity($now),
            'shell_activity' => $this->shellActivity($now),
            'input_activity' => $this->inputActivity(),
            'process_activity' => $this->processActivity($now),
            'terminal_activity' => $this->terminalActivity($now),
            'audio_playback' => $this->audioPlayback(),
        ];
    }

    /** @return array<string, mixed> */
    public function sessionLock(): array
    {
        $locked = $this->session->screenLocked();
        return ['locked' => $locked, 'known' => $locked !== null];
    }

    /** @return array<string, mixed> */
    public function conversationActivity(int $now): array
    {
        $home = (string) getenv('HOME');
        $age = $this->newestMtimeAge([$home . '/.claude/projects'], $now, 3);
        return ['seconds_since_write' => $age, 'observed' => $age !== null];
    }

    /** @return array<string, mixed> */
    public function shellActivity(int $now): array
    {
        $home = (string) getenv('HOME');
        $age = $this->fileAge([$home . '/.zsh_history', $home . '/.bash_history'], $now);
        return ['seconds_since_write' => $age, 'observed' => $age !== null];
    }

    /** @return array<string, mixed> */
    public function inputActivity(): array
    {
        $awareness = $this->awareness ?? new DesktopAwareness();
        try {
            $presence = $awareness->presence();
        } catch (\Throwable) {
            return ['active_in_window' => null, 'window_seconds' => null];
        }
        if (($presence['state'] ?? 'unavailable') === 'unavailable') {
            return ['active_in_window' => null, 'window_seconds' => null];
        }
        return [
            'active_in_window' => $presence['using_computer'] === null
                ? null
                : (bool) $presence['using_computer'],
            'window_seconds' => (int) ($presence['active_within_seconds'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function processActivity(int $now): array
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : null;
        if ($uid === null) {
            return ['seconds_since_newest' => null, 'owned_processes' => null];
        }
        $entries = @scandir('/proc');
        if ($entries === false) {
            return ['seconds_since_newest' => null, 'owned_processes' => null];
        }
        $newest = null;
        $count = 0;
        foreach ($entries as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $stat = @stat('/proc/' . $entry);
            if ($stat === false || ($stat['uid'] ?? -1) !== $uid) {
                continue;
            }
            $count++;
            $ctime = $stat['ctime'] ?? null;
            if ($ctime !== null && ($newest === null || $ctime > $newest)) {
                $newest = $ctime;
            }
        }
        return [
            'seconds_since_newest' => $newest === null ? null : max(0, $now - $newest),
            'owned_processes' => $count,
        ];
    }

    /** @return array<string, mixed> */
    public function terminalActivity(int $now): array
    {
        $newest = null;
        $entries = @scandir('/dev/pts');
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'ptmx') {
                    continue;
                }
                $mtime = @filemtime('/dev/pts/' . $entry);
                if ($mtime !== false && ($newest === null || $mtime > $newest)) {
                    $newest = $mtime;
                }
            }
        }
        return ['seconds_since_write' => $newest === null ? null : max(0, $now - $newest)];
    }

    /** @return array<string, mixed> */
    public function audioPlayback(): array
    {
        $status = $this->session->mprisStatus();
        return ['playing' => $status === null ? null : ($status === 'Playing'), 'status' => $status];
    }

    /**
     * Which applications currently hold a microphone capture stream.
     *
     * NOT WIRED INTO sampleAll(). `wpctl status` costs about six seconds from a
     * child process, which blows the probe budget for every other signal. This
     * needs a cheaper route to the same fact before it can be sampled
     * continuously; the parsing below is correct, the transport is not.
     *
     * Reports names and a count and draws no conclusion from them. Whether a
     * particular application means a meeting is in progress is a judgement, and
     * judgements belong in sense configuration rather than here.
     *
     * @return array<string, mixed>
     */
    public function audioCapture(): array
    {
        // wpctl answers in tens of milliseconds; a full pw-dump is megabytes and
        // blows the probe budget for a signal this small.
        $uid = function_exists('posix_getuid') ? posix_getuid() : 1000;
        $status = $this->run(
            ['/usr/bin/wpctl', 'status'],
            ['XDG_RUNTIME_DIR' => '/run/user/' . $uid]
        );
        if ($status === null) {
            return ['capturing_apps' => null, 'capture_count' => null];
        }

        // An application is capturing only when one of its child links is a
        // capture port. Matching stream rows alone also picks up sinks,
        // cameras, and devices, which are not microphone users.
        $apps = [];
        $currentApp = null;
        foreach (preg_split('/\R/', $status) ?: [] as $line) {
            $plain = preg_replace('/\e\[[0-9;]*m/', '', $line) ?? $line;
            if (preg_match('/^[^0-9]*(\d+)\.\s+(\S[^\t]*?)\s*$/', $plain, $matches) === 1) {
                $candidate = trim($matches[2]);
                // Port rows share the numbering shape as application rows.
                if (preg_match('/^(input|output|monitor|playback|capture)_/', $candidate) === 1) {
                    continue;
                }
                $currentApp = $candidate;
                continue;
            }
            if ($currentApp !== null && str_contains($plain, 'capture_')) {
                $apps[$currentApp] = true;
            }
        }
        ksort($apps);
        $names = array_keys($apps);
        return [
            'capturing_apps' => implode(',', $names),
            'capture_count' => count($names),
        ];
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    private function run(array $command, array $environment): ?string
    {
        if (!is_executable($command[0])) {
            return null;
        }
        $pipes = [];
        $current = getenv();
        $process = @proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(is_array($current) ? $current : [], $environment)
        );
        if (!is_resource($process)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 4;
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            // Drain stderr too. Leaving it unread lets the child block on a
            // full pipe, which looks exactly like a slow command and cost this
            // probe four seconds on every call.
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                break;
            }
            usleep(20000);
        }
        $output .= (string) stream_get_contents($pipes[1]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exit = proc_close($process);
        return $exit === 0 ? $output : null;
    }

    /** @return array<string, mixed> */
    public function heardSpeechAge(int $now): array
    {
        try {
            foreach (SenseEvent::getAllByWhere(
                ['sense_key' => 'heard_speech'],
                ['order' => ['id' => 'DESC'], 'limit' => 1]
            ) as $event) {
                $at = is_numeric($event->observed_at)
                    ? (int) $event->observed_at
                    : strtotime((string) $event->observed_at);
                return ['seconds_since_speech' => $at === false ? null : max(0, $now - $at)];
            }
        } catch (\Throwable) {
            return ['seconds_since_speech' => null];
        }
        return ['seconds_since_speech' => null];
    }

    private function newestMtimeAge(array $roots, int $now, int $maxDepth): ?int
    {
        $newest = null;
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach ($this->walk($root, $maxDepth) as $path) {
                $mtime = @filemtime($path);
                if ($mtime !== false && ($newest === null || $mtime > $newest)) {
                    $newest = $mtime;
                }
            }
        }
        return $newest === null ? null : max(0, $now - $newest);
    }

    /** @return list<string> */
    private function walk(string $root, int $maxDepth, int $depth = 0): array
    {
        if ($depth > $maxDepth) {
            return [];
        }
        $entries = @scandir($root);
        if ($entries === false) {
            return [];
        }
        $found = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . '/' . $entry;
            if (is_dir($path)) {
                foreach ($this->walk($path, $maxDepth, $depth + 1) as $nested) {
                    $found[] = $nested;
                }
                continue;
            }
            $found[] = $path;
        }
        return $found;
    }

    private function fileAge(array $paths, int $now): ?int
    {
        $newest = null;
        foreach ($paths as $path) {
            $mtime = @filemtime($path);
            if ($mtime !== false && ($newest === null || $mtime > $newest)) {
                $newest = $mtime;
            }
        }
        return $newest === null ? null : max(0, $now - $newest);
    }
}
