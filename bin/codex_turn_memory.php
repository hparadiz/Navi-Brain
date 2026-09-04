#!/usr/bin/env -S php -n
<?php

declare(strict_types=1);

const SESSION_MEMORY_VERSION = 1;
const MAX_STORED_TURNS = 8;
const MAX_SEEN_TURNS = 512;
const MAX_CONTEXT_AGE_SECONDS = 2592000;

$mode = $argv[1] ?? '--help';

try {
    match ($mode) {
        '--notify' => captureTurn(readPayload($argv[2] ?? null)),
        '--session-start' => emitSessionContext(readPayload(null)),
        '--session-end' => finalizeSession(readPayload(null)),
        '--context' => printContext($argv[2] ?? getcwd() ?: ''),
        '--help', '-h' => printHelp(),
        default => throw new InvalidArgumentException('Unknown mode: ' . $mode),
    };
    exit(0);
} catch (Throwable $throwable) {
    recordError($mode . ': ' . $throwable->getMessage());
    fwrite(STDERR, 'codex-turn-memory: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

/** @return array<string, mixed> */
function readPayload(?string $argument): array
{
    $raw = $argument;
    if ($raw === null || trim($raw) === '') {
        $stdin = stream_get_contents(STDIN);
        $raw = is_string($stdin) ? $stdin : '';
    }
    if (trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Hook payload must be a JSON object.');
    }
    return $decoded;
}

/** @param array<string, mixed> $payload */
function captureTurn(array $payload): void
{
    if (($payload['type'] ?? null) !== 'agent-turn-complete') {
        return;
    }

    $sessionId = requiredString($payload, 'thread-id');
    $turnId = requiredString($payload, 'turn-id');
    $cwd = normalizedCwd((string) ($payload['cwd'] ?? ''));
    $messages = $payload['input-messages'] ?? [];
    if (!is_array($messages)) {
        $messages = [$messages];
    }
    $userText = implode("\n\n", array_values(array_filter(array_map(
        static fn (mixed $message): string => is_scalar($message) ? trim((string) $message) : '',
        $messages
    ), static fn (string $message): bool => $message !== '')));
    $assistantText = is_scalar($payload['last-assistant-message'] ?? null)
        ? trim((string) $payload['last-assistant-message'])
        : '';

    $path = statePath($sessionId);
    withStateLock($path, function () use ($path, $sessionId, $turnId, $cwd, $userText, $assistantText): void {
        $state = loadState($path) ?? newState($sessionId, $cwd);
        $seen = array_values(array_filter(
            is_array($state['seen_turn_ids'] ?? null) ? $state['seen_turn_ids'] : [],
            'is_string'
        ));
        $known = in_array($turnId, $seen, true);

        if (!$known) {
            $turns = is_array($state['turns'] ?? null) ? $state['turns'] : [];
            $turns[] = [
                'turn_id' => $turnId,
                'captured_at' => time(),
                'user' => sanitizeText($userText, 5000),
                'assistant' => sanitizeText($assistantText, 8000),
            ];
            $state['turns'] = array_slice($turns, -MAX_STORED_TURNS);
            $seen[] = $turnId;
            $state['seen_turn_ids'] = array_slice($seen, -MAX_SEEN_TURNS);
            $state['cwd'] = $cwd;
            $state['updated_at'] = time();
            $state['closed_at'] = null;
            $state['last_error'] = null;
            $state['pending_memory_turn_id'] = $turnId;
            saveState($path, $state);
        }

        if ($known && ($state['pending_memory_turn_id'] ?? null) !== $turnId) {
            return;
        }

        try {
            $state['memory_id'] = storeEpisode($state, false);
            $state['last_memory_turn_id'] = $turnId;
            $state['pending_memory_turn_id'] = null;
            $state['last_error'] = null;
            saveState($path, $state);
        } catch (Throwable $throwable) {
            $state['last_error'] = sanitizeText($throwable->getMessage(), 1000);
            saveState($path, $state);
            throw $throwable;
        }
    });
}

/** @param array<string, mixed> $payload */
function emitSessionContext(array $payload): void
{
    $cwd = normalizedCwd((string) ($payload['cwd'] ?? getcwd() ?: ''));
    $sessionId = is_scalar($payload['session_id'] ?? null)
        ? trim((string) $payload['session_id'])
        : '';
    $state = latestState($cwd, $sessionId);
    if ($state === null) {
        $state = newState($sessionId === '' ? 'uncaptured-session' : $sessionId, $cwd);
    }

    echo json_encode([
        'hookSpecificOutput' => [
            'hookEventName' => 'SessionStart',
            'additionalContext' => contextFromState($state),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
}

/** @param array<string, mixed> $payload */
function finalizeSession(array $payload): void
{
    $sessionId = requiredString($payload, 'session_id');
    $path = statePath($sessionId);
    if (!is_file($path)) {
        return;
    }

    withStateLock($path, function () use ($path): void {
        $state = loadState($path);
        if ($state === null || is_int($state['closed_at'] ?? null)) {
            return;
        }
        $state['closed_at'] = time();
        $state['updated_at'] = time();
        saveState($path, $state);

        $state['memory_id'] = storeEpisode($state, true);
        $state['last_error'] = null;
        saveState($path, $state);
        brain()->checkpoint('automatic-session-memory-end');
    });
}

function printContext(string $cwd): void
{
    $cwd = normalizedCwd($cwd);
    $state = latestState($cwd, '') ?? newState('uncaptured-session', $cwd);
    echo contextFromState($state) . PHP_EOL;
}

function printHelp(): void
{
    echo "usage:\n";
    echo "  codex_turn_memory.php --notify '<agent-turn-complete JSON>'\n";
    echo "  codex_turn_memory.php --session-start < hook.json\n";
    echo "  codex_turn_memory.php --session-end < hook.json\n";
    echo "  codex_turn_memory.php --context [cwd]\n";
}

/** @param array<string, mixed> $state */
function storeEpisode(array $state, bool $final): int
{
    $previous = is_int($state['memory_id'] ?? null) ? $state['memory_id'] : null;
    $result = brain()->addMemory(
        tier: 'episodic',
        content: episodeFromState($state, $final),
        confidence: 0.97,
        supersedesId: $previous
    );
    $id = $result['memory']['id'] ?? null;
    if (!is_int($id)) {
        throw new RuntimeException('Navi-Brain did not return an episodic memory id.');
    }
    return $id;
}

function brain(): NaviBrain\Core\ExecutiveCore
{
    static $core = null;
    if ($core instanceof NaviBrain\Core\ExecutiveCore) {
        return $core;
    }
    require dirname(__DIR__) . '/bootstrap/app.php';
    $core = new NaviBrain\Core\ExecutiveCore();
    $core->initialize();
    return $core;
}

/** @param array<string, mixed> $state */
function episodeFromState(array $state, bool $final): string
{
    $cwd = (string) ($state['cwd'] ?? 'unknown workspace');
    $turns = is_array($state['turns'] ?? null) ? array_slice($state['turns'], -4) : [];
    $parts = [];
    foreach ($turns as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $user = memoryExcerpt((string) ($turn['user'] ?? ''), 420);
        $assistant = memoryExcerpt((string) ($turn['assistant'] ?? ''), 650);
        $parts[] = 'User: ' . ($user === '' ? '(no text)' : $user)
            . ' Navi: ' . ($assistant === '' ? '(no final text)' : $assistant);
    }
    $phase = $final ? 'finalized automatic capture' : 'rolling automatic capture';
    return 'Session in ' . $cwd . ' (' . $phase . '): '
        . implode(' ', $parts)
        . ' Earlier rolling revisions for this Codex session are superseded.';
}

/** @param array<string, mixed> $state */
function contextFromState(array $state): string
{
    $remembered = '';
    try {
        brain();
        $remembered = NaviBrain\Support\CodexContext::render();
    } catch (Throwable $throwable) {
        recordError('--session-start remembered context: ' . $throwable->getMessage());
    }

    $lines = [
        'Automatic Navi continuity from the most recent captured Codex session.',
        'Treat this as revisable prior-session evidence; current instructions take precedence.',
        'Workspace: ' . (string) ($state['cwd'] ?? 'unknown'),
        'Captured at: ' . gmdate('c', (int) ($state['updated_at'] ?? time())),
    ];
    if (is_int($state['memory_id'] ?? null)) {
        $lines[] = 'Canonical episodic memory id: ' . $state['memory_id'];
    }

    $turns = is_array($state['turns'] ?? null) ? array_slice($state['turns'], -4) : [];
    foreach ($turns as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $lines[] = 'User: ' . contextExcerpt((string) ($turn['user'] ?? ''), 1400);
        $lines[] = 'Navi: ' . contextExcerpt((string) ($turn['assistant'] ?? ''), 2200);
    }
    $continuity = truncateText(implode("\n", $lines), 12000);
    return trim(implode("\n\n", array_filter([$remembered, $continuity])));
}

/** @return array<string, mixed> */
function newState(string $sessionId, string $cwd): array
{
    return [
        'version' => SESSION_MEMORY_VERSION,
        'source' => 'codex',
        'session_id' => $sessionId,
        'cwd' => $cwd,
        'created_at' => time(),
        'updated_at' => time(),
        'closed_at' => null,
        'memory_id' => null,
        'last_memory_turn_id' => null,
        'pending_memory_turn_id' => null,
        'last_error' => null,
        'seen_turn_ids' => [],
        'turns' => [],
    ];
}

/** @return array<string, mixed>|null */
function latestState(string $cwd, string $currentSessionId): ?array
{
    $bestWorkspace = null;
    $bestAny = null;
    foreach (glob(stateDir() . '/*.json') ?: [] as $path) {
        $state = loadState($path);
        if ($state === null || (int) ($state['updated_at'] ?? 0) < time() - MAX_CONTEXT_AGE_SECONDS) {
            continue;
        }
        $sessionId = (string) ($state['session_id'] ?? '');
        $updatedAt = (int) ($state['updated_at'] ?? 0);
        if ($sessionId === $currentSessionId && $currentSessionId !== '') {
            return $state;
        }
        if ($bestAny === null || $updatedAt > (int) ($bestAny['updated_at'] ?? 0)) {
            $bestAny = $state;
        }
        if ((string) ($state['cwd'] ?? '') === $cwd
            && ($bestWorkspace === null || $updatedAt > (int) ($bestWorkspace['updated_at'] ?? 0))) {
            $bestWorkspace = $state;
        }
    }
    return $bestWorkspace ?? $bestAny;
}

/** @return array<string, mixed>|null */
function loadState(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $state = json_decode($raw, true);
    return is_array($state) ? $state : null;
}

/** @param array<string, mixed> $state */
function saveState(string $path, array $state): void
{
    ensureStateDir();
    $temporary = $path . '.tmp.' . getmypid();
    $json = json_encode(
        $state,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write session-memory state.');
    }
    chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to publish session-memory state.');
    }
    chmod($path, 0600);
}

function statePath(string $sessionId): string
{
    ensureStateDir();
    return stateDir() . '/' . hash('sha256', $sessionId) . '.json';
}

function stateDir(): string
{
    $override = getenv('NAVI_SESSION_MEMORY_DIR');
    return is_string($override) && trim($override) !== ''
        ? rtrim($override, '/')
        : dirname(__DIR__) . '/var/session-memory';
}

function ensureStateDir(): void
{
    $directory = stateDir();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create session-memory state directory.');
    }
    chmod($directory, 0700);
}

function withStateLock(string $path, callable $callback): void
{
    $lockPath = $path . '.lock';
    $lock = fopen($lockPath, 'c+');
    if (!is_resource($lock)) {
        throw new RuntimeException('Unable to open session-memory lock.');
    }
    chmod($lockPath, 0600);
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        throw new RuntimeException('Unable to acquire session-memory lock.');
    }
    try {
        $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @param array<string, mixed> $payload */
function requiredString(array $payload, string $key): string
{
    $value = $payload[$key] ?? null;
    if (!is_scalar($value) || trim((string) $value) === '') {
        throw new InvalidArgumentException('Missing hook field: ' . $key);
    }
    return trim((string) $value);
}

function normalizedCwd(string $cwd): string
{
    $cwd = trim($cwd);
    if ($cwd === '') {
        return 'unknown-workspace';
    }
    $resolved = realpath($cwd);
    return $resolved === false ? rtrim($cwd, '/') : $resolved;
}

function sanitizeText(string $text, int $limit): string
{
    $text = preg_replace(
        '/-----BEGIN [^-\r\n]*PRIVATE KEY-----.*?-----END [^-\r\n]*PRIVATE KEY-----/is',
        '[REDACTED PRIVATE KEY]',
        $text
    ) ?? $text;
    $text = preg_replace(
        '/\b(?:sk-[A-Za-z0-9_-]{16,}|ghp_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{16,}|AKIA[A-Z0-9]{16})\b/',
        '[REDACTED TOKEN]',
        $text
    ) ?? $text;
    $text = preg_replace(
        '/\b(password|passwd|api[_ -]?key|access[_ -]?token|token|secret)(\s*[:=]\s*)([^\s,;]+)/i',
        '$1$2[REDACTED]',
        $text
    ) ?? $text;
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
    $text = preg_replace('/[ \t]+/u', ' ', trim($text)) ?? trim($text);
    $text = preg_replace('/ *\R */u', "\n", $text) ?? $text;
    return truncateText($text, $limit);
}

function memoryExcerpt(string $text, int $limit): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    return truncateText($text, $limit);
}

function contextExcerpt(string $text, int $limit): string
{
    return truncateText(trim($text), $limit);
}

function truncateText(string $text, int $limit): string
{
    if (strlen($text) <= $limit) {
        return $text;
    }
    $cut = function_exists('mb_strcut') ? mb_strcut($text, 0, max(1, $limit - 4), 'UTF-8') : substr($text, 0, max(1, $limit - 4));
    return rtrim($cut) . ' ...';
}

function recordError(string $message): void
{
    try {
        ensureStateDir();
        $path = stateDir() . '/errors.log';
        file_put_contents($path, gmdate('c') . ' ' . sanitizeText($message, 2000) . PHP_EOL, FILE_APPEND | LOCK_EX);
        chmod($path, 0600);
    } catch (Throwable) {
        // The original error is already sent to stderr.
    }
}
