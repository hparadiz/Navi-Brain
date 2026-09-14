<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Core\ExecutiveCore\Executive;

use InvalidArgumentException;

class DreamCycle
{
    private readonly Executive $core;
    private readonly NativeMaintenance $native;
    private readonly DreamModelWorker $dream;
    private readonly int $maxPrompts;
    private readonly int $intervalSeconds;
    private int $nextCheckAt = 0;

    /** @param array<string, mixed> $dreamConfig */
    public function __construct(Executive $core, array $dreamConfig = [], array $nativeConfig = [])
    {
        $this->core = $core;
        $maximum = $dreamConfig['max_prompts_per_cycle'] ?? 1;
        $interval = $dreamConfig['interval_seconds'] ?? 5400;
        if (!is_int($maximum) || $maximum < 1 || $maximum > 2) {
            throw new InvalidArgumentException('Dream prompt cap must be 1 or 2.');
        }
        if (!is_int($interval) || $interval < 5400 || $interval > 86400) {
            throw new InvalidArgumentException('Dream interval must be between 5400 and 86400 seconds.');
        }
        $this->maxPrompts = $maximum;
        $this->intervalSeconds = $interval;
        $this->native = new NativeMaintenance($core, $nativeConfig);
        $this->dream = new DreamModelWorker($core, $dreamConfig);
    }

    /** @return array<string, mixed> */
    public function runOnce(string $owner): array
    {
        $native = $this->native->runOnce($owner);
        $attempts = [];

        if (time() >= $this->nextCheckAt) {

            $this->nextCheckAt = time() + 60;
            $first = $this->dream->runOnce($owner);
            $attempts[] = $first;
            $this->nextCheckAt = time() + (($first['status'] ?? null) === 'completed'
                ? $this->intervalSeconds : 300);
            if ($this->maxPrompts === 2
                && ($first['status'] ?? null) === 'completed'
                && ($first['integration']['accepted_batch'] ?? false) === true
                && is_string($first['window_id'] ?? null) && $first['window_id'] !== ''
                && ($this->core->cognitionControl()['paused'] ?? false) !== true) {

                $attempts[] = $this->dream->runOnce($owner, $first['window_id']);
                $this->nextCheckAt = time() + $this->intervalSeconds;
            }
        }
        $failed = ($native['status'] ?? null) === 'failed';
        $completed = ($native['status'] ?? null) === 'completed';
        foreach ($attempts as $attempt) {
            $failed = $failed || in_array($attempt['status'] ?? null, ['failed', 'error', 'integration_pending'], true);
            $completed = $completed || ($attempt['status'] ?? null) === 'completed';
        }
        return ['status' => $failed ? 'failed' : ($completed ? 'completed' : 'idle'),
            'native' => $native,

            'dream_attempts' => $attempts,
            'next_check_at' => $this->nextCheckAt];
    }
}
