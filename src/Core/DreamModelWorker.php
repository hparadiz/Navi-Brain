<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Core\ExecutiveCore\Executive;

use InvalidArgumentException;
use NaviBrain\Model\WorkItem;
use RuntimeException;
use Throwable;

class DreamModelWorker
{
    private readonly Executive $core;
    private readonly array $config;
    private readonly string $runtimeRoot;

    public function __construct(Executive $core, array $config = [])
    {
        $this->core = $core;
        $this->config = array_replace([
            'enabled' => false,
            'allow_private_memory' => false,
            'model' => 'muse-spark-1.3-contributor-free',
            'interval_seconds' => 5400,
            'max_prompts_per_cycle' => 1,
            'source_limit' => 32,
        ], $config);
        foreach (['enabled', 'allow_private_memory'] as $key) {
            if (!is_bool($this->config[$key])) {
                throw new InvalidArgumentException('Dream ' . $key . ' must be a boolean.');
            }
        }
        foreach (['interval_seconds' => [5400, 86400], 'max_prompts_per_cycle' => [1, 2],
            'source_limit' => [1, 32]] as $key => [$minimum, $maximum]) {
            if (!is_int($this->config[$key]) || $this->config[$key] < $minimum
                || $this->config[$key] > $maximum) {
                throw new InvalidArgumentException('Dream ' . $key . ' is outside its supported integer bounds.');
            }
        }
        if ($this->config['model'] !== 'muse-spark-1.3-contributor-free') {
            throw new InvalidArgumentException('Dream model must be an explicitly supported OpenCode free model.');
        }
        $this->runtimeRoot = dirname(__DIR__, 2) . '/var/opencode-worker';
    }

    /** @return array<string, mixed> */
    public function runOnce(string $owner, ?string $continuationWindowId = null): array
    {
        if (trim($owner) === '') {
            throw new InvalidArgumentException('Dream worker owner must not be empty.');
        }
        if (!$this->config['enabled']) {
            return ['status' => 'disabled', 'cli_invocations' => 0];
        }
        if (!$this->config['allow_private_memory']) {
            return ['status' => 'privacy_decision_required', 'cli_invocations' => 0];
        }
        if ($this->core->cognitionControl()['paused']) {
            return ['status' => 'paused', 'cli_invocations' => 0];
        }
        if (!is_dir($this->runtimeRoot)
            && !mkdir($this->runtimeRoot, 0700, true) && !is_dir($this->runtimeRoot)) {
            throw new RuntimeException('Unable to create the private OpenCode runtime directory.');
        }
        $quota = new OpenCodeQuota($this->runtimeRoot, $this->config['interval_seconds'], $this->config['max_prompts_per_cycle']);
        try {
            if (!$quota->acquire()) {
                return ['status' => 'provider_busy', 'cli_invocations' => 0];
            }
            $budget = $quota->attemptBudgetStatus($continuationWindowId);
            if (!$budget['available']) {
                return $budget + ['cli_invocations' => 0];
            }
            $prepared = $this->core->prepareDreamConsolidation($this->config['source_limit']);
            if (($prepared['status'] ?? null) !== 'queued') {
                return array_intersect_key($prepared, array_flip(['status', 'work_id'])) + ['cli_invocations' => 0];
            }
            $candidate = $prepared['work_item'] ?? [];
            if (!$this->isDreamWork($candidate)) {
                throw new RuntimeException('Dream preparation returned work outside its evidence-only lane.');
            }
            $client = new OpenCodeDreamClient($this->core, $this->config['model']);

            try {
                $client->preflight();
            } catch (ModelRateLimit $limited) {
                return ['status' => 'provider_cooling_down', 'cli_invocations' => 0,
                    'retry_at' => $quota->rateLimited($limited->retryAt)];
            }
            $claim = new WorkClaim($owner, 180);
            $claim->includedWorkTypes = [Executive::MEMORY_CONSOLIDATION_WORK_TYPE];
            $claim->workId = (int) $candidate['id'];
            $claim->lane = 'opencode-dream';
            $claimed = $this->core->claimWork($claim);
            if ($claimed === null) {
                return ['status' => 'busy', 'cli_invocations' => 0];
            }
            $work = $claimed['work_item'];
            if (!$this->isDreamWork($work)) {

                $this->core->deferWork((int) $work['id'], $owner, (int) $work['fencing_token'], time() + 5400);
                throw new RuntimeException('Claimed dream work changed its evidence-only contract.');
            }
            if ($this->core->cognitionControl()['paused']) {
                $this->core->deferWork((int) $work['id'], $owner, (int) $work['fencing_token'], time() + 30);
                return ['status' => 'paused', 'cli_invocations' => 0];
            }

            $preparedPrompt = is_string($prepared['prepared_prompt'] ?? null)
                && $work['id'] === $candidate['id']
                && ($work['prompt'] ?? null) === ($candidate['prompt'] ?? null)
                && ($work['input_refs'] ?? null) === ($candidate['input_refs'] ?? null)
                ? $prepared['prepared_prompt'] : null;
            unset($prepared, $candidate);
            try {
                $materialized = $this->core->materializeDreamConsolidation($work, $preparedPrompt);
            } catch (Throwable) {

                $this->core->deferWork((int) $work['id'], $owner, (int) $work['fencing_token'], time() + 300);
                return ['status' => 'failed', 'work_id' => (int) $work['id'], 'cli_invocations' => 0,
                    'error' => 'Dream source materialization failed; the exact work is retained for retry.'];
            } finally {
                unset($preparedPrompt);
            }
            if ($materialized['status'] === 'source_changed') {
                return $this->core->cancelChangedDreamConsolidation((int) $work['id'], $owner, (int) $work['fencing_token'], (string) $materialized['reason']) + ['cli_invocations' => 0];
            }
            $prompt = $materialized['prompt'];
            unset($materialized);
            if ($this->core->cognitionControl()['paused']) {
                return ['status' => 'paused', 'work_id' => (int) $work['id'], 'cli_invocations' => 0];
            }
            if (!$this->core->dreamConsolidationLeaseIsCurrent($work, $owner)) {

                return ['status' => 'busy', 'work_id' => (int) $work['id'], 'cli_invocations' => 0,
                    'reason' => 'dream_lease_or_manifest_requires_recovery'];
            }
            $reservation = $quota->reserveAttempt((int) $work['id'], (int) $work['fencing_token'], $this->config['model'], $continuationWindowId);
            return $this->complete($client, $quota, $work, $reservation, $prompt);
        } finally {
            $quota->release();
        }
    }

