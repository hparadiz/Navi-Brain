<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use Throwable;

/**
 * Drives bounded work items through the local llama.cpp server.
 *
 * This is the brain's baseline cognition: it has no rate limit, no network
 * dependency, and no external account, so the thread machinery keeps advancing
 * when the free-model pool is throttled or offline. Remote free models remain
 * an optional upgrade rather than a requirement for staying alive.
 */
final class LocalModelWorker
{
    public const MODEL_ID = 'local/gemma-3-4b-it-qat-Q4_0';

    private const WORK_LEASE_SECONDS = 360;
    private const MAX_TOKENS = 512;

    private LocalModelClient $client;

    public function __construct(
        private readonly ExecutiveCore $core,
        ?LocalModelClient $client = null
    ) {
        $this->client = $client ?? new LocalModelClient();
    }

    public function isHealthy(): bool
    {
        return $this->client->isHealthy();
    }

    /** @return array<string, mixed> */
    public function runOnce(string $owner): array
    {
        // Respect the backoff before anything else, and do so without claiming.
        // A claim here would lease a work item to a worker that is standing
        // down, so the item would burn its attempts waiting on a model that is
        // deliberately not being called. Leaving it queued means it simply runs
        // when the model is worth calling again.
        $cooldown = $this->core->localModelCooldownRemaining(self::MODEL_ID);
        if ($cooldown !== null) {
            return [
                'status' => 'local_model_cooling_down',
                'seconds_remaining' => $cooldown,
                'endpoint' => $this->client->endpoint(),
            ];
        }

        // Probe before claiming: a work item must never be leased to a worker
        // that already knows it cannot run it.
        if (!$this->client->isHealthy()) {
            $this->core->recordLocalModelUnavailable(
                self::MODEL_ID,
                'Local model endpoint ' . $this->client->endpoint() . ' did not answer its health probe.'
            );
            return ['status' => 'local_model_unavailable', 'endpoint' => $this->client->endpoint()];
        }

        $claimed = $this->core->claimWork($owner, self::WORK_LEASE_SECONDS);
        if ($claimed === null) {
            return ['status' => 'idle'];
        }
        $work = $claimed['work_item'];
        $wall = max(5, min(600, (int) $work['wall_budget_seconds']));
        $maxTokens = max(64, min(self::MAX_TOKENS, (int) $work['token_budget']));
        $started = hrtime(true);

        try {
            $invocation = $this->client->complete(
                (string) $work['prompt'],
                $this->proposalSchema($work),
                $maxTokens,
                $wall
            );
            // Finish while the lease is still inside the failure boundary.
            // A syntactically valid JSON object can still violate the
            // executive's exact proposal envelope; that must fail the work
            // item immediately instead of stranding it until lease expiry.
            $finished = $this->core->finishWork(
                workId: (int) $work['id'],
                owner: $owner,
                fencingToken: (int) $work['fencing_token'],
                succeeded: true,
                result: $invocation['proposal'],
                model: self::MODEL_ID
            );
        } catch (Throwable $throwable) {
            $latency = (int) round((hrtime(true) - $started) / 1_000_000);
            $this->core->recordModelResult(self::MODEL_ID, false, $latency, $throwable->getMessage());
            try {
                $failed = $this->core->finishWork(
                    workId: (int) $work['id'],
                    owner: $owner,
                    fencingToken: (int) $work['fencing_token'],
                    succeeded: false,
                    result: [],
                    model: null,
                    error: 'Local model invocation failed: ' . $throwable->getMessage()
                );
            } catch (Throwable $finishFailure) {
                return [
                    'status' => 'lease_lost',
                    'model' => self::MODEL_ID,
                    'error' => $throwable->getMessage(),
                    'finish_error' => $finishFailure->getMessage(),
                ];
            }
            return [
                'status' => 'failed',
                'model' => self::MODEL_ID,
                'error' => $throwable->getMessage(),
                'result' => $failed,
                'integration' => $this->core->handleWorkerFailure(
                    $work,
                    'Local model invocation failed: ' . $throwable->getMessage()
                ),
            ];
        }

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $this->core->recordModelResult(self::MODEL_ID, true, $latency);

        try {
            $integration = $this->core->integrateWorkerResult(
                $work,
                $invocation['proposal'],
                self::MODEL_ID
            );
        } catch (Throwable $throwable) {
            $integration = $this->core->handleWorkerFailure(
                $work,
                'Local worker completed, but thread integration failed: ' . $throwable->getMessage()
            );
        }

        return [
            'status' => 'completed',
            'model' => self::MODEL_ID,
            'latency_ms' => $latency,
            'usage' => $invocation['usage'],
            'result' => $finished,
            'integration' => $integration,
        ];
    }

    /**
     * Build the decode-time constraint for this work item.
     *
     * Constraining `kind` to the operation the executive actually asked for
     * makes the curator's kind check structurally unfailable, which is the main
     * practical advantage of a local model over the remote free pool.
     *
     * @param array<string, mixed> $work
     * @return array<string, mixed>
     */
    private function proposalSchema(array $work): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $kind = ['type' => 'string', 'minLength' => 1, 'maxLength' => 96];

        $operation = $refs['operation'] ?? null;
        if (is_string($operation) && $operation !== '') {
            $kind['enum'] = [$operation];
        } elseif (($work['work_type'] ?? null) === ExecutiveCore::SELF_PRESENCE_ANSWER_WORK_TYPE) {
            $kind['enum'] = ['self_presence_utterance'];
        } elseif (($work['work_type'] ?? null) === ExecutiveCore::SELF_PRESENCE_SPEECH_WORK_TYPE) {
            $kind['enum'] = ['self_presence_utterance', 'remain_silent'];
        }

        return [
            'type' => 'object',
            'properties' => [
                'kind' => $kind,
                // Do not express the output budget as a large JSON-Schema
                // maxLength. llama.cpp lowers that to a bounded grammar
                // repetition and rejects large bounds before decoding starts.
                // max_tokens and the curator already provide the real limit.
                'content' => ['type' => 'string', 'minLength' => 1],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'challenged_assumption' => ['type' => 'string', 'minLength' => 1],
            ],
            'required' => ['kind', 'content', 'confidence', 'challenged_assumption'],
            'additionalProperties' => false,
        ];
    }
}
