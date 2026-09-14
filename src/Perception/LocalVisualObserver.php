<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use JsonException;
use RuntimeException;

class LocalVisualObserver
{
    private const INFERENCE_TIMEOUT_MS = 25000;

    private string $projectRoot;

    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    public function observe(string $imagePath, string $reason): array
    {
        $ocrStartedAt = hrtime(true);
        $ocr = $this->run([ '/usr/bin/tesseract', $imagePath, 'stdout', '--psm', '6', '-l', 'eng', ], 5000);
        $ocrMs = (hrtime(true) - $ocrStartedAt) / 1_000_000;
        $localFlags = $ocr['exit_code'] === 0
            ? $this->sensitiveCategories($ocr['stdout'])
            : ['other'];

        $prompt = trim((string) file_get_contents( $this->projectRoot . '/config/desktop-vision-prompt.txt' )) . "\n\nReason for this observation: " . $this->reasonForLocalModel($reason);

        [$modelPath, $projectorPath] = $this->visionModelPaths();
        if (!is_file($modelPath) || !is_file($projectorPath)) {
            return $this->unavailableResult($localFlags, $ocrMs, 0.0, 'Local Gemma vision model files are not installed.');
        }

        $visionStartedAt = hrtime(true);
        $vision = $this->run([
            '/usr/local/bin/llama-mtmd-cli',
            '--model',
            $modelPath,
            '--mmproj',
            $projectorPath,
            '--offline',
            '--image',
            $imagePath,
            '--prompt',
            $prompt,
            '--json-schema-file',
            $this->projectRoot . '/config/desktop-vision-schema.json',
            '--predict',
            '256',
            '--temperature',
            '0',
            '--ctx-size',
            '4096',
            '--image-max-tokens',
            '512',
            '--threads',
            '8',
            '--prio',
            '-1',
            '--poll',
            '0',
            '--gpu-layers',
            'auto',
            '--log-verbosity',
            '1',
        ], self::INFERENCE_TIMEOUT_MS);
        $visionMs = (hrtime(true) - $visionStartedAt) / 1_000_000;

        if ($vision['exit_code'] !== 0) {
            return $this->unavailableResult($localFlags, $ocrMs, $visionMs, $vision['stderr']);
        }

        try {
            $judgement = $this->decodeObject($vision['stdout']);
        } catch (JsonException|RuntimeException $exception) {
            return $this->unavailableResult($localFlags, $ocrMs, $visionMs, $exception->getMessage());
        }

        $withheld = array_values(array_unique(array_merge( $localFlags, is_array($judgement['withheld_categories'] ?? null) ? array_values(array_filter($judgement['withheld_categories'], 'is_string')) : [] )));
        $sensitivity = is_string($judgement['sensitivity'] ?? null)
            ? $judgement['sensitivity']
            : 'unknown';
        if (array_intersect($withheld, ['credentials', 'authentication'])) {
            $sensitivity = 'secret';
        } elseif ($withheld !== [] && $sensitivity === 'none') {
            $sensitivity = 'personal';
        }

        $summary = is_string($judgement['summary'] ?? null)
            ? $this->sanitizeSummary($judgement['summary'])
            : 'Local visual classification completed without a usable summary.';
        if ($sensitivity === 'secret') {
            $summary = 'Sensitive desktop content was detected locally; details were withheld.';
        } elseif ($sensitivity === 'personal') {
            $summary = 'Personal desktop activity was detected locally; identifying details were withheld.';
        }

        return [
            'available' => true,
            'scene' => $this->enumValue($judgement, 'scene', 'unknown'),
            'activity' => $this->enumValue($judgement, 'activity', 'unknown'),
            'sensitivity' => $sensitivity,
            'summary' => $summary,
            'visible_applications' => $sensitivity === 'none'
                ? $this->safeApplicationNames($judgement['visible_applications'] ?? [])
                : [],
            'relevant_to_reason' => (bool) ($judgement['relevant_to_reason'] ?? false),
            'raw_image_safe' => $sensitivity === 'none'
                && $withheld === []
                && ($judgement['raw_image_safe'] ?? false) === true,
            'withheld_categories' => $withheld,
            'local_only' => true,
            'network_disabled' => true,
            'model' => 'Gemma 3 4B QAT via llama.cpp',
            'model_persistent' => false,
            'ocr_ms' => round($ocrMs, 2),
            'vision_ms' => round($visionMs, 2),
        ];
    }

