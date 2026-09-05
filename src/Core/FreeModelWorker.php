<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use JsonException;
use NaviBrain\Perception\SocialFeedback;
use NaviBrain\Support\PlainText;
use RuntimeException;

final class FreeModelWorker
{
    private const MAX_OUTPUT_BYTES = 1048576;
    private const MODEL_REFRESH_SECONDS = 3600;
    private const MAX_MODELS_PER_WORK = 3;
    // One full-evidence narrative may legitimately use the entire 900-second
    // work budget across model fallbacks. Keep its fence alive through that
    // window instead of requeueing it underneath the still-running worker.
    private const WORK_LEASE_SECONDS = 1200;

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
    public function runOnce(
        string $owner,
        array $includedWorkTypes = [],
        array $allowedModels = []
    ): array
    {
        foreach ($allowedModels as $model) {
            if (!is_string($model) || preg_match('~\Aopencode/[a-z0-9][a-z0-9._-]*-free\z~', $model) !== 1) {
                throw new RuntimeException('Pinned OpenCode workers require explicit opencode/*-free model IDs.');
            }
        }
        $this->ensureRuntimeDirectories();
        $quota = new OpenCodeQuota($this->runtimeRoot);
        try {
            if (!$quota->acquire()) {
                return ['status' => 'provider_busy'];
            }
            if ($quota->retryAt() > time()) {
                return ['status' => 'provider_cooling_down', 'retry_at' => $quota->retryAt()];
            }
            return $this->runAvailable($owner, $includedWorkTypes, $allowedModels, $quota);
        } finally {
            $quota->release();
        }
    }

