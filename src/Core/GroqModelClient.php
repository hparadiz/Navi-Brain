<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use JsonException;
use RuntimeException;

/**
 * Inactive transport seam for an operator-approved synthetic pilot.
 * No executive work claims, integration, retries, or provider fallback.
 */
final class GroqModelClient
{
    public const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
    public const MODEL = 'openai/gpt-oss-120b';
    public const MAX_COMPLETION_TOKENS = 512;
    public const MAX_REQUEST_BYTES = 4096;
    public const MAX_RESPONSE_BYTES = 65536;
    public const TIMEOUT_SECONDS = 20;

    /** Build a request without reading credentials or opening a connection. */
    public static function requestPayload(string $prompt, string $kind): array
    {
        if ($prompt === '' || preg_match('/\A[a-z][a-z0-9_]{0,95}\z/D', $kind) !== 1) {
            throw new RuntimeException('Invalid pilot prompt or proposal kind.');
        }
        $payload = [
            'model' => self::MODEL,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.6,
            'reasoning_effort' => 'low',
            'include_reasoning' => false,
            'max_completion_tokens' => self::MAX_COMPLETION_TOKENS,
            'stream' => false,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'navi_proposal',
                    'strict' => true,
                    'schema' => LocalModelWorker::proposalSchema([
                        'input_refs' => ['operation' => $kind],
                    ]),
                ],
            ],
        ];
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > self::MAX_REQUEST_BYTES) {
            throw new RuntimeException('Pilot request exceeds the byte limit.');
        }
        return $payload;
    }

    /**
     * Submit one request. The caller must authorize account use and data egress.
     * The first pilot supports the existing four-field proposal shape only.
     * Provider errors are codes and numeric metadata, never response bodies.
     *
     * @return array<string, mixed>
     */
    public function complete(string $prompt, string $kind): array
    {
        $payload = json_encode(self::requestPayload($prompt, $kind), JSON_THROW_ON_ERROR);
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The synthetic pilot requires ext-curl.');
        }
        if ((curl_version()['features'] & CURL_VERSION_ASYNCHDNS) === 0) {
            throw new RuntimeException('The synthetic pilot requires bounded asynchronous DNS.');
        }
        $apiKey = getenv('GROQ_API_KEY');
        if (!is_string($apiKey) || preg_match('/\A[\x21-\x7e]{1,512}\z/D', $apiKey) !== 1) {
            throw new RuntimeException('GROQ_API_KEY is missing or invalid.');
        }
        $curl = curl_init(self::ENDPOINT);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the pilot transport.');
        }

        $body = '';
        $headerBytes = 0;
        $limitReached = false;
        $retryAfter = null;
        $started = hrtime(true);
        try {
            $configured = curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_CONNECTTIMEOUT_MS => 5000,
                CURLOPT_TIMEOUT_MS => self::TIMEOUT_SECONDS * 1000,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$limitReached): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                        $limitReached = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headerBytes, &$limitReached, &$retryAfter): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > 16384) {
                        $limitReached = true;
                        return 0;
                    }
                    if (str_starts_with($line, 'HTTP/')) {
                        $retryAfter = null;
                    } elseif (stripos($line, 'Retry-After:') === 0) {
                        $value = trim(substr($line, strlen('Retry-After:')));
                        if (preg_match('/\A[0-9]{1,9}\z/D', $value) === 1) {
                            $retryAfter = (int) $value;
                        } else {
                            $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $value);
                            $retryAfter = $date === false ? null : max(0, $date->getTimestamp() - time());
                        }
                    }
                    return strlen($line);
                },
            ]);
            if (!$configured) {
                throw new RuntimeException('Could not configure the bounded pilot transport.');
            }
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($curl);
        } finally {
            curl_close($curl);
            unset($apiKey);
        }
        $result = [
            'status' => 'rejected',
            'model' => self::MODEL,
            'http_status' => $status,
            'latency_ms' => null,
            'retry_after_seconds' => $retryAfter,
            'finish_reason' => null,
            'usage' => null,
            'proposal' => null,
        ];
        if ($ok === false) {
            $result['error'] = $limitReached ? 'response_limit' : ($error === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'transport_failure');
            return self::timedResult($result, $started);
        }
        if ($status !== 200) {
            $result['error'] = $status === 429 ? 'rate_limited' : 'http_failure';
            return self::timedResult($result, $started);
        }
        try {
            $response = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $result['error'] = 'invalid_response_json';
            return self::timedResult($result, $started);
        }
        if (!is_array($response) || ($response['model'] ?? null) !== self::MODEL) {
            $result['error'] = 'unexpected_response_model';
            return self::timedResult($result, $started);
        }
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $result['usage'] = [];
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            $value = $usage[$key] ?? null;
            $result['usage'][$key] = is_int($value) && $value >= 0 ? $value : null;
        }
        $details = is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : [];
        $reasoningTokens = $details['reasoning_tokens'] ?? null;
        $result['usage']['reasoning_tokens'] = is_int($reasoningTokens) && $reasoningTokens >= 0 ? $reasoningTokens : null;
        if (($result['usage']['completion_tokens'] ?? 0) > self::MAX_COMPLETION_TOKENS
            || ($result['usage']['reasoning_tokens'] ?? 0) > self::MAX_COMPLETION_TOKENS) {
            $result['error'] = 'reported_completion_budget_exceeded';
            return self::timedResult($result, $started);
        }
        if ($result['usage']['reasoning_tokens'] !== null && $result['usage']['completion_tokens'] !== null
            && $result['usage']['reasoning_tokens'] > $result['usage']['completion_tokens']) {
            $result['error'] = 'inconsistent_reported_usage';
            return self::timedResult($result, $started);
        }
        $choices = $response['choices'] ?? null;
        $choice = is_array($choices) && count($choices) === 1 ? ($choices[0] ?? null) : null;
        $message = is_array($choice) ? ($choice['message'] ?? null) : null;
        $finish = is_array($choice) ? ($choice['finish_reason'] ?? null) : null;
        $result['finish_reason'] = in_array($finish, ['stop', 'length', 'tool_calls', 'content_filter', 'function_call'], true) ? $finish : 'unknown';
        if ($finish !== 'stop' || !is_array($message)
            || ($message['role'] ?? null) !== 'assistant'
            || !empty($message['refusal']) || !empty($message['tool_calls']) || !empty($message['function_call'])) {
            $result['error'] = 'incomplete_or_refused_completion';
            return self::timedResult($result, $started);
        }
        $content = $message['content'] ?? null;
        if (!is_string($content) || strlen($content) > 8192) {
            $result['error'] = 'invalid_completion_content';
            return self::timedResult($result, $started);
        }
        try {
            $proposal = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $result['error'] = 'invalid_proposal_json';
            return self::timedResult($result, $started);
        }
        $keys = ['kind', 'content', 'confidence', 'challenged_assumption'];
        if (!is_array($proposal) || count($proposal) !== count($keys)
            || array_diff($keys, array_keys($proposal)) !== []
            || ($proposal['kind'] ?? null) !== $kind
            || !is_string($proposal['content']) || trim($proposal['content']) === ''
            || !is_string($proposal['challenged_assumption']) || trim($proposal['challenged_assumption']) === ''
            || !(is_int($proposal['confidence']) || is_float($proposal['confidence']))
            || !is_finite((float) $proposal['confidence']) || $proposal['confidence'] < 0 || $proposal['confidence'] > 1) {
            $result['error'] = 'invalid_proposal_shape';
            return self::timedResult($result, $started);
        }
        $result['status'] = 'completed';
        $result['proposal'] = $proposal;
        return self::timedResult($result, $started);
    }

    private static function timedResult(array $result, int $started): array
    {
        $result['latency_ms'] = round((hrtime(true) - $started) / 1000000, 3);
        return $result;
    }
}
