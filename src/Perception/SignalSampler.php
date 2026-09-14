<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

class SignalSampler
{
    public function __construct(private readonly SessionState $session = new SessionState(), private readonly ?DesktopAwareness $awareness = null) {
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
