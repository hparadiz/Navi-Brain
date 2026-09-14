<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use JsonException;
use RuntimeException;

class LocalModelClient
{
    public const DEFAULT_HOST = '127.0.0.1';
    public const DEFAULT_PORT = 5007;

    private const MAX_RESPONSE_BYTES = 1048576;
    private const HEALTH_TIMEOUT_SECONDS = 2;

    private string $host;
    private int $port;

    public function __construct(?string $host = null, ?int $port = null)
    {
        $configuredHost = $host ?? getenv('NAVI_BRAIN_LOCAL_MODEL_HOST');
        $configuredPort = $port ?? getenv('NAVI_BRAIN_LOCAL_MODEL_PORT');
        $this->host = is_string($configuredHost) && trim($configuredHost) !== ''
            ? trim($configuredHost)
            : self::DEFAULT_HOST;
        $this->port = is_numeric($configuredPort) ? (int) $configuredPort : self::DEFAULT_PORT;
    }

    public function endpoint(): string
    {
        return sprintf('%s:%d', $this->host, $this->port);
    }

    public function isHealthy(): bool
    {
        try {
            $response = $this->request('GET', '/health', null, self::HEALTH_TIMEOUT_SECONDS);
        } catch (RuntimeException) {
            return false;
        }
        return ($response['body']['status'] ?? null) === 'ok';
    }

    /**
     * @param array<string, mixed> $jsonSchema
     * @return array{proposal: array<string, mixed>, usage: array<string, mixed>}
     */
    public function complete(string $prompt, array $jsonSchema, int $maxTokens, int $timeoutSeconds): array {
        $payload = json_encode([
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.7,
            'top_p' => 0.9,
            'max_tokens' => max(1, $maxTokens),
            'stream' => false,
            'cache_prompt' => false,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'navi_proposal',
                    'strict' => true,
                    'schema' => $jsonSchema,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->request('POST', '/v1/chat/completions', $payload, max(5, $timeoutSeconds));
        $body = $response['body'];
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Local model returned no completion content.');
        }

        try {
            $proposal = json_decode(trim($content), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Local model output was not exact JSON.', 0, $exception);
        }
        if (!is_array($proposal)) {
            throw new RuntimeException('Local model proposal must be a JSON object.');
        }

        return [
            'proposal' => $proposal,
            'usage' => is_array($body['usage'] ?? null) ? $body['usage'] : [],
        ];
    }

    /** @return array{http_status: int, body: array<string, mixed>} */
    private function request(string $method, string $path, ?string $body, int $timeoutSeconds): array
    {
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $this->host, $this->port), $errorNumber, $errorMessage, $timeoutSeconds, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            throw new RuntimeException(sprintf( 'Local model endpoint %s is unavailable: %s (%d).', $this->endpoint(), $errorMessage, $errorNumber ));
        }

        stream_set_timeout($socket, $timeoutSeconds);
        $payload = $body ?? '';
        $request = sprintf(
            "%s %s HTTP/1.1\r\nHost: %s\r\nConnection: close\r\nContent-Type: application/json\r\nContent-Length: %d\r\n\r\n%s",
            $method,
            $path,
            $this->endpoint(),
            strlen($payload),
            $payload
        );

        try {
            $remaining = $request;
            while ($remaining !== '') {
                $written = fwrite($socket, $remaining);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Local model request could not be written.');
                }
                $remaining = substr($remaining, $written);
            }

            $response = '';
            while (!feof($socket)) {
                $chunk = fread($socket, 65536);
                if ($chunk === false) {

                    $meta = stream_get_meta_data($socket);
                    if ($meta['timed_out'] ?? false) {
                        throw new RuntimeException(sprintf( 'Local model did not answer within %d seconds. This includes time spent waiting for an available server slot.', $timeoutSeconds ));
                    }
                    break;
                }
                $response .= $chunk;
                if (strlen($response) > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Local model exceeded the response byte limit.');
                }
                $meta = stream_get_meta_data($socket);
                if ($meta['timed_out'] ?? false) {
                    throw new RuntimeException('Local model response timed out.');
                }
            }
        } finally {
            fclose($socket);
        }

        if ($response === '') {
            throw new RuntimeException('Local model returned an empty response.');
        }
        [$headers, $responseBody] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        if (!preg_match('/^HTTP\/1\.[01] (\d{3})\b/', $headers, $matches)) {
            throw new RuntimeException('Local model returned an invalid HTTP response.');
        }
        $status = (int) $matches[1];
        if (stripos($headers, 'Transfer-Encoding: chunked') !== false) {
            $responseBody = $this->decodeChunked($responseBody);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf( 'Local model returned HTTP %d: %s', $status, substr(trim($responseBody), 0, 300) ));
        }

        try {
            $decoded = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Local model returned invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Local model returned a non-object JSON response.');
        }
        return ['http_status' => $status, 'body' => $decoded];
    }

    private function decodeChunked(string $body): string
    {
        $decoded = '';
        $offset = 0;
        $length = strlen($body);
        while ($offset < $length) {
            $lineEnd = strpos($body, "\r\n", $offset);
            if ($lineEnd === false) {
                break;
            }
            $size = hexdec(trim(substr($body, $offset, $lineEnd - $offset)));
            if (!is_int($size) || $size <= 0) {
                break;
            }
            $decoded .= substr($body, $lineEnd + 2, $size);
            $offset = $lineEnd + 2 + $size + 2;
        }
        return $decoded === '' ? $body : $decoded;
    }
}
