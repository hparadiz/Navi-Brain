<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Core\ExecutiveCore\Executive;

use RuntimeException;

class OpenCodeDreamClient
{
    public const MODEL = 'muse-spark-1.3-contributor-free';
    public const MAX_PROMPT_BYTES = 16384;
    public const MAX_OUTPUT_TOKENS = 512;
    private readonly FreeModelWorker $worker;
    private int $preparedAt = 0;

    public function __construct(Executive $core, string $modelId = self::MODEL)
    {
        $model = str_starts_with($modelId, 'opencode/') ? substr($modelId, 9) : $modelId;
        if ($model !== self::MODEL) {
            throw new RuntimeException('Dream inference requires the selected OpenCode free Muse model.');
        }
        $this->worker = new FreeModelWorker($core);
    }

    public function preflight(): void
    {
        $this->preparedAt = 0;
        if (!in_array('opencode/' . self::MODEL, $this->worker->discoverModels(), true)) {
            throw new RuntimeException('The selected free Muse model is unavailable in OpenCode.');
        }
        $this->preparedAt = time();
    }

    public function complete(string $prompt, int $tokenBudget = 512): array
    {
        if ($this->preparedAt === 0 || time() < $this->preparedAt || time() - $this->preparedAt >= 3600) {
            throw new RuntimeException('OpenCode dream inference requires a current preflight.');
        }
        $this->preparedAt = 0;
        if (trim($prompt) === '' || strlen($prompt) > self::MAX_PROMPT_BYTES
            || $tokenBudget < 1 || $tokenBudget > self::MAX_OUTPUT_TOKENS) {
            throw new RuntimeException('OpenCode dream prompt or output budget exceeds its bound.');
        }
        $started = hrtime(true);
        $result = $this->worker->completeDreamPrompt($prompt, $tokenBudget);
        return $result + ['model' => 'opencode/' . self::MODEL,
            'latency_ms' => round((hrtime(true) - $started) / 1000000, 3)];
    }
}
