<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

/**
 * Instruments on the desktop session.
 *
 * This reads two things and interprets neither: whether the screen locker
 * reports itself active, and what a media player reports it is doing. What
 * either implies is not decided here.
 *
 * This machine runs OpenRC rather than systemd, so there is no logind session
 * to query and no predictable `/run/user/UID/bus`. The session bus address is
 * therefore recovered from a running desktop process when the environment does
 * not carry it, which is what lets these instruments be read from a supervised
 * daemon that inherits no desktop environment.
 */
final class SessionState
{
    private const PROBE_TIMEOUT_SECONDS = 3;

    /** @var list<string> Desktop processes that reliably hold the session bus. */
    private const SESSION_PROCESSES = ['plasmashell', 'kwin_wayland', 'plasma_session', 'ksmserver'];

    private ?string $busAddress = null;
    private bool $busResolved = false;

    /** Current MPRIS playback status, or null when nothing answers. */
    public function mprisStatus(): ?string
    {
        $address = $this->sessionBusAddress();
        if ($address === null) {
            return null;
        }
        $names = $this->run([
            '/usr/bin/dbus-send', '--session', '--print-reply', '--reply-timeout=1500',
            '--dest=org.freedesktop.DBus', '/org/freedesktop/DBus',
            'org.freedesktop.DBus.ListNames',
        ], ['DBUS_SESSION_BUS_ADDRESS' => $address]);
        if ($names === null) {
            return null;
        }
        if (preg_match('/"(org\\.mpris\\.MediaPlayer2\\.[^"]+)"/', $names, $matches) !== 1) {
            return null;
        }
        $reply = $this->run([
            '/usr/bin/dbus-send', '--session', '--print-reply', '--reply-timeout=1500',
            '--dest=' . $matches[1], '/org/mpris/MediaPlayer2',
            'org.freedesktop.DBus.Properties.Get',
            'string:org.mpris.MediaPlayer2.Player', 'string:PlaybackStatus',
        ], ['DBUS_SESSION_BUS_ADDRESS' => $address]);
        if ($reply === null) {
            return null;
        }
        return preg_match('/string\\s+"([^"]+)"/', $reply, $status) === 1 ? $status[1] : null;
    }

    /** Null when the interface cannot be reached at all. */
    public function screenLocked(): ?bool
    {
        $address = $this->sessionBusAddress();
        if ($address === null) {
            return null;
        }

        foreach (['org.freedesktop.ScreenSaver', 'org.kde.screensaver'] as $destination) {
            $result = $this->run([
                '/usr/bin/dbus-send',
                '--session',
                '--print-reply',
                '--reply-timeout=2000',
                '--dest=' . $destination,
                '/ScreenSaver',
                'org.freedesktop.ScreenSaver.GetActive',
            ], ['DBUS_SESSION_BUS_ADDRESS' => $address]);

            if ($result === null) {
                continue;
            }
            if (preg_match('/boolean\s+(true|false)/', $result, $matches) === 1) {
                return $matches[1] === 'true';
            }
        }
        return null;
    }

    /**
     * Find the session bus. A supervised daemon inherits no desktop
     * environment, so falling back to a live desktop process's environ is what
     * makes this readable outside an interactive shell.
     */
    private function sessionBusAddress(): ?string
    {
        if ($this->busResolved) {
            return $this->busAddress;
        }
        $this->busResolved = true;

        $fromEnv = getenv('DBUS_SESSION_BUS_ADDRESS');
        if (is_string($fromEnv) && $fromEnv !== '' && $this->addressUsable($fromEnv)) {
            return $this->busAddress = $fromEnv;
        }

        $uid = function_exists('posix_getuid') ? posix_getuid() : 1000;
        $xdgDefault = sprintf('unix:path=/run/user/%d/bus', $uid);
        if (is_readable(sprintf('/run/user/%d/bus', $uid))) {
            return $this->busAddress = $xdgDefault;
        }

        foreach ($this->sessionProcessIds() as $pid) {
            $environ = @file_get_contents('/proc/' . $pid . '/environ');
            if (!is_string($environ) || $environ === '') {
                continue;
            }
            foreach (explode("\0", $environ) as $pair) {
                if (str_starts_with($pair, 'DBUS_SESSION_BUS_ADDRESS=')) {
                    $candidate = substr($pair, strlen('DBUS_SESSION_BUS_ADDRESS='));
                    if ($candidate !== '' && $this->addressUsable($candidate)) {
                        return $this->busAddress = $candidate;
                    }
                }
            }
        }
        return $this->busAddress = null;
    }

    private function addressUsable(string $address): bool
    {
        if (preg_match('/unix:path=([^,]+)/', $address, $matches) === 1) {
            return file_exists($matches[1]);
        }
        return true;
    }

    /** @return list<int> */
    private function sessionProcessIds(): array
    {
        $pids = [];
        foreach (self::SESSION_PROCESSES as $name) {
            $output = $this->run(['/usr/bin/pgrep', '-u', (string) (function_exists('posix_getuid') ? posix_getuid() : 1000), '-x', $name], []);
            if ($output === null) {
                continue;
            }
            foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
                if (is_numeric(trim($line))) {
                    $pids[] = (int) trim($line);
                }
            }
        }
        return $pids;
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
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $current = getenv();
        $process = @proc_open(
            $command,
            $descriptors,
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
        $deadline = microtime(true) + self::PROBE_TIMEOUT_SECONDS;
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
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
}