    private function isDreamWork(array $work): bool
    {
        return is_int($work['id'] ?? null) && $work['id'] > 0
            && ($work['work_type'] ?? null) === Executive::MEMORY_CONSOLIDATION_WORK_TYPE
            && in_array($work['input_refs']['dream_protocol'] ?? null, ['dream-consolidation-v1', Executive::DREAM_REFERENCE_PROTOCOL], true)
            && ($work['parent_run_id'] ?? null) === null
            && ($work['parent_intention_id'] ?? null) === null
            && ($work['allowed_actions'] ?? []) === [];
    }

    /** @return array<string, mixed> */
    private function complete(OpenCodeDreamClient $client, OpenCodeQuota $quota, array $work, array $reservation, string $prompt): array
    {
        $owner = (string) $work['lease_owner'];
        $workId = (int) $work['id'];
        $fence = (int) $work['fencing_token'];
        $base = ['work_id' => $workId, 'window_id' => $reservation['window_id'],
            'next_cycle_at' => $reservation['next_cycle_at'], 'reserved_attempts' => 1];
        if ($this->core->cognitionControl()['paused']) {
            $this->core->deferWork($workId, $owner, $fence, (int) $reservation['next_cycle_at']);
            return $base + ['status' => 'paused', 'cli_invocations' => 0];
        }
        try {
            $response = $client->complete($prompt, min(512, (int) $work['token_budget']));
        } catch (ModelRateLimit $limited) {
            $retryAt = max($quota->rateLimited($limited->retryAt), (int) $reservation['next_cycle_at']);
            $this->core->deferWork($workId, $owner, $fence, $retryAt);
            return $base + ['status' => 'provider_cooling_down', 'cli_invocations' => 1, 'retry_at' => $retryAt];
        } catch (ModelProposalRejected $rejected) {
            return $base + $this->rejectResponse($work, $owner);
        } catch (Throwable $error) {

            $this->core->deferWork($workId, $owner, $fence, (int) $reservation['next_cycle_at']);
            return $base + ['status' => 'failed', 'cli_invocations' => 1, 'error' => $error->getMessage()];
        }
        $model = 'opencode/' . $this->config['model'];
        try {
            $this->core->finishWork(WorkCompletion::success(new WorkItem($work), $response['proposal'], $model));
        } catch (Throwable $error) {
            $current = WorkItem::getByID($workId);
            if ($current instanceof WorkItem && $current->status === 'completed') {

                return $base + ['status' => 'integration_pending', 'cli_invocations' => 1];
            }
            if ($error instanceof InvalidArgumentException) {
                return $base + $this->rejectResponse($work, $owner);
            }

            throw $error;
        }
        try {
            $quota->succeeded();
        } catch (Throwable) {

        }
        try {
            $integration = $this->core->integrateWorkerResult($work, $response['proposal'], $model);
        } catch (Throwable $error) {
            return $base + ['status' => 'integration_pending', 'cli_invocations' => 1,
                'error' => $error->getMessage()];
        }
        return $base + ['status' => 'completed', 'cli_invocations' => 1,
            'model' => $model, 'usage' => $response['usage'] ?? null,
            'latency_ms' => $response['latency_ms'] ?? null,
            'integration' => $this->integrationSummary($integration)];
    }

    private function rejectResponse(array $work, string $owner): array
    {
        $reason = 'Dream response failed the proposal validator.';
        $this->core->finishWork(WorkCompletion::failure(new WorkItem($work), $reason, 'opencode/' . $this->config['model']));
        $integration = $this->core->handleWorkerFailure($work, $reason);
        return ['status' => 'failed', 'cli_invocations' => 1,
            'integration' => $this->integrationSummary($integration)];
    }

    private function integrationSummary(array $integration): array
    {
        $summary = array_intersect_key($integration, array_flip(['status', 'accepted_batch', 'new_assertion']));
        if (isset($integration['memory']['id'])) {
            $summary['memory_id'] = (int) $integration['memory']['id'];
        }
        foreach (['supported_episode_ids', 'rejected_episode_ids'] as $key) {
            if (is_array($integration[$key] ?? null)) {
                $summary[$key] = $integration[$key];
            }
        }
        return $summary;
    }
}
