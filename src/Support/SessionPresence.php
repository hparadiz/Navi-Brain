<?php

declare(strict_types=1);

namespace NaviBrain\Support;

use Throwable;

class SessionPresence
{
    /** @var resource|null */
    private mixed $handle;
    private ?string $path;
    private bool $closed = false;

    private function __construct(private readonly ActivityBus $activityBus, private readonly string $flow, private readonly string $client, mixed $handle, string $path) {
        $this->handle = $handle;
        $this->path = $path;
    }

    /** @param array<string, mixed> $initializeParams */
    public static function open(ActivityBus $activityBus, array $initializeParams): ?self
    {
        try {
            $clientInfo = is_array($initializeParams['clientInfo'] ?? null)
                ? $initializeParams['clientInfo']
                : [];
            $client = self::token(is_string($clientInfo['name'] ?? null) ? $clientInfo['name'] : 'tui', 'tui');
            $version = self::token(is_string($clientInfo['version'] ?? null) ? $clientInfo['version'] : 'unknown', 'unknown');
            $pid = getmypid();
            $pid = is_int($pid) ? $pid : 0;
            $nonce = bin2hex(random_bytes(6));
            $flow = 'tui-' . $pid . '-' . $nonce;
            $directory = ActivityBus::runtimeDirectory();
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                return null;
            }
            @chmod($directory, 0700);
            $path = $directory . '/session-' . $flow . '.presence';
            $handle = @fopen($path, 'x+b');
            if (!is_resource($handle)) {
                return null;
            }
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                @unlink($path);
                return null;
            }
            @chmod($path, 0600);
            $packet = implode("\n", [
                'version 1',
                'flow ' . $flow,
                'client ' . $client,
                'client_version ' . $version,
                'pid ' . (string) $pid,
                'connected_at_ms ' . (string) ((int) floor(microtime(true) * 1000)),
                '',
            ]);
            fwrite($handle, $packet);
            fflush($handle);

            $presence = new self($activityBus, $flow, $client, $handle, $path);
            $activity = new \NaviBrain\Support\Activity();
            $activity->source = 'tui';
            $activity->phase = 'started';
            $activity->operation = 'session.' . $client;
            $activity->domain = 'continuity';
            $activity->outcome = 'connected';
            $activity->flow = $flow;
            $activityBus->publish($activity);
            return $presence;
        } catch (Throwable) {
            return null;
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $activity = new \NaviBrain\Support\Activity();
        $activity->source = 'tui';
        $activity->phase = 'finished';
        $activity->operation = 'session.' . $this->client;
        $activity->domain = 'continuity';
        $activity->outcome = 'disconnected';
        $activity->flow = $this->flow;
        $this->activityBus->publish($activity);
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
        if ($this->path !== null) {
            @unlink($this->path);
        }
        $this->path = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function token(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        $value = trim($value, '-_.');
        return substr($value === '' ? $fallback : $value, 0, 64);
    }
}
