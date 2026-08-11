<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use JsonException;
use NaviBrain\Perception\SocialFeedback;
use RuntimeException;

final class FreeModelWorker
{
    private const MAX_OUTPUT_BYTES = 1048576;
    private const MODEL_REFRESH_SECONDS = 3600;
    private const MAX_MODELS_PER_WORK = 3;
    private const WORK_LEASE_SECONDS = 360;

    private string $projectRoot;
    private string $runtimeRoot;
    private string $opencodeBinary;
    private int $lastDiscoveryAttemptAt = 0;

    public function __construct(private readonly ExecutiveCore $core)
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->runtimeRoot = $this->projectRoot . '/var/opencode-worker';
        $configuredBinary = getenv('OPENCODE_BIN');
        $this->opencodeBinary = is_string($configuredBinary) && $configuredBinary !== ''
            ? $configuredBinary
            : '/home/akujin/.opencode/bin/opencode';
    }

    /** @return array<string, mixed> */
    public function runOnce(string $owner): array
    {
        $this->ensureRuntimeDirectories();
        try {
            $this->refreshModelsWhenDue();
        } catch (\Throwable $throwable) {
            return ['status' => 'model_discovery_failed', 'error' => $throwable->getMessage()];
        }
        if ($this->core->selectableFreeModels() === []) {
            return ['status' => 'model_pool_unavailable'];
        }
        $claimed = $this->core->claimWork($owner, self::WORK_LEASE_SECONDS);
        if ($claimed === null) {
            return ['status' => 'idle'];
        }

        $work = $claimed['work_item'];
        $errors = [];
        $models = array_slice($this->core->selectableFreeModels(), 0, self::MAX_MODELS_PER_WORK);
        $deadline = microtime(true) + max(1, (int) $work['wall_budget_seconds']);
        foreach ($models as $index => $modelId) {
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 1) {
                break;
            }
            $remainingCandidates = count($models) - $index;
            $attemptWall = max(1, intdiv($remaining, $remainingCandidates));
            $started = hrtime(true);
            try {
                $invocation = $this->invokeModel($work, $modelId, $attemptWall);
                $latency = (int) round((hrtime(true) - $started) / 1_000_000);
                $this->core->recordModelResult($modelId, true, $latency);
                $finished = $this->core->finishWork(
                    workId: (int) $work['id'],
                    owner: $owner,
                    fencingToken: (int) $work['fencing_token'],
                    succeeded: true,
                    result: $invocation['proposal'],
                    model: $modelId
                );
                try {
                    $integration = $this->core->integrateWorkerResult(
                        $work,
                        $invocation['proposal'],
                        $modelId
                    );
                } catch (\Throwable $throwable) {
                    $integration = $this->core->handleWorkerFailure(
                        $work,
                        'Worker completed, but thread integration failed: ' . $throwable->getMessage()
                    );
                }
                if (isset($invocation['session_id'])) {
                    $this->deleteSession((string) $invocation['session_id']);
                }
                return [
                    'status' => 'completed',
                    'model' => $modelId,
                    'latency_ms' => $latency,
                    'result' => $finished,
                    'integration' => $integration,
                ];
            } catch (\Throwable $throwable) {
                $latency = (int) round((hrtime(true) - $started) / 1_000_000);
                $errors[$modelId] = $throwable->getMessage();
                $this->core->recordModelResult($modelId, false, $latency, $throwable->getMessage());
            }
        }

        $message = $errors === []
            ? 'No discovered free model was outside its circuit-breaker cooldown.'
            : 'Every selectable free model failed: ' . json_encode(
                $errors,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        $failed = $this->core->finishWork(
            workId: (int) $work['id'],
            owner: $owner,
            fencingToken: (int) $work['fencing_token'],
            succeeded: false,
            result: [],
            model: null,
            error: $message
        );
        $integration = $this->core->handleWorkerFailure($work, $message);
        return [
            'status' => 'failed',
            'errors' => $errors,
            'result' => $failed,
            'integration' => $integration,
        ];
    }

    /** @return list<string> */
    public function discoverModels(): array
    {
        $this->ensureRuntimeDirectories();
        $result = $this->runProcess(
            ['/usr/bin/timeout', '--signal=TERM', '--kill-after=5s', '60s', $this->opencodeBinary, 'models'],
            '',
            70
        );
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('OpenCode model discovery failed: ' . trim($result['output']));
        }
        $models = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R/u', $result['output']) ?: []
        ), static fn (string $model): bool => str_ends_with($model, '-free')));
        $this->core->syncFreeModels($models);
        return $models;
    }

    private function refreshModelsWhenDue(): void
    {
        $models = $this->core->listModelEndpoints();
        $latest = 0;
        foreach ($models as $model) {
            $value = $model['last_discovered_at'] ?? null;
            $timestamp = is_int($value) ? $value : (is_string($value) ? strtotime($value) : false);
            if ($timestamp !== false && $timestamp !== null) {
                $latest = max($latest, (int) $timestamp);
            }
        }
        if ($models === [] || time() - $latest >= self::MODEL_REFRESH_SECONDS) {
            if (time() - $this->lastDiscoveryAttemptAt < 300) {
                return;
            }
            $this->lastDiscoveryAttemptAt = time();
            $this->discoverModels();
        }
    }

    /** @param array<string, mixed> $work
     *  @return array{proposal: array<string, mixed>, session_id?: string}
     */
    private function invokeModel(array $work, string $modelId, int $wall): array
    {
        $prompt = (string) $work['prompt'] . "\n\nRequired JSON schema:\n" . json_encode(
            $this->workerSchema((string) $work['work_type']),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $wall = max(1, min(3600, $wall));
        $title = sprintf('navi:%s:%d', $work['work_type'], $work['id']);
        $result = $this->runProcess([
            '/usr/bin/timeout',
            '--signal=TERM',
            '--kill-after=10s',
            $wall . 's',
            $this->opencodeBinary,
            'run',
            '--pure',
            '--format',
            'json',
            '--model',
            $modelId,
            '--agent',
            'navi-heartbeat',
            '--dir',
            $this->runtimeRoot . '/work',
            '--title',
            $title,
        ], $prompt, $wall + 15);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(sprintf(
                'OpenCode worker exited %d: %s',
                $result['exit_code'],
                trim($result['output'])
            ));
        }
        return $this->parseWorkerStream($result['output']);
    }

    /** @return array<string, string> */
    private function workerSchema(string $workType): array
    {
        if ($workType === DecisionStateMachine::WORK_TYPE) {
            return [
                'kind' => 'decision_candidates',
                'content' => 'minified JSON object with current_plan, decision_basis, and one-to-three adapter-valid terminal candidates',
                'confidence' => 'number from 0 through 1',
                'challenged_assumption' => 'the assumption the proposed terminal action most depends on',
            ];
        }
        if ($workType === OtherModel::WORK_TYPE) {
            return [
                'kind' => 'other_model_proposition',
                'content' => 'minified JSON with exactly type, proposition, evidence_quote; type is goal, belief, plan, constraint, or none',
                'confidence' => 'number from 0 through 1 for extraction confidence only',
                'challenged_assumption' => 'the assumption that the utterance explicitly reports the extracted proposition',
            ];
        }
        if ($workType === SocialFeedback::WORK_TYPE) {
            return [
                'kind' => 'social_feedback_reflection',
                'content' => 'one lowercase word or short_snake_case phrase naming the observed interaction',
                'confidence' => 'number from 0 through 1',
                'challenged_assumption' => 'the assumption the descriptor most depends on',
            ];
        }
        if ($workType === 'epistemic_advance_step') {
            return [
                'kind' => 'the operation identifier named in the prompt, with no extra words',
                'content' => 'one bounded sentence of 6 to 60 words, grounded in the supplied evidence',
                'confidence' => 'number from 0 through 1',
                'challenged_assumption' => 'the assumption in the current belief this step questions',
            ];
        }
        if ($workType === ExecutiveCore::SELF_PRESENCE_ANSWER_WORK_TYPE) {
            return [
                'kind' => 'self_presence_utterance',
                'content' => '3 to 36 spoken words that directly answer the addressed speech in the prompt',
                'confidence' => 'number from 0 through 1',
                'challenged_assumption' => 'the assumption the answer most depends on',
            ];
        }
        if ($workType === ExecutiveCore::SELF_PRESENCE_SPEECH_WORK_TYPE) {
            return [
                'kind' => 'one of: self_presence_utterance, remain_silent',
                'content' => '3 to 35 spoken words for an utterance, or a short reason for silence',
                'confidence' => 'number from 0 through 1',
                'challenged_assumption' => 'whether speaking or silence is better at this wake',
            ];
        }
        return [
            'kind' => 'short_snake_case_string',
            'content' => 'one bounded unverified thought or repair proposal',
            'confidence' => 'number from 0 through 1',
            'challenged_assumption' => 'the assumption this proposal challenges',
        ];
    }

    /** @return array{proposal: array<string, mixed>, session_id?: string} */
    private function parseWorkerStream(string $output): array
    {
        $sessionId = null;
        $finalText = null;
        $stopped = false;
        foreach (preg_split('/\R/u', trim($output)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            try {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('OpenCode emitted a non-JSON worker line.', 0, $exception);
            }
            if (!is_array($event)) {
                throw new RuntimeException('OpenCode emitted a non-object worker event.');
            }
            $currentSession = $event['sessionID'] ?? null;
            if (is_string($currentSession)) {
                if ($sessionId !== null && $sessionId !== $currentSession) {
                    throw new RuntimeException('OpenCode worker stream mixed multiple session IDs.');
                }
                $sessionId = $currentSession;
            }
            $type = $event['type'] ?? null;
            if ($type === 'tool_use' || $type === 'error') {
                throw new RuntimeException('Deny-all worker emitted a forbidden tool or error event.');
            }
            if ($type === 'text' && is_string($event['part']['text'] ?? null)) {
                $finalText = $event['part']['text'];
            }
            if ($type === 'step_finish' && ($event['part']['reason'] ?? null) === 'stop') {
                $stopped = true;
            }
        }
        if (!$stopped || $finalText === null) {
            throw new RuntimeException('OpenCode worker stream ended without a stopped final text event.');
        }
        try {
            $proposal = json_decode(trim($finalText), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Worker final text was not exact JSON.', 0, $exception);
        }
        if (!is_array($proposal)) {
            throw new RuntimeException('Worker proposal must be a JSON object.');
        }
        $result = ['proposal' => $proposal];
        if ($sessionId !== null) {
            $result['session_id'] = $sessionId;
        }
        return $result;
    }

    private function deleteSession(string $sessionId): void
    {
        try {
            $this->runProcess([
                '/usr/bin/timeout', '--signal=TERM', '--kill-after=2s', '15s',
                $this->opencodeBinary, 'session', 'delete', $sessionId,
            ], '', 20);
        } catch (\Throwable) {
            // Session cleanup is best-effort; the durable work result is already committed.
        }
    }

    private function ensureRuntimeDirectories(): void
    {
        foreach ([$this->runtimeRoot, $this->runtimeRoot . '/work', $this->runtimeRoot . '/xdg-data'] as $path) {
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                throw new RuntimeException(sprintf('Unable to create worker runtime directory: %s', $path));
            }
            if (!chmod($path, 0700)) {
                throw new RuntimeException(sprintf('Unable to harden worker runtime directory: %s', $path));
            }
        }
    }

    /** @param list<string> $command
     *  @return array{exit_code: int, output: string}
     */
    private function runProcess(array $command, string $stdin, int $hardLimitSeconds): array
    {
        $currentEnvironment = getenv();
        $environment = array_merge(is_array($currentEnvironment) ? $currentEnvironment : [], [
            'XDG_CONFIG_HOME' => $this->projectRoot . '/config/opencode-worker',
            'XDG_DATA_HOME' => $this->runtimeRoot . '/xdg-data',
            'XDG_CACHE_HOME' => $this->runtimeRoot . '/xdg-cache',
            'OPENCODE_DB' => $this->runtimeRoot . '/opencode.sqlite',
            'OPENCODE_DISABLE_PROJECT_CONFIG' => '1',
            'OPENCODE_DISABLE_AUTOUPDATE' => '1',
            'OPENCODE_PERMISSION' => '{"*":"deny"}',
            'NO_COLOR' => '1',
        ]);
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $this->projectRoot, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start OpenCode worker process.');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $started = time();
        $lastStatus = null;
        do {
            $output .= stream_get_contents($pipes[1]);
            $output .= stream_get_contents($pipes[2]);
            if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                proc_terminate($process, 9);
                throw new RuntimeException('OpenCode worker exceeded the output byte limit.');
            }
            $lastStatus = proc_get_status($process);
            if (!($lastStatus['running'] ?? false)) {
                break;
            }
            if (time() - $started > $hardLimitSeconds) {
                proc_terminate($process, 9);
                throw new RuntimeException('OpenCode worker exceeded the hard wall-clock limit.');
            }
            usleep(50000);
        } while (true);

        $output .= stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        $exitCode = $closed >= 0 ? $closed : (int) ($lastStatus['exitcode'] ?? -1);
        return ['exit_code' => $exitCode, 'output' => $output];
    }
}
