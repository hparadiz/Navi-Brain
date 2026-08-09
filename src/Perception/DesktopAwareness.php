<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use RuntimeException;

final class DesktopAwareness
{
    private const ACTIVE_WITHIN_SECONDS = 120;
    private const CAPTURE_TIMEOUT_MS = 3000;
    private const CURIOSITY_COOLDOWN_SECONDS = 900;

    private ?int $lastCuriosityCaptureAt = null;
    private string $presenceStatePath;
    private LocalVisualObserver $visualObserver;
    /** @var resource|null */
    private $presenceMonitor = null;

    public function __construct()
    {
        $runtimeDirectory = getenv('XDG_RUNTIME_DIR');
        if (!is_string($runtimeDirectory) || trim($runtimeDirectory) === '') {
            $runtimeDirectory = sys_get_temp_dir();
        }
        $this->presenceStatePath = rtrim($runtimeDirectory, DIRECTORY_SEPARATOR)
            . '/navi-brain-presence-' . posix_geteuid() . '.json';
        $this->visualObserver = new LocalVisualObserver();
        $this->startPresenceMonitor();
    }

    public function __destruct()
    {
        if (is_resource($this->presenceMonitor)) {
            proc_terminate($this->presenceMonitor);
            proc_close($this->presenceMonitor);
        }
    }

    /** @return array<string, int|float|string|bool|null> */
    public function presence(): array
    {
        $startedAt = hrtime(true);
        $raw = is_file($this->presenceStatePath)
            ? file_get_contents($this->presenceStatePath)
            : false;
        $state = is_string($raw) ? json_decode($raw, true) : null;
        $probeMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        if (!is_array($state) || !in_array($state['state'] ?? null, ['active', 'away', 'unknown'], true)) {
            $this->restartPresenceMonitor();
            return [
                'using_computer' => null,
                'state' => 'unavailable',
                'active_within_seconds' => self::ACTIVE_WITHIN_SECONDS,
                'probe_ms' => $probeMs,
                'source' => 'KIdleTime Wayland idle notifications',
            ];
        }

        $monitorPid = is_int($state['monitor_pid'] ?? null) ? $state['monitor_pid'] : 0;
        if ($monitorPid < 1 || !is_dir('/proc/' . $monitorPid)) {
            $this->restartPresenceMonitor();
            return [
                'using_computer' => null,
                'state' => 'unavailable',
                'active_within_seconds' => self::ACTIVE_WITHIN_SECONDS,
                'probe_ms' => $probeMs,
                'source' => 'KIdleTime Wayland idle notifications',
            ];
        }

        return [
            'using_computer' => $state['state'] === 'unknown'
                ? null
                : (bool) $state['using_computer'],
            'state' => (string) $state['state'],
            'active_within_seconds' => (int) $state['active_within_seconds'],
            'observed_at_ms' => (int) $state['observed_at_ms'],
            'observation_age_ms' => max(0, (int) floor(microtime(true) * 1000) - (int) $state['observed_at_ms']),
            'probe_ms' => $probeMs,
            'source' => (string) $state['source'],
        ];
    }

    /** @return array{metadata: array<string, mixed>, image_base64: ?string} */
    public function look(string $reason, string $purpose, string $scope): array
    {
        $presence = null;
        if ($purpose === 'curiosity') {
            $presence = $this->presence();
            if ($presence['using_computer'] !== true) {
                throw new RuntimeException('Curiosity capture skipped because the user is not actively using the computer.');
            }

            $now = time();
            if (
                $this->lastCuriosityCaptureAt !== null
                && $now - $this->lastCuriosityCaptureAt < self::CURIOSITY_COOLDOWN_SECONDS
            ) {
                $remaining = self::CURIOSITY_COOLDOWN_SECONDS - ($now - $this->lastCuriosityCaptureAt);
                throw new RuntimeException(sprintf(
                    'Curiosity capture skipped; the performance cooldown has %d seconds remaining.',
                    $remaining
                ));
            }
        }

        $path = sprintf(
            '%s/navi-brain-look-%d-%s.png',
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR),
            getmypid(),
            bin2hex(random_bytes(6))
        );
        $scopeFlag = match ($scope) {
            'active_window' => '--activewindow',
            'current_screen' => '--current',
            'desktop' => '--fullscreen',
            default => throw new RuntimeException('Unsupported desktop capture scope.'),
        };