    /** @return list<string> */
    private function sensitiveCategories(string $text): array
    {
        $patterns = [
            'credentials' => '/\b(password|passphrase|api[ _-]?key|secret|access[ _-]?token|private[ _-]?key|keepass|bitwarden)\b/i',
            'authentication' => '/\b(one[ -]?time|verification code|authenticator|two[ -]?factor|2fa|otp)\b/i',
            'private_messages' => '/\b(signal|telegram|discord|slack|messenger|inbox|direct messages?)\b/i',
            'financial' => '/\b(account number|routing number|credit card|bank statement|wallet seed|balance due)\b/i',
            'medical' => '/\b(diagnosis|patient|prescription|medical record|test result)\b/i',
            'identity' => '/\b(social security|ssn|passport|driver.?s license)\b/i',
        ];

        $categories = [];
        foreach ($patterns as $category => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $categories[] = $category;
            }
        }
        return $categories;
    }

    private function reasonForLocalModel(string $reason): string
    {
        $reason = preg_replace('/[^\pL\pN .,:;!?_-]+/u', ' ', $reason) ?? '';
        return mb_substr(trim($reason), 0, 240);
    }

    /** @return array{string, string} */
    private function visionModelPaths(): array
    {
        $model = getenv('NAVI_BRAIN_VISION_MODEL');
        $projector = getenv('NAVI_BRAIN_VISION_MMPROJ');
        if (
            is_string($model) && trim($model) !== ''
            && is_string($projector) && trim($projector) !== ''
        ) {
            return [trim($model), trim($projector)];
        }

        $cacheHome = getenv('XDG_CACHE_HOME');
        if (!is_string($cacheHome) || trim($cacheHome) === '') {
            $userHome = getenv('HOME');
            $cacheHome = is_string($userHome) && trim($userHome) !== ''
                ? rtrim($userHome, DIRECTORY_SEPARATOR) . '/.cache'
                : sys_get_temp_dir();
        }
        $cache = rtrim($cacheHome, DIRECTORY_SEPARATOR) . '/llama.cpp/';
        return [
            $cache . 'ggml-org_gemma-3-4b-it-qat-GGUF_gemma-3-4b-it-qat-Q4_0.gguf',
            $cache . 'ggml-org_gemma-3-4b-it-qat-GGUF_mmproj-model-f16-4B.gguf',
        ];
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $output): array
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('Local vision model did not return JSON.');
        }
        $decoded = json_decode(substr($output, $start, $end - $start + 1), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Local vision model returned an invalid object.');
        }
        return $decoded;
    }

    private function sanitizeSummary(string $summary): string
    {
        $summary = preg_replace('/https?:\/\/\S+|\b\S+@\S+\.\S+\b/u', '[withheld]', $summary) ?? '';
        $summary = preg_replace('/\b(?:\d[ -]?){6,}\b/u', '[withheld]', $summary) ?? '';
        $summary = preg_replace('/\b[A-Za-z0-9_\/-]{24,}\b/u', '[withheld]', $summary) ?? '';
        return mb_substr(trim($summary), 0, 240);
    }

    /** @return list<string> */
    private function safeApplicationNames(mixed $applications): array
    {
        if (!is_array($applications)) {
            return [];
        }
        $safe = [];
        foreach ($applications as $application) {
            if (!is_string($application)) {
                continue;
            }
            $application = preg_replace('/[^\pL\pN ._+-]+/u', '', $application) ?? '';
            $application = mb_substr(trim($application), 0, 40);
            if ($application !== '') {
                $safe[] = $application;
            }
        }
        return array_slice(array_values(array_unique($safe)), 0, 5);
    }

    /** @param array<string, mixed> $values */
    private function enumValue(array $values, string $key, string $default): string
    {
        return is_string($values[$key] ?? null) ? $values[$key] : $default;
    }

    /**
     * @param list<string> $localFlags
     * @return array<string, mixed>
     */
    private function unavailableResult(array $localFlags, float $ocrMs, float $visionMs, string $error): array {
        return [
            'available' => false,
            'scene' => 'unknown',
            'activity' => 'unknown',
            'sensitivity' => 'unknown',
            'summary' => 'Local visual judgement was unavailable; desktop details were withheld.',
            'visible_applications' => [],
            'relevant_to_reason' => false,
            'raw_image_safe' => false,
            'withheld_categories' => $localFlags,
            'local_only' => true,
            'network_disabled' => true,
            'model' => 'Gemma 3 4B QAT via llama.cpp',
            'model_persistent' => false,
            'ocr_ms' => round($ocrMs, 2),
            'vision_ms' => round($visionMs, 2),
            'error' => mb_substr(trim($error), 0, 240),
        ];
    }

    /**
     * @param list<string> $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function run(array $command, int $timeoutMs): array
    {
        $pipes = [];
        $process = proc_open($command, [ 0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], ], $pipes, options: ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start local visual helper.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = hrtime(true);
        $exitCode = -1;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if ((hrtime(true) - $startedAt) / 1_000_000 >= $timeoutMs) {
                proc_terminate($process);
                usleep(20_000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                $stderr .= sprintf('Timed out after %d ms.', $timeoutMs);
                break;
            }
            usleep(10_000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        if ($exitCode < 0 && $closedExitCode >= 0) {
            $exitCode = $closedExitCode;
        }
        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
