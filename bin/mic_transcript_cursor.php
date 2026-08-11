#!/usr/bin/env php
<?php

declare(strict_types=1);

const STATE_DIR = '/home/akujin/Sources/Navi-Brain/var';
const STATE_PATH = STATE_DIR . '/navi-transcript.json';
const MAX_RECENT = 40;
const DEFAULT_CLIENT = 'opencode';

$mode = $argv[1] ?? '--get';
$client = readOption('--client') ?? DEFAULT_CLIENT;

if ($mode === '--get') {
    echo cursorFor(loadState(), $client) . "\n";
    exit(0);
}

if ($mode === '--set') {
    $value = readPositionalDigits();
    if ($value === null) {
        fwrite(STDERR, "usage: mic_transcript_cursor.php --set N [--client NAME]\n");
        exit(1);
    }

    $state = loadState();
    $state = withCursor($state, $client, (int) $value);
    $state['updated_at'] = time();
    persistState($state);
    echo "ok\n";
    exit(0);
}

if ($mode === '--tag') {
    $seq = readOption('--seq');
    $addressee = readOption('--addressee');
    $hint = readOption('--hint');
    $text = readOption('--text');
    if ($seq === null || !ctype_digit($seq) || $addressee === null) {
        fwrite(STDERR, "usage: mic_transcript_cursor.php --tag --seq N --addressee KIND [--hint TEXT] [--text TEXT] [--client NAME]\n");
        exit(1);
    }
    if (!in_array($addressee, ['to_self', 'to_me', 'to_person', 'to_room'], true)) {
        fwrite(STDERR, "addressee must be one of to_self|to_me|to_person|to_room\n");
        exit(1);
    }

    $state = loadState();
    $recent = is_array($state['recent'] ?? null) ? $state['recent'] : [];
    $recent[] = [
        'seq' => (int) $seq,
        'addressee' => $addressee,
        'hint' => $hint ?? null,
        'text' => $text ?? null,
        'at' => time(),
        'client' => $client,
    ];
    usort($recent, static fn(array $a, array $b): int => (int) $a['seq'] <=> (int) $b['seq']);
    $state['recent'] = array_slice($recent, -MAX_RECENT);
    $state['updated_at'] = time();
    persistState($state);
    echo "ok\n";
    exit(0);
}

if ($mode === '--recent') {
    $state = loadState();
    $recent = is_array($state['recent'] ?? null) ? $state['recent'] : [];
    foreach ($recent as $entry) {
        $line = sprintf(
            "seq=%d addressee=%s at=%s",
            (int) $entry['seq'],
            (string) ($entry['addressee'] ?? '?'),
            (string) ($entry['at'] ?? '')
        );
        if (!empty($entry['hint'])) {
            $line .= ' hint=' . (string) $entry['hint'];
        }
        if (!empty($entry['client'])) {
            $line .= ' client=' . (string) $entry['client'];
        }
        echo $line . "\n";
    }
    exit(0);
}

if ($mode === '--rewind-if-stale') {
    $state = loadState();
    $latest = liveLatestSequence();
    if ($latest === null) {
        fwrite(STDERR, "rewind-if-stale: pet-native not answering on 127.0.0.1:47831\n");
        exit(1);
    }
    $rewound = [];
    foreach (allCursors($state) as $name => $value) {
        if ($value > $latest) {
            $state = withCursor($state, $name, 0);
            $rewound[] = $name . '=' . $value . '->0';
        }
    }
    if ($rewound !== []) {
        $state['updated_at'] = time();
        persistState($state);
    }
    echo ($rewound === [] ? 'ok' : 'rewound: ' . implode(', ', $rewound)) . "\n";
    exit(0);
}

if ($mode === '--cursors') {
    $state = loadState();
    foreach (allCursors($state) as $name => $value) {
        echo $name . '=' . $value . "\n";
    }
    exit(0);
}

fwrite(STDERR, "usage: mic_transcript_cursor.php [--get|--set N|--tag --seq N --addressee KIND [--hint TEXT] [--text TEXT]|--recent|--cursors|--rewind-if-stale] [--client NAME]\n");
exit(1);

/**
 * Each consuming agent keeps its own cursor so two clients reading the same
 * bounded feed do not consume each other's unread utterances. The legacy
 * scalar `cursor` key remains the opencode cursor.
 */
function cursorFor(array $state, string $client): int
{
    $cursors = is_array($state['cursors'] ?? null) ? $state['cursors'] : [];
    if (array_key_exists($client, $cursors)) {
        return (int) $cursors[$client];
    }

    // An unseen client joins where the feed already is rather than at zero, so
    // it does not replay the whole bounded backlog on its first read.
    return (int) ($state['cursor'] ?? 0);
}

function allCursors(array $state): array
{
    $cursors = is_array($state['cursors'] ?? null) ? $state['cursors'] : [];
    $cursors[DEFAULT_CLIENT] ??= (int) ($state['cursor'] ?? 0);
    return array_map('intval', $cursors);
}

function withCursor(array $state, string $client, int $value): array
{
    $cursors = is_array($state['cursors'] ?? null) ? $state['cursors'] : [];
    $cursors[$client] = $value;
    $state['cursors'] = array_map('intval', $cursors);
    if ($client === DEFAULT_CLIENT) {
        $state['cursor'] = $value;
    }
    $state['cursor'] ??= 0;
    return $state;
}

/** Live latest sequence from pet-native, or null when the bridge is down. */
function liveLatestSequence(): ?int
{
    $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $body = @file_get_contents('http://127.0.0.1:47831/transcripts?after=0', false, $context);
    if ($body === false) {
        return null;
    }
    foreach (explode("\n", trim($body)) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'status') {
            continue;
        }
        return (int) ($decoded['latestSequence'] ?? 0);
    }
    return null;
}

function loadState(): array
{
    if (!is_file(STATE_PATH)) {
        return ['cursor' => 0, 'updated_at' => 0, 'recent' => []];
    }

    $json = file_get_contents(STATE_PATH);
    $data = json_decode($json ?: '', true);
    if (!is_array($data)) {
        return ['cursor' => 0, 'updated_at' => 0, 'recent' => []];
    }

    return $data;
}

function persistState(array $state): void
{
    if (!is_dir(STATE_DIR)) {
        mkdir(STATE_DIR, 0775, true);
    }
    file_put_contents(STATE_PATH, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function readOption(string $name): ?string
{
    global $argv;
    $count = count($argv);
    for ($i = 0; $i < $count; $i++) {
        if ($argv[$i] !== $name) {
            continue;
        }
        return $argv[$i + 1] ?? null;
    }
    return null;
}

/**
 * Reads the first bare numeric argument, so `--set 12 --client x` and
 * `--set --client x 12` both work.
 */
function readPositionalDigits(): ?string
{
    global $argv;
    $count = count($argv);
    for ($i = 2; $i < $count; $i++) {
        if ($argv[$i - 1] === '--client') {
            continue;
        }
        if (ctype_digit($argv[$i])) {
            return $argv[$i];
        }
    }
    return null;
}
