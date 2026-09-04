<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use JsonException;
use NaviBrain\Support\PlainText;
use RuntimeException;
use Throwable;

/**
 * Runs durable, high-consequence background synthesis through Codex Spark.
 *
 * The worker uses the existing ChatGPT-authenticated Codex CLI in an empty,
 * read-only, ephemeral workspace. Codex receives no write or approval path;
 * shell, browser, app, computer, image, multi-agent, plugin, goal, and web
 * tools are disabled. Its final answer is constrained by the same schema the
 * local worker uses before deterministic integration.
 */
final class CodexSparkWorker
{
    public const MODEL_ID = 'gpt-5.3-codex-spark';

    private const WORK_LEASE_SECONDS = 1200;
    private const MAX_OUTPUT_BYTES = 1048576;

    private string $projectRoot;
    private string $runtimeRoot;
    private string $codexBinary;

    public function __construct(private readonly ExecutiveCore $core)
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->runtimeRoot = $this->projectRoot . '/var/codex-spark-worker';
        $configuredBinary = getenv('CODEX_BIN');
        $this->codexBinary = is_string($configuredBinary) && trim($configuredBinary) !== ''
            ? trim($configuredBinary)
            : '/usr/local/bin/codex';
    }

    /** @return array<string, mixed> */
    public function runOnce(string $owner): array
    {
        $cooldown = $this->core->modelCooldownRemaining(self::MODEL_ID);
        if ($cooldown !== null) {
            return [
                'status' => 'spark_model_cooling_down',
                'seconds_remaining' => $cooldown,
                'model' => self::MODEL_ID,
            ];
        }

        if (!is_file($this->codexBinary) || !is_executable($this->codexBinary)) {
            $message = 'Codex CLI is not executable at the configured path.';
            $this->recordModelResultBestEffort(false, 0, $message);
            return ['status' => 'spark_model_unavailable', 'model' => self::MODEL_ID, 'error' => $message];
        }

        $claimed = $this->core->claimWork($owner, self::WORK_LEASE_SECONDS);
        if ($claimed === null) {
            return ['status' => 'idle'];
        }

        $work = $claimed['work_item'];
        $wall = max(30, min(900, (int) $work['wall_budget_seconds']));
        $started = hrtime(true);
        try {
            $proposal = $this->invokeModel($work, $wall);
            $finished = $this->finishWorkWithRetry(
                workId: (int) $work['id'],
                owner: $owner,
                fencingToken: (int) $work['fencing_token'],
                succeeded: true,
                result: $proposal,
                model: self::MODEL_ID
            );
        } catch (Throwable $throwable) {
            $latency = (int) round((hrtime(true) - $started) / 1_000_000);
            $message = 'Codex Spark invocation failed: ' . $throwable->getMessage();
            $this->recordModelResultBestEffort(false, $latency, $message);
            try {
                $failed = $this->finishWorkWithRetry(
                    workId: (int) $work['id'],
                    owner: $owner,
                    fencingToken: (int) $work['fencing_token'],
                    succeeded: false,
                    result: [],
                    model: null,
                    error: $message
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
                'integration' => $this->core->handleWorkerFailure($work, $message),
            ];
        }

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $this->recordModelResultBestEffort(true, $latency);
        try {
            $integration = $this->core->integrateWorkerResult($work, $proposal, self::MODEL_ID);
        } catch (Throwable $throwable) {
            $integration = $this->core->handleWorkerFailure(
                $work,
                'Codex Spark completed, but thread integration failed: ' . $throwable->getMessage()
            );
        }

        return [
            'status' => 'completed',
            'model' => self::MODEL_ID,
            'latency_ms' => $latency,
            'result' => $finished,
            'integration' => $integration,
        ];
    }

    /** @param array<string, mixed> $work @return array<string, mixed> */
    private function invokeModel(array $work, int $wall): array
    {
        $this->ensureRuntimeDirectories();
        $schemaPath = tempnam($this->runtimeRoot, '.schema-');
        if ($schemaPath === false) {
            throw new RuntimeException('Unable to create the Codex Spark output schema.');
        }

        try {
            $schema = json_encode(
                $this->proposalSchema($work),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($schemaPath, $schema, LOCK_EX) !== strlen($schema)
                || !chmod($schemaPath, 0600)
            ) {
                throw new RuntimeException('Unable to write the Codex Spark output schema.');
            }

            $prompt = implode("\n", [
                'This is a bounded deny-all background cognition job.',
                'Do not inspect files, run commands, search, call tools, or ask questions.',
                'The task below contains all relevant evidence. Return the schema-conforming final answer immediately.',
                '',
                PlainText::sanitize((string) $work['prompt']),
            ]);
            $result = $this->runProcess([
                '/usr/bin/timeout',
                '--signal=TERM',
                '--kill-after=10s',
                $wall . 's',
                $this->codexBinary,
                'exec',
                '--strict-config',
                '-m',
                self::MODEL_ID,
                '-s',
                'read-only',
                '-c',
                'approval_policy="never"',
                '-c',
                'web_search="disabled"',
                '--disable',
                'shell_tool',
                '--disable',
                'unified_exec',
                '--disable',
                'multi_agent',
                '--disable',
                'apps',
                '--disable',
                'browser_use',
                '--disable',
                'in_app_browser',
                '--disable',
                'computer_use',
                '--disable',
                'image_generation',
                '--disable',
                'plugins',
                '--disable',
                'goals',
                '-C',
                $this->runtimeRoot . '/work',
                '--ephemeral',
                '--ignore-user-config',
                '--ignore-rules',
                '--skip-git-repo-check',
                '--output-schema',
                $schemaPath,
                '-',
            ], $prompt, $wall + 15);
            if ($result['exit_code'] !== 0) {
                throw new RuntimeException(sprintf(
                    'Codex CLI exited %d (diagnostic sha256 %s).',
                    $result['exit_code'],
                    hash('sha256', $result['stderr'])
                ));
            }

            try {
                $proposal = json_decode(trim($result['stdout']), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Codex Spark did not return exact structured JSON.', 0, $exception);
            }
            if (!is_array($proposal)) {
                throw new RuntimeException('Codex Spark proposal must be a JSON object.');
            }
            return $proposal;
        } finally {
            if (is_file($schemaPath)) {
                @unlink($schemaPath);
            }
        }
    }

    /** @param array<string, mixed> $work @return array<string, mixed> */
    private function proposalSchema(array $work): array
    {
        $schema = LocalModelWorker::proposalSchema($work);
        // The deterministic memory curator already enforces uniqueness and
        // set equality. Codex Structured Outputs does not accept uniqueItems,
        // although llama.cpp's grammar does, so omit only that provider-level
        // duplicate check from Spark's transport schema.
        if (($work['work_type'] ?? null) === ExecutiveCore::MEMORY_CONSOLIDATION_WORK_TYPE) {
            unset(
                $schema['properties']['supported_episode_ids']['uniqueItems'],
                $schema['properties']['rejected_episode_ids']['uniqueItems']
            );
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    private function finishWorkWithRetry(
        int $workId,
        string $owner,
        int $fencingToken,
        bool $succeeded,
        array $result,
        ?string $model,
        ?string $error = null
    ): array {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->core->finishWork(
                    workId: $workId,
                    owner: $owner,
                    fencingToken: $fencingToken,
                    succeeded: $succeeded,
                    result: $result,
                    model: $model,
                    error: $error
                );
            } catch (Throwable $throwable) {
                if ($attempt >= 3
                    || !str_contains(strtolower($throwable->getMessage()), 'database is locked')
                ) {
                    throw $throwable;
                }
                usleep(250000 * ($attempt + 1));
            }
        }
    }

    private function recordModelResultBestEffort(
        bool $succeeded,
        int $latencyMs,
        ?string $error = null
    ): void {
        try {
            $this->core->recordModelResult(self::MODEL_ID, $succeeded, $latencyMs, $error);
        } catch (Throwable) {
            // Telemetry must never strand fenced work after inference returns.
        }
    }

    private function ensureRuntimeDirectories(): void
    {
        foreach ([$this->runtimeRoot, $this->runtimeRoot . '/work'] as $path) {
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                throw new RuntimeException(sprintf('Unable to create Spark worker directory: %s', $path));
            }
            if (!chmod($path, 0700)) {
                throw new RuntimeException(sprintf('Unable to harden Spark worker directory: %s', $path));
            }
        }
    }

    /** @param list<string> $command @return array{exit_code: int, stdout: string, stderr: string} */
    private function runProcess(array $command, string $stdin, int $hardLimitSeconds): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $this->projectRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the Codex Spark worker process.');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $started = time();
        $lastStatus = null;
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if (strlen($stdout) + strlen($stderr) > self::MAX_OUTPUT_BYTES) {
                proc_terminate($process, 9);
                throw new RuntimeException('Codex Spark exceeded the output byte limit.');
            }
            $lastStatus = proc_get_status($process);
            if (!($lastStatus['running'] ?? false)) {
                break;
            }
            if (time() - $started > $hardLimitSeconds) {
                proc_terminate($process, 9);
                throw new RuntimeException('Codex Spark exceeded the hard wall-clock limit.');
            }
            usleep(50000);
        } while (true);

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        $exitCode = $closed >= 0 ? $closed : (int) ($lastStatus['exitcode'] ?? -1);
        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
