#!/usr/bin/env -S php -n
<?php

declare(strict_types=1);

const SESSION_MEMORY_VERSION = 1;
const MAX_STORED_TURNS = 8;
const MAX_CONTEXT_AGE_SECONDS = 2592000;
const TRANSCRIPT_TAIL_LINES = 80;

$mode = $argv[1] ?? '--help';

try {
    match ($mode) {
        '--session-start' => emitSessionContext(readPayload(null)),
        '--stop' => captureTurn(readPayload(null)),
        '--session-end' => finalizeSession(readPayload(null)),
        '--context' => printContext($argv[2] ?? getcwd() ?: ''),
        '--help', '-h' => printHelp(),
        default => throw new InvalidArgumentException('Unknown mode: ' . $mode),
    };
    exit(0);
} catch (Throwable $throwable) {
    recordError($mode . ': ' . $throwable->getMessage());
    fwrite(STDERR, 'claude-turn-memory: ' . $throwable->getMessage() . PHP_EOL);
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

/**
 * Stop fires each time Claude finishes responding. Pull the last real user
 * prompt and the last final assistant text block from the session transcript
 * and roll them into an automatic episodic memory, superseding the previous
 * rolling capture for this session.
 *
 * @param array<string, mixed> $payload
 */
function captureTurn(array $payload): void
{
    $sessionId = requiredString($payload, 'session_id');
    $cwd = normalizedCwd((string) ($payload['cwd'] ?? ''));
    $transcriptPath = is_scalar($payload['transcript_path'] ?? null)
        ? trim((string) $payload['transcript_path'])
        : '';

    [$turnId, $userText, $assistantText] = $transcriptPath !== '' && is_file($transcriptPath)
        ? lastTurnFromTranscript($transcriptPath)
        : [null, '', ''];

    if ($turnId === null) {
        return;
    }

    $path = statePath($sessionId);
    withStateLock($path, function () use ($path, $sessionId, $cwd, $turnId, $userText, $assistantText): void {
        $state = loadState($path) ?? newState($sessionId, $cwd);
        $seen = is_string($state['last_captured_turn_id'] ?? null) ? $state['last_captured_turn_id'] : null;
        if ($seen === $turnId) {
            return;
        }

        $turns = is_array($state['turns'] ?? null) ? $state['turns'] : [];
        $turns[] = [
            'turn_id' => $turnId,
            'captured_at' => time(),
            'user' => sanitizeText($userText, 5000),
            'assistant' => sanitizeText($assistantText, 8000),
        ];
        $state['turns'] = array_slice($turns, -MAX_STORED_TURNS);
        $state['last_captured_turn_id'] = $turnId;
        $state['cwd'] = $cwd;
        $state['updated_at'] = time();
        $state['closed_at'] = null;

        try {
            $previous = is_int($state['memory_id'] ?? null) ? $state['memory_id'] : null;
            $state['memory_id'] = storeEpisode($state, false, $previous);
            $state['last_error'] = null;
        } catch (Throwable $throwable) {
            $state['last_error'] = sanitizeText($throwable->getMessage(), 1000);
            saveState($path, $state);
            throw $throwable;
        }
        saveState($path, $state);
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

        $previous = is_int($state['memory_id'] ?? null) ? $state['memory_id'] : null;
        $state['memory_id'] = storeEpisode($state, true, $previous);
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
    echo "  claude_turn_memory.php --session-start < hook.json\n";
    echo "  claude_turn_memory.php --stop < hook.json\n";
    echo "  claude_turn_memory.php --session-end < hook.json\n";
    echo "  claude_turn_memory.php --context [cwd]\n";
}

/**
 * Read the tail of a Claude Code session transcript (JSONL) and return the
 * most recent real user prompt paired with the most recent final assistant
 * text reply. Tool-call and thinking blocks are not prose and are skipped.
 *
 * @return array{0: ?string, 1: string, 2: string} [turnId, userText, assistantText]
 */
function lastTurnFromTranscript(string $path): array
{
    $lines = readLastLines($path, TRANSCRIPT_TAIL_LINES);
    $assistantText = '';
    $assistantUuid = null;
    $userText = '';

    for ($index = count($lines) - 1; $index >= 0; $index--) {
        $entry = json_decode($lines[$index], true);
        if (!is_array($entry)) {
            continue;
        }
        $type = $entry['type'] ?? null;

        if ($assistantUuid === null && $type === 'assistant') {
            $text = extractTextBlocks($entry['message']['content'] ?? null);
            if ($text !== '') {
                $assistantText = $text;
                $assistantUuid = is_scalar($entry['uuid'] ?? null) ? (string) $entry['uuid'] : ('line-' . $index);
                continue;
            }
        }

        if ($assistantUuid !== null && $userText === '' && $type === 'user') {
            $text = extractUserText($entry['message']['content'] ?? null);
            if ($text !== '') {
                $userText = $text;
                break;
            }
        }
    }

    if ($assistantUuid === null) {
        return [null, '', ''];
    }

    return [$assistantUuid, $userText, $assistantText];
}

function extractUserText(mixed $content): string
{
    if (is_string($content)) {
        return trim($content);
    }
    if (!is_array($content)) {
        return '';
    }
    $parts = [];
    foreach ($content as $block) {
        if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
            $parts[] = $block['text'];
        }
    }
    return trim(implode("\n", $parts));
}

function extractTextBlocks(mixed $content): string
{
    if (is_string($content)) {
        return trim($content);
    }
    if (!is_array($content)) {
        return '';
    }
    $parts = [];
    foreach ($content as $block) {
        if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
            $parts[] = $block['text'];
        }
    }
    return trim(implode("\n", $parts));
}

/** @return list<string> */
function readLastLines(string $path, int $maxLines): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    try {
        $buffer = '';
        $chunkSize = 65536;
        $position = fstat($handle)['size'] ?? 0;
        $lines = [];
        while ($position > 0 && count($lines) <= $maxLines) {
            $read = min($chunkSize, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = fread($handle, $read) . $buffer;
            $lines = preg_split('/\R/', $buffer) ?: [];
        }
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
        return array_slice($lines, -$maxLines);
    } finally {
        fclose($handle);
    }
}

/** @param array<string, mixed> $state */
function storeEpisode(array $state, bool $final, ?int $previous): int
{
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
        . ' Earlier rolling revisions for this Claude Code session are superseded.';
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
        'Automatic Navi continuity from the most recent captured Claude Code session.',
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
    $continuity = truncateText(implode("\n", $lines), 8000);
    return trim(implode("\n\n", array_filter([$remembered, $continuity])));
}

/** @return array<string, mixed> */
function newState(string $sessionId, string $cwd): array
{
    return [
        'version' => SESSION_MEMORY_VERSION,
        'source' => 'claude',
        'session_id' => $sessionId,
        'cwd' => $cwd,
        'created_at' => time(),
        'updated_at' => time(),
        'closed_at' => null,
        'memory_id' => null,
        'last_captured_turn_id' => null,
        'last_error' => null,
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
        if (($state['source'] ?? null) !== 'claude') {
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
    return stateDir() . '/claude-' . hash('sha256', $sessionId) . '.json';
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
        file_put_contents($path, gmdate('c') . ' claude ' . sanitizeText($message, 2000) . PHP_EOL, FILE_APPEND | LOCK_EX);
        chmod($path, 0600);
    } catch (Throwable) {
        // The original error is already sent to stderr.
    }
}
