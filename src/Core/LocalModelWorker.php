<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Model\WorkItem;

use NaviBrain\Core\ExecutiveCore\Executive;

use Throwable;

class LocalModelWorker
{
    public const MODEL_ID = 'local/gemma-3-4b-it-qat-Q4_0';

    private const WORK_LEASE_SECONDS = 360;
    private const MAX_TOKENS = 512;

    private LocalModelClient $client;

    public function __construct(private readonly Executive $core, ?LocalModelClient $client = null) {
        $this->client = $client ?? new LocalModelClient();
    }

    public function isHealthy(): bool
    {
        return $this->client->isHealthy();
    }

    /** @return array<string, mixed> */
    public function runOnce(string $owner): array
    {

        $cooldown = $this->core->localModelCooldownRemaining(self::MODEL_ID);
        if ($cooldown !== null) {
            return [
                'status' => 'local_model_cooling_down',
                'seconds_remaining' => $cooldown,
                'endpoint' => $this->client->endpoint(),
            ];
        }

        if (!$this->client->isHealthy()) {
            $this->core->recordLocalModelUnavailable(self::MODEL_ID, 'Local model endpoint ' . $this->client->endpoint() . ' did not answer its health probe.');
            return ['status' => 'local_model_unavailable', 'endpoint' => $this->client->endpoint()];
        }

        $claim = new WorkClaim($owner, self::WORK_LEASE_SECONDS);
        $claim->excludedWorkTypes = [
                NarrativeSynthesis::PERSONALITY_WORK_TYPE,
                NarrativeSynthesis::MOTIVATION_WORK_TYPE,
                NarrativeSynthesis::INTENTION_WORK_TYPE,
            ];
        $claimed = $this->core->claimWork($claim);
        if ($claimed === null) {
            return ['status' => 'idle'];
        }
        $work = $claimed['work_item'];
        $wall = max(5, min(600, (int) $work['wall_budget_seconds']));
        $maxTokens = max(64, min(self::MAX_TOKENS, (int) $work['token_budget']));
        $started = hrtime(true);

        try {
            $invocation = $this->client->complete((string) $work['prompt'], self::proposalSchema($work), $maxTokens, $wall);

            $finished = $this->core->finishWork(WorkCompletion::success(new WorkItem($work), $invocation['proposal'], self::MODEL_ID));
        } catch (Throwable $throwable) {
            $latency = (int) round((hrtime(true) - $started) / 1_000_000);
            $this->core->recordModelResult(self::MODEL_ID, false, $latency, $throwable->getMessage());
            try {
                $failed = $this->core->finishWork(WorkCompletion::failure(new WorkItem($work), 'Local model invocation failed: ' . $throwable->getMessage(), null));
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
                'integration' => $this->core->handleWorkerFailure($work, 'Local model invocation failed: ' . $throwable->getMessage()),
            ];
        }

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $this->core->recordModelResult(self::MODEL_ID, true, $latency);

        try {
            $integration = $this->core->integrateWorkerResult($work, $invocation['proposal'], self::MODEL_ID);
        } catch (Throwable $throwable) {
            $integration = $this->core->handleWorkerFailure($work, 'Local worker completed, but thread integration failed: ' . $throwable->getMessage());
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
     * @param array<string, mixed> $work
     * @return array<string, mixed>
     */
    public static function proposalSchema(array $work): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $kind = ['type' => 'string', 'minLength' => 1, 'maxLength' => 96];

        $operation = $refs['operation'] ?? null;
        if (is_string($operation) && $operation !== '') {
            $kind['enum'] = [$operation];
        } elseif (($work['work_type'] ?? null) === Executive::SELF_PRESENCE_ANSWER_WORK_TYPE) {
            $kind['enum'] = ['self_presence_utterance'];
        } elseif (($work['work_type'] ?? null) === Executive::SELF_PRESENCE_SPEECH_WORK_TYPE) {
            $kind['enum'] = ['self_presence_utterance', 'remain_silent'];
        } elseif (($work['work_type'] ?? null) === Executive::MEMORY_CONSOLIDATION_WORK_TYPE) {
            $kind['enum'] = [Executive::MEMORY_CONSOLIDATION_WORK_TYPE];
        } elseif (NarrativeSynthesis::isWorkType((string) ($work['work_type'] ?? ''))) {
            $kind['enum'] = [NarrativeSynthesis::expectedKind((string) $work['work_type'])];
        }

        if (($work['work_type'] ?? null) === Executive::MEMORY_CONSOLIDATION_WORK_TYPE) {
            return [
                'type' => 'object',
                'properties' => [
                    'kind' => $kind,
                    'content' => ['type' => 'string'],
                    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'challenged_assumption' => ['type' => 'string', 'minLength' => 1],
                    'supported_episode_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer', 'minimum' => 1],
                        'uniqueItems' => true,
                    ],
                    'rejected_episode_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer', 'minimum' => 1],
                        'uniqueItems' => true,
                    ],
                    'rejection_reason' => ['type' => 'string', 'minLength' => 1],
                    'supersedes_memory_id' => [
                        'anyOf' => [
                            ['type' => 'integer', 'minimum' => 1],
                            ['type' => 'null'],
                        ],
                    ],
                ],
                'required' => [
                    'kind',
                    'content',
                    'confidence',
                    'challenged_assumption',
                    'supported_episode_ids',
                    'rejected_episode_ids',
                    'rejection_reason',
                    'supersedes_memory_id',
                ],
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'object',
            'properties' => [
                'kind' => $kind,

                'content' => ['type' => 'string', 'minLength' => 1],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'challenged_assumption' => ['type' => 'string', 'minLength' => 1],
            ],
            'required' => ['kind', 'content', 'confidence', 'challenged_assumption'],
            'additionalProperties' => false,
        ];
    }
}
