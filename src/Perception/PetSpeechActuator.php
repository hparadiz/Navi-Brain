<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use JsonException;
use RuntimeException;

class PetSpeechActuator
{
    private const ADDRESS = 'tcp://127.0.0.1:47831';
    private const HOST = '127.0.0.1:47831';
    private const TIMEOUT_SECONDS = 3;

    /** @return array<string, mixed> */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /** @var array<string, string> */
    private const UNSPEAKABLE = [
        'a first-person model identity claim' => '/\b(?:i\s+am|i[\'’]m)\s+(?:an?\s+|the\s+)?(?:(?:ai|artificial intelligence|foundation|language|large language|machine learning)\s+model|model\b|codex\b|chatgpt\b|gpt(?:[- .]?\d+)?\b|claude\b|gemini\b|gemma\b|llama\b|mistral\b|mixtral\b|qwen\b|deepseek\b|grok\b|phi\b|kimi\b)/iu',
        'code punctuation' => '/[`{}\[\]<>|\\\\]/u',
        'an operator' => '/(?:=>|->|::|\$[a-zA-Z_]|\w\(\)|\+\+|!==?|>=|<=)/u',
        'a file path' => '/(?:(?:^|\s)[~.]?\/[\w.-]+|\/[\w.-]+\/)/u',
        'a file extension' => '/\.(?:php|json|jsonl|js|ts|py|rs|sh|md|log|sqlite|c|h|yml|yaml|toml|html|css|conf|ini)\b/iu',
        'a snake_case identifier' => '/\b[a-z]{2,}_[a-z_]{2,}\b/u',
        'a camelCase identifier' => '/\b[a-z]+[A-Z][a-z]+/u',
        'a hex blob' => '/\b[0-9a-f]{8,}\b/iu',
    ];

    public static function unspeakable(string $text): ?string
    {
        foreach (self::UNSPEAKABLE as $name => $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return sprintf('%s (%s)', $name, trim((string) ($matches[0] ?? '')));
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function speak(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Pet speech text cannot be empty.');
        }
        $unspeakable = self::unspeakable($text);
        if ($unspeakable !== null) {
            throw new RuntimeException(sprintf( 'This line cannot be spoken aloud: it contains %s. Say what it means instead.', $unspeakable ));
        }
        return $this->request('POST', '/speak', json_encode( ['text' => $text], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ));
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?string $body = null): array
    {
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(self::ADDRESS, $errorNumber, $errorMessage, self::TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            throw new RuntimeException(sprintf( 'Pet bridge is unavailable: %s (%d).', $errorMessage, $errorNumber ));
        }

        stream_set_timeout($socket, self::TIMEOUT_SECONDS);
        $payload = $body ?? '';
        $request = sprintf(
            "%s %s HTTP/1.1\r\nHost: %s\r\nConnection: close\r\nContent-Type: application/json\r\nContent-Length: %d\r\n\r\n%s",
            $method,
            $path,
            self::HOST,
            strlen($payload),
            $payload
        );
        try {
            $remaining = $request;
            while ($remaining !== '') {
                $written = fwrite($socket, $remaining);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Pet bridge request could not be written.');
                }
                $remaining = substr($remaining, $written);
            }
            $response = stream_get_contents($socket);
        } finally {
            fclose($socket);
        }

        if (!is_string($response) || $response === '') {
            throw new RuntimeException('Pet bridge returned an empty response.');
        }
        [$headers, $responseBody] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        if (!preg_match('/^HTTP\/1\.[01] (\d{3})\b/', $headers, $matches)) {
            throw new RuntimeException('Pet bridge returned an invalid HTTP response.');
        }
        $status = (int) $matches[1];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Pet bridge returned HTTP %d.', $status));
        }
        try {
            $decoded = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Pet bridge returned invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Pet bridge returned a non-object JSON response.');
        }
        return ['http_status' => $status, 'body' => $decoded];
    }
}
