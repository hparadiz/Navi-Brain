<?php

declare(strict_types=1);

namespace NaviBrain\Mcp;

use InvalidArgumentException;
use JsonException;
use NaviBrain\Core\ExecutiveCore;
use NaviBrain\Perception\DesktopAwareness;
use Throwable;

final class Server
{
    private const VERSION = '0.4.0';

    private ExecutiveCore $core;
    private DesktopAwareness $desktop;

    public function run(): int
    {
        try {
            $this->core = new ExecutiveCore();
            $this->core->initialize();
            $this->desktop = new DesktopAwareness();
        } catch (Throwable $throwable) {
            fwrite(STDERR, 'Navi-Brain startup failed: ' . $throwable->getMessage() . PHP_EOL);
            return 1;
        }

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($message)) {
                    throw new JsonException('The message must be a JSON object.');
                }
                $this->handle($message);
            } catch (JsonException $exception) {
                $this->sendError(null, -32700, 'Parse error: ' . $exception->getMessage());
            } catch (Throwable $throwable) {
                $this->sendError(null, -32603, 'Internal error: ' . $throwable->getMessage());
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $message */
    private function handle(array $message): void
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;

        if (!is_string($method) || $method === '') {
            if (array_key_exists('id', $message)) {
                $this->sendError($id, -32600, 'Invalid Request');
            }
            return;
        }

        if ($method === 'initialize') {
            $params = is_array($message['params'] ?? null) ? $message['params'] : [];
            $protocolVersion = is_string($params['protocolVersion'] ?? null)
                ? $params['protocolVersion']
                : '2025-11-25';

            $this->sendResult($id, [
                'protocolVersion' => $protocolVersion,
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'Navi-Brain', 'version' => self::VERSION],
                'instructions' => 'Use Navi-Brain for durable, evidence-backed memory. Read before writing. Treat self-model facts as revisable observations, never privileged introspection.',
            ]);
            return;
        }

        if ($method === 'ping') {
            $this->sendResult($id, []);
            return;
        }

        if ($method === 'tools/list') {
            $this->sendResult($id, ['tools' => $this->tools()]);
            return;
        }

        if ($method === 'tools/call') {
            if (!array_key_exists('id', $message)) {
                return;
            }
            $params = is_array($message['params'] ?? null) ? $message['params'] : [];
            $this->callTool($id, $params);
            return;
        }

        if (array_key_exists('id', $message)) {
            $this->sendError($id, -32601, 'Method not found: ' . $method);
        }
    }

    /** @param array<string, mixed> $params */
    private function callTool(mixed $id, array $params): void
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            $this->sendError($id, -32602, 'Tool name is required.');
            return;
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            $this->sendError($id, -32602, 'Tool arguments must be an object.');
            return;
        }

        try {
            if ($name === 'desktop_look') {
                $capture = $this->desktop->look(
                    $this->requiredString($arguments, 'reason'),
                    $this->requiredChoice($arguments, 'purpose', ['user_request', 'task', 'curiosity']),
                    $this->optionalChoice(
                        $arguments,
                        'scope',
                        ['active_window', 'current_screen', 'desktop'],
                        'active_window'
                    )
                );
                if ($capture['image_base64'] !== null) {
                    $this->sendImageToolResult($id, $capture['metadata'], $capture['image_base64']);
                } else {
                    $this->sendToolResult($id, ['result' => $capture['metadata']]);
                }
                return;
            }

            $result = match ($name) {
                'desktop_presence' => $this->desktop->presence(),
                'brain_status' => $this->core->status(),
                'brain_recall' => $this->core->searchMemory(
                    $this->requiredString($arguments, 'query'),
                    $this->optionalInteger($arguments, 'limit', 20, 1, 100)
                ),
                'brain_self_model' => $this->core->listSelfModelFacts(),
                'brain_needs' => $this->core->listNeeds(),
                'brain_stimulate' => $this->core->satisfyNeed(
                    $this->requiredString($arguments, 'need'),
                    $this->requiredNumber($arguments, 'amount'),
                    $this->requiredString($arguments, 'source')
                ),
                'brain_daydream' => $this->core->daydream(
                    $this->requiredString($arguments, 'reason')
                ),
                'brain_sleep' => $this->core->sleep(
                    $this->requiredString($arguments, 'reason')
                ),
                'brain_heartbeat_status' => $this->core->heartbeatStatus(),
                'brain_thoughts' => $this->core->listThoughtArtifacts(
                    $this->optionalString($arguments, 'status')
                ),
                'brain_remember_self' => $this->core->setSelfModelFact(
                    $this->requiredString($arguments, 'key'),
                    $this->requiredString($arguments, 'value'),
                    $this->requiredNumber($arguments, 'confidence'),
                    $this->requiredString($arguments, 'evidence')
                ),
                'brain_checkpoint' => $this->core->checkpoint(
                    $this->requiredString($arguments, 'reason')
                ),
                default => throw new InvalidArgumentException('Unknown tool: ' . $name),
            };

            $this->sendToolResult($id, ['result' => $result]);
        } catch (InvalidArgumentException $exception) {
            $this->sendError($id, -32602, $exception->getMessage());
        } catch (Throwable $throwable) {
            $this->sendToolResult($id, ['error' => $throwable->getMessage()], true);
        }
    }

    /** @return list<array<string, mixed>> */
    private function tools(): array
    {
        $readOnly = [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
        $additiveWrite = [
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ];

        return [
            [
                'name' => 'desktop_presence',
                'title' => 'Read Desktop Presence',
                'description' => 'Read the event-driven KDE presence state: active means input occurred within the last two minutes; away means it did not. Use this before visual curiosity. It captures no pixels, reads no input events, and performs no polling.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'desktop_look',
                'title' => 'Look at the Desktop',
                'description' => 'Capture KDE desktop pixels for local-only vision and OCR judgement when visual evidence is necessary. Task and curiosity calls return only a privacy-filtered structured observation. Raw pixels are returned only for a direct user_request that the local guard classifies as entirely safe. Prefer active_window, widen scope only when required, and never poll. Curiosity is refused while the user is away and rate-limited to one capture per 15 minutes. Temporary pixels are deleted after local processing.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                        'purpose' => [
                            'type' => 'string',
                            'enum' => ['user_request', 'task', 'curiosity'],
                        ],
                        'scope' => [
                            'type' => 'string',
                            'enum' => ['active_window', 'current_screen', 'desktop'],
                            'default' => 'active_window',
                        ],
                    ],
                    'required' => ['reason', 'purpose'],
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_status',
                'title' => 'Read Navi-Brain Status',
                'description' => 'Read active intentions, pending actions, discrepancies, working and semantic memory, self-model facts, and top appraisals.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_recall',
                'title' => 'Recall Durable Memory',
                'description' => 'Search active working, semantic, episodic, and procedural memory for task-relevant context.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'minLength' => 1],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_self_model',
                'title' => 'Read Navi Self Model',
                'description' => 'Read current evidence-backed and revisable facts about Navi capabilities, tools, permissions, limits, and recurring failure modes.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_needs',
                'title' => 'Read Navi Needs',
                'description' => 'Read persistent need pressure, growth, trigger thresholds, and current boredom or curiosity activation.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_stimulate',
                'title' => 'Satisfy a Cognitive Need',
                'description' => 'Record meaningful cognitive activity that partially satisfies one named need.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'need' => ['type' => 'string', 'minLength' => 1],
                        'amount' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'source' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['need', 'amount', 'source'],
                ],
                'annotations' => $additiveWrite,
            ],
            [
                'name' => 'brain_daydream',
                'title' => 'Open a Sandboxed Daydream',
                'description' => 'Create one unverified, provenance-marked wandering thought artifact without granting external action or factual-memory status.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    ],
                    'required' => ['reason'],
                ],
                'annotations' => $additiveWrite,
            ],
            [
                'name' => 'brain_sleep',
                'title' => 'Run a Cognitive Sleep Cycle',
                'description' => 'Run checkpointed Non-REM maintenance and REM repair/daydream proposal generation; synthetic artifacts remain outside factual memory.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    ],
                    'required' => ['reason'],
                ],
                'annotations' => $additiveWrite,
            ],
            [
                'name' => 'brain_heartbeat_status',
                'title' => 'Read Chronic Heartbeat Status',
                'description' => 'Read low- and high-brain rhythms, due times, running wake moments, and queued or leased worker cognition.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_thoughts',
                'title' => 'Read Thought Artifacts',
                'description' => 'Read provenance-marked observed, inferred, counterfactual, daydream, or worker artifacts without promoting them to memory.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'enum' => ['proposed', 'accepted', 'rejected', 'expired'],
                        ],
                    ],
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_remember_self',
                'title' => 'Remember Self Observation',
                'description' => 'Store or revise one self-model fact backed by an observed result. Do not use for feelings, hidden-state claims, wishes, or unsupported identity narration.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                        'value' => ['type' => 'string', 'minLength' => 1],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'evidence' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['key', 'value', 'confidence', 'evidence'],
                ],
                'annotations' => $additiveWrite,
            ],
            [
                'name' => 'brain_checkpoint',
                'title' => 'Checkpoint Navi-Brain',
                'description' => 'Capture the current executive state before context pressure, interruption, or task handoff.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 96],
                    ],
                    'required' => ['reason'],
                ],
                'annotations' => $additiveWrite,
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function requiredString(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($name . ' must be a non-empty string.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $arguments */
    private function optionalString(array $arguments, string $name): ?string
    {
        if (!array_key_exists($name, $arguments)) {
            return null;
        }
        $value = $arguments[$name];
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($name . ' must be non-empty text when provided.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $arguments */
    private function requiredNumber(array $arguments, string $name): float
    {
        $value = $arguments[$name] ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException($name . ' must be a number.');
        }
        $number = (float) $value;
        if ($number < 0.0 || $number > 1.0) {
            throw new InvalidArgumentException($name . ' must be between 0 and 1.');
        }
        return $number;
    }

    /** @param array<string, mixed> $arguments */
    private function optionalInteger(
        array $arguments,
        string $name,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        if (!array_key_exists($name, $arguments)) {
            return $default;
        }
        $value = $arguments[$name];
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf(
                '%s must be an integer between %d and %d.',
                $name,
                $minimum,
                $maximum
            ));
        }
        return $value;
    }

    /** @param list<string> $choices */
    private function requiredChoice(array $arguments, string $name, array $choices): string
    {
        $value = $this->requiredString($arguments, $name);
        if (!in_array($value, $choices, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s must be one of: %s.',
                $name,
                implode(', ', $choices)
            ));
        }
        return $value;
    }

    /** @param list<string> $choices */
    private function optionalChoice(
        array $arguments,
        string $name,
        array $choices,
        string $default
    ): string {
        if (!array_key_exists($name, $arguments)) {
            return $default;
        }
        return $this->requiredChoice($arguments, $name, $choices);
    }

    /** @param array<string, mixed> $metadata */
    private function sendImageToolResult(mixed $id, array $metadata, string $imageBase64): void
    {
        $payload = ['result' => $metadata];
        $text = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $this->sendResult($id, [
            'content' => [
                ['type' => 'text', 'text' => $text],
                ['type' => 'image', 'data' => $imageBase64, 'mimeType' => 'image/png'],
            ],
            'structuredContent' => $payload,
            'isError' => false,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function sendToolResult(mixed $id, array $payload, bool $isError = false): void
    {
        $text = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $this->sendResult($id, [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $payload,
            'isError' => $isError,
        ]);
    }

    /** @param array<string, mixed> $result */
    private function sendResult(mixed $id, array $result): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function sendError(mixed $id, int $code, string $message): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        fwrite(STDOUT, json_encode(
            $message,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL);
        fflush(STDOUT);
    }
}