    private function runAvailable(
        string $owner,
        array $includedWorkTypes,
        array $allowedModels,
        OpenCodeQuota $quota
    ): array {
        try {
            $this->refreshModelsWhenDue();
        } catch (\Throwable $throwable) {
            return ['status' => 'model_discovery_failed', 'error' => $throwable->getMessage()];
        }
        $models = $this->core->selectableFreeModels();
        if ($allowedModels !== []) {
            $models = array_values(array_intersect($allowedModels, $models));
        }
        $models = array_slice($models, 0, self::MAX_MODELS_PER_WORK);
        if ($models === []) {
            return ['status' => 'model_pool_unavailable'];
        }
        $claimed = $this->core->claimWork(
            $owner,
            self::WORK_LEASE_SECONDS,
            [],
            $includedWorkTypes
        );
        if ($claimed === null) {
            return ['status' => 'idle'];
        }

        $work = $claimed['work_item'];
        $errors = [];
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
                $finished = $this->finishWorkWithRetry(
                    workId: (int) $work['id'],
                    owner: $owner,
                    fencingToken: (int) $work['fencing_token'],
                    succeeded: true,
                    result: $invocation['proposal'],
                    model: $modelId
                );
                $this->recordModelResultBestEffort($modelId, true, $latency);
                try {
                    $quota->succeeded();
                } catch (\Throwable) {
                    // Failure to clear an expired brake is conservative; it
                    // must not replay or fail already-committed work.
                }
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
            } catch (ModelRateLimit $limited) {
                // All local OpenCode lanes share this brake. Do not try a
                // second model or reject the source evidence because of 429.
                $retryAt = $quota->rateLimited($limited->retryAt);
                $this->core->deferWork((int) $work['id'], $owner, (int) $work['fencing_token'], $retryAt);
                return ['status' => 'provider_cooling_down', 'retry_at' => $retryAt, 'work_id' => $work['id']];
            } catch (\Throwable $throwable) {
                $latency = (int) round((hrtime(true) - $started) / 1_000_000);
                $errors[$modelId] = $throwable->getMessage();
                $this->recordModelResultBestEffort(
                    $modelId,
                    false,
                    $latency,
                    $throwable->getMessage()
                );
            }
        }

        $message = $errors === []
            ? 'No discovered free model was outside its circuit-breaker cooldown.'
            : 'Every selectable free model failed: ' . PlainText::inline($errors, 3000);
        $failed = $this->finishWorkWithRetry(
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
            } catch (\Throwable $throwable) {
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
        string $modelId,
        bool $succeeded,
        int $latencyMs,
        ?string $error = null
    ): void {
        try {
            $this->core->recordModelResult($modelId, $succeeded, $latencyMs, $error);
        } catch (\Throwable) {
            // Telemetry must never strand fenced work after inference returns.
        }
    }

    /** @return list<string> */
    public function discoverModels(): array
    {
        $this->ensureRuntimeDirectories();
        $result = $this->runProcess(
            ['/usr/bin/timeout', '--signal=TERM', '--kill-after=5s', '60s', $this->opencodeBinary, 'models', 'opencode', '--refresh'],
            '',
            70
        );
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('OpenCode model discovery failed: ' . trim($result['output']));
        }
        $models = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R/u', $result['output']) ?: []
        ), static fn (string $model): bool => preg_match('~\Aopencode/[a-z0-9][a-z0-9._-]*-free\z~', $model) === 1));
        $this->core->syncFreeModels($models);
        return $models;
    }

    private function refreshModelsWhenDue(): void
    {
        $models = $this->core->listModelEndpoints();
        $latest = 0;
        foreach ($models as $model) {
            if (!str_starts_with((string) ($model['model_id'] ?? ''), 'opencode/')) {
                continue;
            }
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
        $prompt = ($work['work_type'] === PublicReflection::WORK_TYPE
                ? (string) $work['prompt'] : PlainText::sanitize((string) $work['prompt']))
            . "\n\nRequired response fields:\n"
            . PlainText::render($this->workerSchema((string) $work['work_type']), 5000, 20);
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
        // Error events can be emitted with either exit status. Inspect them
        // before a generic exit-code error discards status and Retry-After.
        $this->checkStreamErrors($result['output']);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(sprintf(
                'OpenCode worker exited %d: %s',
                $result['exit_code'],
                trim($result['output'])
            ));
        }
        return $this->parseWorkerStream($result['output'], (string) $work['work_type']);
    }

    /** @return array<string, string> */
    private function workerSchema(string $workType): array
    {
        if ($workType === PublicReflection::WORK_TYPE) {
            return [
                'kind' => PublicReflection::WORK_TYPE,
                'content' => 'one code finding with an exact source quote and a falsifiable check, or the specific missing evidence',
                'confidence' => 'number from 0 through 1 for support in the supplied code, not runtime verification',
                'challenged_assumption' => 'the specific assumption challenged by this finding',
            ];
        }
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
        if ($workType === ExecutiveCore::MEMORY_CONSOLIDATION_WORK_TYPE) {
            return [
                'kind' => 'memory_consolidation',
                'content' => 'the grounded durable claim itself, or an empty string when rejecting the whole batch',
                'confidence' => 'number from 0 through 1 for the grounded claim, or 0 when rejecting the whole batch',
                'challenged_assumption' => 'the existing belief or proposed generalization tested by this replay',
                'supported_episode_ids' => 'JSON array of fresh episode integer IDs that directly support content',
                'rejected_episode_ids' => 'JSON array of every other fresh episode integer ID',
                'rejection_reason' => 'why rejected IDs do not support a durable claim, or none',
                'supersedes_memory_id' => 'one offered semantic-memory integer ID, or null',
            ];
        }
        if (NarrativeSynthesis::isWorkType($workType)) {
            return [
                'kind' => (string) NarrativeSynthesis::expectedKind($workType),
                'content' => 'the complete first-person plain-language narrative requested by the prompt',
                'confidence' => 'number from 0 through 1 for fidelity to the complete supplied evidence',
                'challenged_assumption' => 'one short assumption that most threatens a faithful synthesis',
            ];
        }
        return [
            'kind' => 'short_snake_case_string',
            'content' => 'one bounded unverified thought or repair proposal',
            'confidence' => 'number from 0 through 1',
            'challenged_assumption' => 'the assumption this proposal challenges',
        ];
    }

    private function checkStreamErrors(string $output): void
    {
        foreach (preg_split('/\R/u', trim($output)) ?: [] as $line) {
            $event = json_decode($line, true);
            if (!is_array($event)) {
                continue;
            }
            if (($event['type'] ?? '') === 'tool_use') {
                throw new RuntimeException('Deny-all worker emitted a forbidden tool event.');
            }
            if (($event['type'] ?? '') !== 'error') {
                continue;
            }
            $data = $event['error']['data'] ?? [];
            if (($data['statusCode'] ?? null) === 429) {
                throw new ModelRateLimit(is_array($data['responseHeaders'] ?? null) ? $data['responseHeaders'] : []);
            }
            // Do not copy response bodies/headers, which can contain credentials.
            throw new RuntimeException(sprintf('OpenCode %s (HTTP %s): %s',
                (string) ($event['error']['name'] ?? 'error'),
                (string) ($data['statusCode'] ?? 'unknown'),
                (string) ($data['message'] ?? 'No provider error message.')
            ));
        }
    }

    /** @return array{proposal: array<string, mixed>, session_id?: string} */
    private function parseWorkerStream(string $output, string $workType): array
    {
        $this->checkStreamErrors($output);
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
        $proposal = $this->decodeWorkerProposal($finalText, $workType);
        if (!is_array($proposal)) {
            throw new RuntimeException('Worker proposal must be a JSON object.');
        }
        $result = ['proposal' => $proposal];
        if ($sessionId !== null) {
            $result['session_id'] = $sessionId;
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function decodeWorkerProposal(string $finalText, string $workType): array
    {
        $trimmed = trim($finalText);
        try {
            $proposal = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($proposal)) {
                return $proposal;
            }
        } catch (JsonException $exception) {
            if (!NarrativeSynthesis::isWorkType($workType)) {
                throw new RuntimeException('Worker final text was not exact JSON.', 0, $exception);
            }
        }

        // Narrative models commonly wrap an otherwise valid transport object
        // in a code fence or one sentence of chatter. Unwrap that only for
        // this derived-output lane; every action-bearing worker stays exact.
        $candidates = [];
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/isu', $trimmed, $match) === 1) {
            $candidates[] = $match[1];
        }
        $firstBrace = strpos($trimmed, '{');
        $lastBrace = strrpos($trimmed, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidates[] = substr($trimmed, $firstBrace, $lastBrace - $firstBrace + 1);
        }
        foreach (array_unique($candidates) as $candidate) {
            try {
                $proposal = json_decode($candidate, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (is_array($proposal)) {
                return $proposal;
            }
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            throw new RuntimeException('Narrative worker returned malformed structured output.');
        }

        $content = trim((string) preg_replace('/^```(?:text|markdown)?\s*|\s*```$/iu', '', $trimmed));
        if ($content === '') {
            throw new RuntimeException('Narrative worker returned no prose.');
        }
        $kind = (string) NarrativeSynthesis::expectedKind($workType);
        $confidence = 0.5;
        $challengedAssumption = 'Some ranked evidence may be transient, repetitive, or contradictory.';
        $lines = preg_split('/\R/u', $content) ?: [];
        while ($lines !== []) {
            $line = trim((string) end($lines));
            if ($line === '') {
                array_pop($lines);
                continue;
            }
            if (preg_match('/^kind\s*:\s*(.+)$/iu', $line, $match) === 1) {
                $kind = trim($match[1]);
                array_pop($lines);
                continue;
            }
            if (preg_match('/^confidence\s*:\s*(0(?:\.\d+)?|1(?:\.0+)?)$/iu', $line, $match) === 1) {
                $confidence = (float) $match[1];
                array_pop($lines);
                continue;
            }
            if (preg_match('/^challenged[ _-]assumption\s*:\s*(.+)$/iu', $line, $match) === 1) {
                $challengedAssumption = trim($match[1]);
                array_pop($lines);
                continue;
            }
            break;
        }
        $content = trim(implode("\n", $lines));
        if ($content === '') {
            throw new RuntimeException('Narrative worker returned metadata without prose.');
        }
        return [
            'kind' => $kind,
            'content' => $content,
            'confidence' => $confidence,
            'challenged_assumption' => $challengedAssumption,
        ];
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
