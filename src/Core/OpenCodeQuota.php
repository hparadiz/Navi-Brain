<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use RuntimeException;

/** One local provider slot and a persistent shared cooldown, never IP rotation. */
final class OpenCodeQuota
{
    private string $path;
    private mixed $lock = null;
    private array $state = [];

    public function __construct(string $runtimeRoot)
    {
        $this->path = $runtimeRoot . '/provider-quota.json';
    }

    public function acquire(): bool
    {
        $this->lock = fopen($this->path . '.lock', 'c');
        if ($this->lock === false) {
            throw new RuntimeException('Unable to open shared OpenCode quota lock.');
        }
        if (!flock($this->lock, LOCK_EX | LOCK_NB)) {
            $this->release();
            return false;
        }
        if (is_file($this->path)) {
            $state = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($state) || !is_int($state['retry_at'] ?? null)
                || !is_int($state['failures'] ?? null)) {
                throw new RuntimeException('Invalid OpenCode quota state; dispatch is disabled until repaired.');
            }
            $this->state = $state;
        }
        return true;
    }

    public function retryAt(): int
    {
        return (int) ($this->state['retry_at'] ?? 0);
    }

    public function rateLimited(int $retryAt): int
    {
        $failures = (int) ($this->state['failures'] ?? 0) + 1;
        $retryAt = max($retryAt, time() + min(3600, 30 * (2 ** min($failures, 7))));
        $this->save(['retry_at' => $retryAt, 'failures' => $failures]);
        return $retryAt;
    }

    public function succeeded(): void
    {
        if ($this->state !== []) {
            $this->save(['retry_at' => 0, 'failures' => 0]);
        }
    }

    private function save(array $state): void
    {
        $temporary = tempnam(dirname($this->path), '.quota-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to persist OpenCode provider cooldown.');
        }
        try {
            $body = json_encode($state, JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $body, LOCK_EX) !== strlen($body)
                || !chmod($temporary, 0600) || !rename($temporary, $this->path)) {
                throw new RuntimeException('Unable to persist OpenCode provider cooldown.');
            }
            $this->state = $state;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function release(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
    }
}