        $startedAt = hrtime(true);
        try {
            $previousUmask = umask(0077);
            try {
                $result = $this->run([
                    '/usr/bin/spectacle',
                    $scopeFlag,
                    '--background',
                    '--nonotify',
                    '--output',
                    $path,
                ], self::CAPTURE_TIMEOUT_MS);
            } finally {
                umask($previousUmask);
            }
            $captureMs = (hrtime(true) - $startedAt) / 1_000_000;

            if ($result['exit_code'] !== 0 || !is_file($path)) {
                throw new RuntimeException('Desktop capture failed: ' . $this->commandError($result));
            }

            $image = file_get_contents($path);
            $dimensions = getimagesize($path);
            if ($image === false || $dimensions === false) {
                throw new RuntimeException('Desktop capture did not produce a readable image.');
            }

            if ($purpose === 'curiosity') {
                $this->lastCuriosityCaptureAt = time();
            }

            $localObservation = $this->visualObserver->observe($path, $reason);
            $returnRawImage = $purpose === 'user_request'
                && ($localObservation['raw_image_safe'] ?? false) === true;

            return [
                'metadata' => [
                    'reason' => $reason,
                    'purpose' => $purpose,
                    'scope' => $scope,
                    'width' => $dimensions[0],
                    'height' => $dimensions[1],
                    'bytes' => strlen($image),
                    'capture_ms' => round($captureMs, 2),
                    'presence' => $presence,
                    'backend' => 'KDE Spectacle',
                    'stored' => false,
                    'raw_image_returned' => $returnRawImage,
                    'local_observation' => $localObservation,
                    'policy' => [
                        'background_polling' => false,
                        'capture_timeout_ms' => self::CAPTURE_TIMEOUT_MS,
                        'curiosity_cooldown_seconds' => self::CURIOSITY_COOLDOWN_SECONDS,
                    ],
                ],
                'image_base64' => $returnRawImage ? base64_encode($image) : null,
            ];
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function idleHelperPath(): string
    {
        $configured = getenv('NAVI_BRAIN_IDLE_HELPER');
        $path = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(__DIR__, 2) . '/build/navi-brain-idle';

        if (!is_executable($path)) {
            throw new RuntimeException('Desktop idle helper is unavailable; run `make desktop-awareness`.');
        }

        return $path;
    }

    private function startPresenceMonitor(): void
    {
        try {
            $helper = $this->idleHelperPath();
        } catch (RuntimeException) {
            return;
        }

        $this->presenceMonitor = proc_open(
            [
                $helper,
                '--monitor',
                $this->presenceStatePath,
                (string) self::ACTIVE_WITHIN_SECONDS,
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ],
            $pipes,
            options: ['bypass_shell' => true]
        );
        if (!is_resource($this->presenceMonitor)) {
            $this->presenceMonitor = null;
        }
    }

    private function restartPresenceMonitor(): void
    {
        if (is_resource($this->presenceMonitor)) {
            proc_terminate($this->presenceMonitor);
            proc_close($this->presenceMonitor);
            $this->presenceMonitor = null;
        }
        $this->startPresenceMonitor();
    }

    /** @param list<string> $command
     *  @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function run(array $command, int $timeoutMs): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            options: ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start desktop awareness helper.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = hrtime(true);
        $exitCode = -1;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if ((hrtime(true) - $startedAt) / 1_000_000 >= $timeoutMs) {
                proc_terminate($process);
                usleep(20_000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                $stderr .= sprintf('Timed out after %d ms.', $timeoutMs);
                break;
            }

            usleep(5_000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        if ($exitCode < 0 && $closedExitCode >= 0) {
            $exitCode = $closedExitCode;
        }

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param array{exit_code: int, stdout: string, stderr: string} $result */
    private function commandError(array $result): string
    {
        $message = trim($result['stderr']);
        if ($message === '') {
            $message = trim($result['stdout']);
        }
        return $message !== '' ? $message : 'exit code ' . $result['exit_code'];
    }
}
