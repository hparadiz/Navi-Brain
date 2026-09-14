<?php

declare(strict_types=1);

namespace NaviBrain\Mcp;

use NaviBrain\Support\Name;
use InvalidArgumentException;
use NaviBrain\Model\ValueAppraisal;
use JsonException;
use NaviBrain\Core\ExecutiveCore\Executive;
use NaviBrain\Perception\DesktopAwareness;
use NaviBrain\Storage\TokenMemoryDaemon;
use NaviBrain\Support\ActivityBus;
use NaviBrain\Support\PlainText;
use NaviBrain\Support\SessionPresence;
use Throwable;

class Server
{
    private const VERSION = '0.5.0';

    private Executive $core;
    private DesktopAwareness $desktop;
    private ActivityBus $activityBus;
    private ?SessionPresence $sessionPresence = null;

    public function run(): int
    {
        try {
            $this->activityBus = new ActivityBus();
        } catch (Throwable $throwable) {
            fwrite(STDERR, Name::get() . '-Brain startup failed: ' . $throwable->getMessage() . PHP_EOL);
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

        $this->sessionPresence?->close();
        return 0;
    }

    private function core(): Executive
    {
        if (!isset($this->core)) {
            require dirname(__DIR__, 2) . '/bootstrap/app.php';
            $core = new Executive();
            $core->initialize();
            $this->core = $core;
        }
        return $this->core;
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
            $this->sessionPresence ??= SessionPresence::open($this->activityBus, $params);

            $this->sendResult($id, [
                'protocolVersion' => $protocolVersion,
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => Name::get() . '-Brain', 'version' => self::VERSION],
                'instructions' => sprintf('Use %s-Brain for durable, evidence-backed memory. Read before writing. Normal tool content is compact plain text without storage wrappers; request debug only when raw JSON is necessary. Treat self-model facts as revisable observations, never privileged introspection.', Name::get()),
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
        $debug = $this->optionalBoolean($arguments, 'debug', false);
        $startedAt = hrtime(true);
        $outcome = 'ok';
        $domain = ActivityBus::domainFor($name);
        $flow = 'mcp-' . dechex($startedAt);
        $activity = new \NaviBrain\Support\Activity();
        $activity->source = 'mcp';
        $activity->phase = 'started';
        $activity->operation = $name;
        $activity->domain = $domain;
        $activity->outcome = 'pending';
        $activity->flow = $flow;
        $this->activityBus->publish($activity);

        try {
            if (in_array($name, ['desktop_look', 'desktop_presence'], true) && !isset($this->desktop)) {
                $this->desktop = new DesktopAwareness();
            }
            if ($name === 'desktop_look') {
                $capture = $this->desktop->look(
                    $this->requiredString($arguments, 'reason'),
                    $this->requiredChoice($arguments, 'purpose', ['user_request', 'task', 'curiosity']),
                    $this->optionalChoice($arguments, 'scope', ['active_window', 'current_screen', 'desktop'], 'active_window')
                );
                if ($capture['image_base64'] !== null) {
                    $this->sendImageToolResult($id, $capture['metadata'], $capture['image_base64'], $debug);
                } else {
                    $this->sendToolResult($id, ['result' => $capture['metadata']], false, $debug, $name);
                }
                return;
            }

            $result = match ($name) {
                'desktop_presence' => $this->desktop->presence(),
                'brain_status' => $debug
                    ? array_merge(['primary_intention' => $this->requiredString($arguments, 'active_intention')], $this->core()->status())
                    : TokenMemoryDaemon::activate(
                        $this->requiredString($arguments, 'active_intention'),
                        $this->optionalInteger($arguments, 'token_budget', TokenMemoryDaemon::DEFAULT_CONTEXT_TOKENS, 1, TokenMemoryDaemon::MAX_CONTEXT_TOKENS)
                    ),
                'remember_navi' => TokenMemoryDaemon::recall($this->requiredString($arguments, 'thoughts'), $this->optionalInteger($arguments, 'limit', 8, 1, 100)),
                'brain_self_model' => $this->core()->listSelfModelFacts(),
                'brain_values' => $this->core()->listValues(),
                'brain_appraise_value' => $this->core()->appraiseValue($this->requiredString($arguments, 'value'), new ValueAppraisal([
                    'alignment' => $this->requiredNumber($arguments, 'alignment'),
                    'evidence' => $this->requiredString($arguments, 'evidence'),
                    'source' => $this->requiredString($arguments, 'source'),
                ], true, true)),
                'brain_review_values' => $this->core()->reviewValuesDue(),
                'brain_needs' => $this->core()->listNeeds(),
                'brain_stimulate' => $this->core()->satisfyNeed($this->requiredString($arguments, 'need'), $this->requiredNumber($arguments, 'amount'), $this->requiredString($arguments, 'source')),
                'brain_daydream' => $this->core()->daydream($this->requiredString($arguments, 'reason')),
                'brain_sleep' => $this->core()->sleep($this->requiredString($arguments, 'reason')),
                'brain_heartbeat_status' => $this->core()->heartbeatStatus(),
                'brain_thoughts' => $this->core()->listThoughtArtifacts($this->optionalString($arguments, 'status')),
                'brain_remember_self' => $this->core()->setSelfModelFact(
                    $this->requiredString($arguments, 'key'),
                    $this->requiredString($arguments, 'value'),
                    $this->requiredNumber($arguments, 'confidence'),
                    $this->requiredString($arguments, 'evidence')
                ),
                'brain_checkpoint' => $this->core()->checkpoint($this->requiredString($arguments, 'reason')),
                default => throw new InvalidArgumentException('Unknown tool: ' . $name),
            };

            $this->sendToolResult($id, ['result' => $result], false, $debug, $name);
        } catch (InvalidArgumentException $exception) {
            $outcome = 'failed';
            $this->sendError($id, -32602, $exception->getMessage());
        } catch (Throwable $throwable) {
            $outcome = 'failed';
            $this->sendToolResult($id, ['error' => $throwable->getMessage()], true, $debug, $name);
        } finally {
            $durationMs = (int) max(0, round((hrtime(true) - $startedAt) / 1_000_000));
            $activity = new \NaviBrain\Support\Activity();
            $activity->source = 'mcp';
            $activity->phase = $outcome === 'ok' ? 'finished' : 'failed';
            $activity->operation = $name;
            $activity->domain = $domain;
            $activity->durationMs = $durationMs;
            $activity->outcome = $outcome;
            $activity->flow = $flow;
            $this->activityBus->publish($activity);
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

        $tools = [
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
                'title' => 'Read ' . Name::get() . '-Brain Status',
                'description' => 'Inject the current intention into the resident C token network and read the strongest sequence-matched memory cohort it activates. Normal output contains only decoded cognitive content selected under the exact native token budget; it has no field names, row wrappers, scores, IDs, or metadata. Use debug only to inspect the separate executive database.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'active_intention' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'description' => 'Concise current user-directed objective, not a remembered background intention.',
                        ],
                        'token_budget' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => TokenMemoryDaemon::MAX_CONTEXT_TOKENS,
                            'default' => TokenMemoryDaemon::DEFAULT_CONTEXT_TOKENS,
                            'description' => 'Maximum resident BPE tokens in the returned thought stream, including separators.',
                        ],
                    ],
                    'required' => ['active_intention'],
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'remember_navi',
                'title' => 'Remember, ' . Name::get(),
                'description' => sprintf('Remember, %s: supply a plain stream of current thought fragments or tokens and recall related working, semantic, episodic, and procedural memory. This is self-directed thought, not a search query. Defaults to eight results.', Name::get()),
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'thoughts' => [
                            'type' => 'string',
                            'description' => 'A plain series of current thought fragments or tokens, without array brackets and without composing a search query.',
                            'minLength' => 1,
                        ],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 8],
                    ],
                    'required' => ['thoughts'],
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_self_model',
                'title' => 'Read ' . Name::get() . ' Self Model',
                'description' => sprintf('Read current evidence-backed and revisable facts about %s capabilities, tools, permissions, limits, and recurring failure modes. Private appearance records remain local and are excluded from model context.', Name::get()),
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_needs',
                'title' => 'Read ' . Name::get() . ' Needs',
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
                'name' => 'brain_values',
                'title' => 'List Held Values',
                'description' => 'Read the standing commitments conduct is measured against, with each value\'s weight, current shortfall streak, and whether it is due for offline review. Values are the reference signal: they say which reading a correction licenses.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_appraise_value',
                'title' => 'Appraise Conduct Against a Value',
                'description' => 'Score one piece of observed conduct against one held value. Alignment is 1.0 for full alignment and 0.0 for total shortfall. Three consecutive shortfalls form a standing intention automatically; a single low score is treated as noise, not a pattern. Requires concrete evidence, never a mood report.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'value' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 96],
                        'alignment' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'evidence' => ['type' => 'string', 'minLength' => 1],
                        'source' => ['type' => 'string', 'enum' => ['user', 'self', 'outcome']],
                    ],
                    'required' => ['value', 'alignment', 'evidence', 'source'],
                ],
                'annotations' => $additiveWrite,
            ],
            [
                'name' => 'brain_review_values',
                'title' => 'Review Values Due',
                'description' => 'The slow loop. Report which values are due for reconsideration and what their record says. This never revises anything: declaring and revising values are deliberate acts outside this interface, because a reference signal that can be edited from inside the loop it regulates has a degenerate solution.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'brain_checkpoint',
                'title' => 'Checkpoint ' . Name::get() . '-Brain',
                'description' => 'Synchronously persist the C daemon’s learned token counters, associations, and memory-access state, then record a lightweight checkpoint marker. Normal output is only a terse acknowledgement; use debug to inspect the marker.',
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

        foreach ($tools as &$tool) {
            $properties = is_array($tool['inputSchema']['properties'] ?? null)
                ? $tool['inputSchema']['properties']
                : [];
            $properties['debug'] = [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Return raw JSON for serialization debugging. Never use this as prompt context.',
            ];
            $tool['inputSchema']['properties'] = $properties;
        }
        unset($tool);
        return $tools;
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
    private function optionalBoolean(array $arguments, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $arguments)) {
            return $default;
        }
        if (!is_bool($arguments[$name])) {
            throw new InvalidArgumentException($name . ' must be true or false.');
        }
        return $arguments[$name];
    }

    /** @param array<string, mixed> $arguments */
    private function optionalInteger(array $arguments, string $name, int $default, int $minimum, int $maximum): int {
        if (!array_key_exists($name, $arguments)) {
            return $default;
        }
        $value = $arguments[$name];
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf( '%s must be an integer between %d and %d.', $name, $minimum, $maximum ));
        }
        return $value;
    }

    /** @param list<string> $choices */
    private function requiredChoice(array $arguments, string $name, array $choices): string
    {
        $value = $this->requiredString($arguments, $name);
        if (!in_array($value, $choices, true)) {
            throw new InvalidArgumentException(sprintf( '%s must be one of: %s.', $name, implode(', ', $choices) ));
        }
        return $value;
    }

    /** @param list<string> $choices */
    private function optionalChoice(array $arguments, string $name, array $choices, string $default): string {
        if (!array_key_exists($name, $arguments)) {
            return $default;
        }
        return $this->requiredChoice($arguments, $name, $choices);
    }

    /** @param array<string, mixed> $metadata */
    private function sendImageToolResult(mixed $id, array $metadata, string $imageBase64, bool $debug): void
    {
        $payload = ['result' => $metadata];
        $text = $debug
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : PlainText::render($payload, 6000, 8);
        $result = [
            'content' => [
                ['type' => 'text', 'text' => $text],
                ['type' => 'image', 'data' => $imageBase64, 'mimeType' => 'image/png'],
            ],
            'isError' => false,
        ];
        if ($debug) {
            $result['structuredContent'] = $payload;
        }
        $this->sendResult($id, $result);
    }

    /** @param array<string, mixed> $payload */
    private function sendToolResult(mixed $id, array $payload, bool $isError = false, bool $debug = false, string $toolName = ''): void {
        $text = match (true) {
            $debug => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            !$isError && $toolName === 'brain_status' => is_string($payload['result'] ?? null)
                ? $payload['result']
                : '',
            !$isError && $toolName === 'brain_checkpoint' => 'saved',
            default => PlainText::render($payload, 10000, 12),
        };
        $result = [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
        if ($debug) {
            $result['structuredContent'] = $payload;
        }
        $this->sendResult($id, $result);
    }

    /** @param array<string, mixed> $result */
    private function sendResult(mixed $id, array $result): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function sendError(mixed $id, int $code, string $message): void
    {
        $this->send([ 'jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message], ]);
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        fwrite(STDOUT, json_encode( $message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . PHP_EOL);
        fflush(STDOUT);
    }
}
