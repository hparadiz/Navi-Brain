<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use RuntimeException;

/**
 * Looking at the machine, as a sense.
 *
 * Navi can inspect the machine on Navi's own initiative rather than waiting for
 * a sampler someone wrote in advance. The result is a reading like any other:
 * it goes through the cortex, senses fire on it, and an unchanged result stops
 * being interesting exactly the way a repeated sensor value does.
 *
 * Nothing is executed here. This class opens a unix socket and asks the
 * navi-senses service, which runs as its own account holding no write
 * permission, to look at something and say what it saw. The privilege boundary
 * is the process boundary: the brain cannot run a command even if it wanted to,
 * because the code that runs commands lives on the other side of the socket in
 * a process that cannot write anything.
 *
 * That arrangement is why there is no list of forbidden commands in this file.
 * A list has to anticipate every spelling of a destructive command and is wrong
 * the first time it misses one. An account without the permission simply fails,
 * and the failure is itself a reading worth having.
 *
 * The authority split the codebase already runs on lines up exactly: a source
 * is the user's to grant and carries an effect ceiling, while senses over it
 * are Navi's to invent, and inventing one "changes what Navi notices; it can
 * never change what Navi can reach".
 */
final class CommandSense
{
    private const SOCKET_PATH = '/run/navi-senses/navi-senses.sock';
    /** Generous: the service enforces its own shorter ceiling per command. */
    private const TIMEOUT_SECONDS = 15;
    private const MAX_RESPONSE_BYTES = 65536;

    public function __construct(private readonly string $socketPath = self::SOCKET_PATH)
    {
    }

    /** Is the looking service reachable right now? */
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

    /**
     * Look at something and return what was seen.
     *
     * Always returns a reading, including when the service is down or refused.
     * A look that could not happen is an observation about the machine, and
     * swallowing it as an exception would hide it from perception entirely.
     *
     * @return array<string, mixed>
     */
    public function observe(string $command): array
    {
        $command = trim($command);
        if ($command === '') {
            return $this->unavailable('', 'an empty command');
        }

        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errorNumber,
            $errorMessage,
            self::TIMEOUT_SECONDS
        );
        if (!is_resource($socket)) {
            return $this->unavailable($command, sprintf(
                'the looking service is not reachable: %s',
                $errorMessage === '' ? 'no socket at ' . $this->socketPath : $errorMessage
            ));
        }

        try {
            stream_set_timeout($socket, self::TIMEOUT_SECONDS);
            $payload = json_encode(['command' => $command], JSON_UNESCAPED_SLASHES) . "\n";
            if (@fwrite($socket, $payload) === false) {
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
