<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use RuntimeException;

/** One local provider slot, persistent attempt budget and shared cooldown. */
final class OpenCodeQuota
{
    private static ?string $processToken = null;
    private string $path;
    private readonly int $intervalSeconds;
    private readonly int $maxAttempts;
    private mixed $lock = null;
    private array $state = [];

    public function __construct(
        string $runtimeRoot,
        int $intervalSeconds = 5400,
        int $maxAttempts = 1
    ) {
        if ($intervalSeconds < 5400 || $intervalSeconds > 86400 || $maxAttempts < 1 || $maxAttempts > 2) {
            throw new RuntimeException('OpenCode budget requires a 5400–86400 second interval and one or two attempts.');
        }
        $this->intervalSeconds = $intervalSeconds;
        $this->maxAttempts = $maxAttempts;
        self::$processToken ??= bin2hex(random_bytes(16));
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
            $this->state = self::validateState($state);
        }
        return true;
    }

    /**
     * Informational view of one atomically published file, without a provider
     * lock or constructor side effects. Never grants dispatch/continuation.
     * The read is byte-bounded; local filesystem latency is not bounded here.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(string $runtimeRoot): array
    {
        $now = time();
        $unavailable = ['observed_at' => $now, 'new_cycle_due' => null];
        $path = $runtimeRoot . '/provider-quota.json';
        $before = @lstat($path);
        if ($before === false) {
            // PHP cannot distinguish a missing path from a denied traversal
            // here. Neither is proof of an unused provider allowance.
            return $unavailable + ['snapshot' => 'missing_or_unreadable'];
        }
        if (($before['mode'] & 0170000) !== 0100000 || $before['size'] < 0 || $before['size'] > 16384) {
            return $unavailable + ['snapshot' => 'invalid_file'];
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return $unavailable + ['snapshot' => 'unreadable'];
        }
        try {
            $opened = fstat($stream);
            if (!is_array($opened) || ($opened['mode'] & 0170000) !== 0100000
                || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']) {
                return $unavailable + ['snapshot' => 'file_changed'];
            }
            $body = @stream_get_contents($stream, 16385);
        } finally {
            fclose($stream);
        }
        if (!is_string($body)) {
            return $unavailable + ['snapshot' => 'unreadable'];
        }
        if (strlen($body) > 16384) {
            return $unavailable + ['snapshot' => 'invalid_file'];
        }
        try {
            $state = self::validateState(json_decode($body, true, 16, JSON_THROW_ON_ERROR));
        } catch (\JsonException | RuntimeException) {
            return $unavailable + ['snapshot' => 'invalid_state'];
        }
        $budget = $state['attempt_budget'] ?? null;
        $next = is_array($budget) ? $budget['next_cycle_at'] : 0;
        $retryAt = max($next, $state['retry_at']);
        return [
            'snapshot' => 'valid',
            'observed_at' => $now,
            'new_cycle_due' => $retryAt <= $now,
            'retry_at' => $retryAt,
            'cooldown_retry_at' => $state['retry_at'],
            'failures' => $state['failures'],
            'window_active' => is_array($budget) && $now < $next,
            'recorded_attempts' => is_array($budget) ? count($budget['attempts']) : 0,
            'recorded_max_attempts' => is_array($budget) ? $budget['max_attempts'] : null,
            'recorded_interval_seconds' => is_array($budget) ? $budget['interval_seconds'] : null,
            'next_cycle_at' => $next,
        ];
    }

    public function retryAt(): int
    {
        return (int) ($this->state['retry_at'] ?? 0);
    }

    public function rateLimited(int $retryAt): int
    {
        $failures = min(127, (int) ($this->state['failures'] ?? 0)) + 1;
        $retryAt = max($retryAt, time() + min(3600, 30 * (2 ** min($failures, 7))));
        $this->save(array_replace($this->state, ['retry_at' => $retryAt, 'failures' => $failures]));
        return $retryAt;
    }

    public function succeeded(): void
    {
        if ($this->state !== []) {
            $this->save(array_replace($this->state, ['retry_at' => 0, 'failures' => 0]));
        }
    }

    /** Read eligibility without reserving a call or accumulating missed cycles. */
    public function attemptBudgetStatus(?string $continuationWindowId = null): array
    {
        $this->requireLock();
        $now = time();
        $budget = $this->state['attempt_budget'] ?? null;
        $next = is_array($budget) ? $budget['next_cycle_at'] : 0;
        $active = is_array($budget) && $now < $next;
        $status = [
            'available' => false,
            'status' => 'budget_exhausted',
            'window_id' => $active ? $budget['window_id'] : null,
            'attempts' => $active ? count($budget['attempts']) : 0,
            'max_attempts' => $active ? min($this->maxAttempts, $budget['max_attempts']) : $this->maxAttempts,
            'next_cycle_at' => $next,
            'retry_at' => max($next, $this->retryAt()),
        ];
        if ($this->retryAt() > $now) {
            $status['status'] = 'provider_cooling_down';
            return $status;
        }
        if ($continuationWindowId !== null) {
            if (!$active || !hash_equals($budget['window_id'], $continuationWindowId)
                || !hash_equals($budget['process_token'], (string) self::$processToken)
                || $budget['process_id'] !== getmypid()
                || $status['attempts'] !== 1 || $status['max_attempts'] !== 2) {
                $status['status'] = 'invalid_continuation';
                return $status;
            }
        } elseif ($active) {
            return $status;
        }
        $status['available'] = true;
        $status['status'] = 'available';
        return $status;
    }

    /** Persist before dispatch. Failure, interruption and process restart never refund a call. */
    public function reserveAttempt(
        int $workId,
        int $fence,
        string $modelId,
        ?string $continuationWindowId = null
    ): array {
        if ($workId < 1 || $fence < 1 || preg_match('~\A(?:opencode/)?[a-z0-9][a-z0-9._-]{0,120}-free\z~', $modelId) !== 1) {
            throw new RuntimeException('Invalid OpenCode attempt identity.');
        }
        $status = $this->attemptBudgetStatus($continuationWindowId);
        if (!$status['available']) {
            throw new RuntimeException('OpenCode attempt budget does not permit dispatch.');
        }
        $now = time();
        $budget = $continuationWindowId === null ? [
            'window_id' => bin2hex(random_bytes(16)),
            'process_token' => self::$processToken,
            'process_id' => getmypid(),
            'interval_seconds' => $this->intervalSeconds,
            'max_attempts' => $this->maxAttempts,
            'next_cycle_at' => 0,
            'attempts' => [],
        ] : $this->state['attempt_budget'];
        foreach ($budget['attempts'] as $attempt) {
            if ($attempt['work_id'] === $workId) {
                throw new RuntimeException('OpenCode continuation requires a different evidence batch.');
            }
        }
        $budget['attempts'][] = ['at' => $now, 'work_id' => $workId, 'fence' => $fence, 'model' => $modelId];
        // Extend from each attempt, including the optional second. A new cycle
        // cannot burst immediately after a late second call in the old cycle.
        $budget['next_cycle_at'] = max($budget['next_cycle_at'],
            $now + max($this->intervalSeconds, $budget['interval_seconds']));
        $this->save(array_replace($this->state, [
            'retry_at' => $this->retryAt(), 'failures' => (int) ($this->state['failures'] ?? 0),
            'attempt_budget' => $budget,
        ]));
        return ['window_id' => $budget['window_id'], 'attempts' => count($budget['attempts']),
            'next_cycle_at' => $budget['next_cycle_at']];
    }

    private function requireLock(): void
    {
        if (!is_resource($this->lock)) {
            throw new RuntimeException('OpenCode quota must be acquired before use.');
        }
    }

    /** Shared acceptance rules; snapshot adds only its separate read/decode caps. */
    private static function validateState(mixed $state): array
    {
        if (!is_array($state) || !is_int($state['retry_at'] ?? null) || $state['retry_at'] < 0
            || !is_int($state['failures'] ?? null) || $state['failures'] < 0) {
            throw new RuntimeException('Invalid OpenCode quota state; dispatch is disabled until repaired.');
        }
        if (array_key_exists('attempt_budget', $state)) {
            self::validateBudget($state['attempt_budget']);
        }
        return $state;
    }

    private static function validateBudget(mixed $budget): void
    {
        $valid = is_array($budget)
            && is_string($budget['window_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $budget['window_id']) === 1
            && is_string($budget['process_token'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $budget['process_token']) === 1
            && is_int($budget['process_id'] ?? null) && $budget['process_id'] > 0
            && is_int($budget['interval_seconds'] ?? null) && $budget['interval_seconds'] >= 5400 && $budget['interval_seconds'] <= 86400
            && is_int($budget['max_attempts'] ?? null) && in_array($budget['max_attempts'], [1, 2], true)
            && is_int($budget['next_cycle_at'] ?? null) && $budget['next_cycle_at'] >= 0
            && is_array($budget['attempts'] ?? null) && array_is_list($budget['attempts'])
            && count($budget['attempts']) >= 1 && count($budget['attempts']) <= $budget['max_attempts'];
        if (!$valid) {
            throw new RuntimeException('Invalid OpenCode attempt budget; dispatch is disabled until repaired.');
        }
        foreach ($budget['attempts'] as $attempt) {
            if (!is_array($attempt) || !is_int($attempt['at'] ?? null) || $attempt['at'] < 0
                || !is_int($attempt['work_id'] ?? null) || $attempt['work_id'] < 1
                || !is_int($attempt['fence'] ?? null) || $attempt['fence'] < 1
                || !is_string($attempt['model'] ?? null)
                || preg_match('~\A(?:opencode/)?[a-z0-9][a-z0-9._-]{0,120}-free\z~', $attempt['model']) !== 1
                || $attempt['at'] > $budget['next_cycle_at'] - $budget['interval_seconds']) {
                throw new RuntimeException('Invalid OpenCode attempt record; dispatch is disabled until repaired.');
            }
        }
    }

    private function save(array $state): void
    {
        $this->requireLock();
        $temporary = tempnam(dirname($this->path), '.quota-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to persist OpenCode provider cooldown.');
        }
        try {
            $body = json_encode($state, JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $body, LOCK_EX) !== strlen($body) || !chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to persist OpenCode provider cooldown.');
            }
            $stream = fopen($temporary, 'r+b');
            if ($stream === false) {
                throw new RuntimeException('Unable to synchronize OpenCode quota state.');
            }
            try {
                if (!fsync($stream)) {
                    throw new RuntimeException('Unable to synchronize OpenCode quota state.');
                }
            } finally {
                fclose($stream);
            }
            if (!rename($temporary, $this->path)) {
                throw new RuntimeException('Unable to publish OpenCode quota state.');
            }
            // Retain the published state even if directory synchronization
            // fails; later error handling must not erase a reserved attempt.
            $this->state = $state;
            $directory = fopen(dirname($this->path), 'r');
            if ($directory === false) {
                throw new RuntimeException('Unable to synchronize OpenCode quota directory.');
            }
            try {
                if (!fsync($directory)) {
                    throw new RuntimeException('Unable to synchronize OpenCode quota directory.');
                }
            } finally {
                fclose($directory);
            }
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
