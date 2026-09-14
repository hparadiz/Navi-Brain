<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

class CommandSense
{
    public const PROTOCOL = 'navi-observation-v1';
    private const SOCKET_PATH = '/run/navi-senses/navi-senses.sock';

    private const TIMEOUT_SECONDS = 15;
    private const MAX_RESPONSE_BYTES = 65536;

    private readonly string $socketPath;

    public function __construct(string $socketPath = self::SOCKET_PATH)
    {
        $this->socketPath = $socketPath;
    }

    /** @return array<string, string> */
    public static function operations(): array
    {
        return [
            'system.summary' => 'Bounded uptime, load, memory and kernel facts.',
            'system.uptime' => 'Kernel uptime and idle time from /proc/uptime.',
            'system.load' => 'Load averages and runnable task counts from /proc/loadavg.',
            'system.memory' => 'Memory counters from /proc/meminfo.',
            'system.cpu' => 'Bounded processor information from /proc/cpuinfo.',
            'system.kernel' => 'Kernel version from /proc/version.',
        ];
    }

    public static function resolveOperation(string $command): ?string
    {
        $command = trim($command);
        if (isset(self::operations()[$command])) {
            return $command;
        }

        return match ($command) {
            'cat /proc/uptime' => 'system.uptime',
            'cat /proc/loadavg' => 'system.load',
            'cat /proc/meminfo' => 'system.memory',
            'cat /proc/cpuinfo' => 'system.cpu',
            'cat /proc/version' => 'system.kernel',
            default => null,
        };
    }

    public function available(): bool
    {
        if (!file_exists($this->socketPath)) {
            return false;
        }
        $socket = @stream_socket_client('unix://' . $this->socketPath, $number, $message, 2);
        if (!is_resource($socket)) {
            return false;
        }
        fclose($socket);
        return true;
    }

    /** @return array<string, mixed> */
    public function observe(string $command): array
    {
        $command = trim($command);
        $operation = self::resolveOperation($command);
        if ($operation === null) {
            return $this->unavailable($command, 'Unsupported observation. Choose one of: ' . implode(', ', array_keys(self::operations())) . '. Arbitrary commands and paths are not supported.');
        }

        $socket = @stream_socket_client('unix://' . $this->socketPath, $errorNumber, $errorMessage, self::TIMEOUT_SECONDS);
        if (!is_resource($socket)) {
            return $this->unavailable($command, sprintf( 'the looking service is not reachable: %s', $errorMessage === '' ? 'no socket at ' . $this->socketPath : $errorMessage ));
        }

        try {
            stream_set_timeout($socket, self::TIMEOUT_SECONDS);
            $payload = json_encode([ 'protocol' => self::PROTOCOL, 'operation' => $operation, ], JSON_UNESCAPED_SLASHES) . "\n";
            if (@fwrite($socket, $payload) !== strlen($payload)) {
                return $this->unavailable($command, 'the looking service closed the connection');
            }
            $response = (string) @stream_get_line($socket, self::MAX_RESPONSE_BYTES, "\n");
        } finally {
            @fclose($socket);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return $this->unavailable($command, 'the looking service returned something unreadable');
        }
        if (($decoded['protocol'] ?? null) !== self::PROTOCOL
            || ($decoded['operation'] ?? null) !== $operation
            || ($decoded['effect_contract'] ?? null) !== 'fixed_kernel_files_v1'
        ) {
            return $this->unavailable($command, 'The looking service does not confirm the fixed-file observation contract.');
        }
        $decoded['command'] = $command;
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function unavailable(string $command, string $because): array
    {
        return [
            'command' => $command,
            'ran' => false,
            'refused' => true,
            'refused_because' => $because,
            'exit_code' => null,
            'timed_out' => false,
            'seconds' => 0.0,
            'output' => '',
            'error_output' => '',
            'output_digest' => hash('sha256', 'unavailable:' . $because),
            'output_bytes' => 0,
        ];
    }
}
