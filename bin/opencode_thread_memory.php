#!/usr/bin/env php
<?php

declare(strict_types=1);

const STATE_DIR = '/home/akujin/Sources/Navi-Brain/var';
const STATE_PATH = STATE_DIR . '/navi-thread.json';
const DEFAULT_DB = '/home/akujin/.local/share/opencode/opencode-dev.db';

$mode = $argv[1] ?? '--context';

if ($mode === '--context') {
    echo renderContext(loadState());
    exit(0);
}

if ($mode === '--tail') {
    $lines = readOption('--lines');
    if ($lines === null || !ctype_digit($lines)) {
        fwrite(STDERR, "usage: opencode_thread_memory.php --tail --lines N [--dir DIR] [--project ID]\n");
        exit(1);
    }
    echo renderTail((int) $lines, readOption('--dir'), readOption('--project'));
    exit(0);
}

if ($mode === '--write') {
    $source = readOption('--source') ?? '';
    $summary = readOption('--summary');
    $keywords = readOption('--keywords') ?? '';
    if ($summary === null || trim($summary) === '') {
        fwrite(STDERR, "missing --summary\n");
        exit(1);
    }

    $state = loadState();
    $state['updated_at'] = time();
    $state['source'] = sanitizeInline($source);
    $state['summary'] = sanitizeInline($summary);
    $state['summary_words'] = wordCount($summary);
    $state['keywords'] = sanitizeInline($keywords);
    persistState($state);
    echo "ok\n";
    exit(0);
}

fwrite(STDERR, "usage: opencode_thread_memory.php [--context|--tail|--write --source NAME --summary TEXT [--keywords CSV]]\n");
exit(1);

function loadState(): array
{
    if (!is_file(STATE_PATH)) {
        return [
            'updated_at' => 0,
            'source' => '',
            'summary' => '',
            'summary_words' => 0,
            'keywords' => '',
        ];
    }

    $json = file_get_contents(STATE_PATH);
    $data = json_decode($json ?: '', true);
    if (!is_array($data)) {
        return [
            'updated_at' => 0,
            'source' => '',
            'summary' => '',
            'summary_words' => 0,
            'keywords' => '',
        ];
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

function renderContext(array $state): string
{
    $updatedAt = (int) ($state['updated_at'] ?? 0);
    $ageMinutes = $updatedAt > 0 ? max(0, (int) floor((time() - $updatedAt) / 60)) : -1;
    $lines = [
        'opencode_thread_age_minutes=' . $ageMinutes,
        'opencode_thread_source=' . sanitizeInline((string) ($state['source'] ?? '')),
    ];

    $summary = trim((string) ($state['summary'] ?? ''));
    if ($summary !== '') {
        $lines[] = 'opencode_thread_summary=' . sanitizeInline($summary);
        $lines[] = 'opencode_thread_summary_words=' . (int) ($state['summary_words'] ?? wordCount($summary));
    }

    $keywords = trim((string) ($state['keywords'] ?? ''));
    if ($keywords !== '') {
        $lines[] = 'opencode_thread_keywords=' . sanitizeInline($keywords);
    }

    return implode("\n", $lines) . "\n";
}

function renderTail(int $lines, ?string $dir, ?string $project): string
{
    $db = findDb();
    if ($db === null) {
        fwrite(STDERR, "no opencode database found\n");
        exit(1);
    }

    $where = [];
    $params = [];

    if ($project !== null) {
        $where[] = 's.id = ?';
        $params[] = $project;
    } elseif ($dir !== null) {
        $where[] = 's.directory = ?';
        $params[] = rtrim($dir, '/');
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $sql = "SELECT p.data FROM part p JOIN session s ON s.id = p.session_id"
        . $whereSql
        . " ORDER BY p.time_created DESC LIMIT " . max(1, $lines);

    $rows = query($db, $sql, $params);
    $texts = [];
    foreach ($rows as $row) {
        $data = json_decode($row['data'] ?? '', true);
        if (!is_array($data)) {
            continue;
        }
        $text = (string) ($data['text'] ?? '');
        if ($text === '') {
            continue;
        }
        $texts[] = trim($text);
    }

    if ($texts === []) {
        echo "(no recent opencode session text)\n";
        exit(0);
    }

    echo "--- opencode recent session text (newest first) ---\n";
    echo implode("\n\n", array_slice($texts, 0, $lines)) . "\n";
    exit(0);
}

function findDb(): ?string
{
    foreach ([
        DEFAULT_DB,
        '/home/akujin/.local/share/opencode/opencode.db',
    ] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    foreach (glob('/home/akujin/.local/share/opencode/*.db') ?: [] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function query(string $db, string $sql, array $params = []): array
{
    foreach ($params as $value) {
        $value = (string) $value;
        $quoted = "'" . str_replace("'", "''", $value) . "'";
        $sql = preg_replace('/\?/', $quoted, $sql, 1) ?? $sql;
    }
    $cmd = ['sqlite3', '-separator', "\t", $db, $sql];
    $result = [];
    $status = 0;
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $result, $status);

    if ($status !== 0 || $result === []) {
        return [];
    }

    $rows = [];
    foreach ($result as $line) {
        $rows[] = ['data' => $line];
    }
    return $rows;
}

function sanitizeInline(string $value): string
{
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    return str_replace(["\r", "\n"], ' ', $value);
}

function wordCount(string $text): int
{
    $parts = preg_split('/\s+/', trim($text)) ?: [];
    $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    return count($parts);
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
