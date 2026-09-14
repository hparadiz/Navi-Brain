<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use InvalidArgumentException;

/**
 * Local upkeep only. The worker owns cadence; this object neither claims model
 * work nor schedules cognition. Explicit pause still permits durable recovery.
 */
final class NativeMaintenance
{
    private readonly ExecutiveCore $core;
    private readonly int $slotLimit;
    private readonly int $recoveryLimit;
    private int $nextConsolidationAt = 0;

    /** @param array<string, mixed> $config */
    public function __construct(ExecutiveCore $core, array $config = [])
    {
        $this->core = $core;
        $this->slotLimit = $this->limit($config, 'slot_limit', 32);
        $this->recoveryLimit = $this->limit($config, 'recovery_limit', 16);
    }

    /** @return array<string, mixed> */
    public function runOnce(string $owner): array
    {
        if (trim($owner) === '') {
            throw new InvalidArgumentException('Native maintenance owner must not be empty.');
        }
        $recovery = $this->core->recoverDecisionIntegrations($this->recoveryLimit);
        $actions = $this->core->decisionStateMachine()->reconcileUnattemptedActions($this->recoveryLimit);
        $workspace = $this->core->workingMemory()->maintainBatch($this->slotLimit);
        $consolidation = null;
        if (time() >= $this->nextConsolidationAt) {
            $consolidation = $this->core->reuseConsolidationEvidence(true);
            $this->nextConsolidationAt = time() + 5400;
        }
        $blocked = count(array_filter(array_merge($recovery, $actions),
            static fn (array $row): bool => ($row['status'] ?? null) === 'recovery_blocked'));
        return [
            // A visited unchanged row can repair a native projection, so do not
            // claim an idle/no-mutation pass just from canonical change counts.
            'status' => $workspace['errors'] !== [] || $blocked > 0 ? 'failed'
                : ($workspace['visited'] === 0 && $recovery === [] && $actions === []
                    && ($consolidation['reused'] ?? 0) === 0 ? 'idle' : 'completed'),
            'owner' => $owner,
            'recovered_decisions' => $recovery,
            'recovered_unattempted_actions' => $actions,
            'workspace' => $workspace,
            'consolidation' => $consolidation,
        ];
    }

    /** @param array<string, mixed> $config */
    private function limit(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (!is_int($value) || $value < 1 || $value > 128) {
            throw new InvalidArgumentException($key . ' must be an integer between 1 and 128.');
        }
        return $value;
    }
}
