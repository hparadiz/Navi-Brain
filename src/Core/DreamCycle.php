<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use InvalidArgumentException;

/** Local upkeep plus a bounded, opt-in consolidation window. */
final class DreamCycle
{
    private readonly ExecutiveCore $core;
    private readonly NativeMaintenance $native;
    private readonly DreamModelWorker $dream;
    private readonly int $maxPrompts;
    private readonly int $intervalSeconds;
    private int $nextCheckAt = 0;

    /** @param array<string, mixed> $dreamConfig @param array<string, mixed> $nativeConfig */
    public function __construct(ExecutiveCore $core, array $dreamConfig = [], array $nativeConfig = [])
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
        // Reported row failures must not starve unrelated episodic evidence.
        // A thrown maintenance failure still aborts above; dream admission
        // independently checks its own SQL/source state and provider budget.
        if (time() >= $this->nextCheckAt) {
            // Local polling relief only. The worker's persistent reservation is
            // authoritative; process restarts cannot recover spent entitlement.
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
                // Continuation exists only on this live stack after a grounded
                // batch was first accepted. Existing-claim reuse is permitted;
                // it is not represented as a newly created native assertion.
                $attempts[] = $this->dream->runOnce($owner, $first['window_id']);
                $this->nextCheckAt = time() + $this->intervalSeconds;
            }
        }
        $failed = ($native['status'] ?? null) === 'failed';
        $completed = ($native['status'] ?? null) === 'completed';
        foreach ($attempts as $attempt) {
            $failed = $failed || in_array($attempt['status'] ?? null,
                ['failed', 'error', 'integration_pending'], true);
            $completed = $completed || ($attempt['status'] ?? null) === 'completed';
        }
        return ['status' => $failed ? 'failed' : ($completed ? 'completed' : 'idle'),
            'native' => $native,
            // Entries include quiet/held preparations and are not HTTP counts.
            'dream_attempts' => $attempts,
            'next_check_at' => $this->nextCheckAt];
    }
}
