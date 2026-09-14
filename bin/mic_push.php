<?php

declare(strict_types=1);

const ENDPOINT = 'http://127.0.0.1:47831/transcripts?after=';
const INTERVAL = 1;

const FLAG = '/home/akujin/.config/navi-audio-mode';

const STALL_SECONDS = 180;
const REPAIR_COOLDOWN = 600;

function audioMode(): bool
{
    clearstatcache(true, FLAG);
    return file_exists(FLAG);
}

function isNonSpeech(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return true;
    }
    $first = $text[0];
    $last = substr($text, -1);
    return ($first === '(' && $last === ')')
        || ($first === '[' && $last === ']')
        || ($first === '*' && $last === '*');
}

function petPid(): ?int
{
    $out = @shell_exec('pgrep -x pet-native 2>/dev/null');
    $pid = (int) trim((string) $out);
    return $pid > 0 ? $pid : null;
}

function audioThreadTicks(int $pid): ?int
{
    $total = 0;
    $found = false;
    foreach (glob("/proc/$pid/task/*/comm") ?: [] as $commPath) {
        $name = trim((string) @file_get_contents($commPath));
        if ($name === '' || !str_contains($name, 'audi')) {
            continue;
        }
        $stat = @file_get_contents(dirname($commPath) . '/stat');
        if ($stat === false) {
            continue;
        }
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        $total += (int) ($fields[11] ?? 0) + (int) ($fields[12] ?? 0);
        $found = true;
    }

    return $found ? $total : null;
}

function audioThreadsIdle(): ?bool
{
    $pid = petPid();
    if ($pid === null) {
        return null;
    }

    $before = audioThreadTicks($pid);
    if ($before === null) {
        return null;
    }
    sleep(3);
    $after = audioThreadTicks($pid);

    return $after === null ? null : ($after - $before) === 0;
}

function fetchFeed(int $after): ?array
{
    $context = stream_context_create([ 'http' => ['timeout' => 3, 'ignore_errors' => true], ]);

    $body = @file_get_contents(ENDPOINT . $after, false, $context);
    if ($body === false) {
        return null;
    }

    $status = null;
    $events = [];
    foreach (explode("\n", trim($body)) as $line) {
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        if (($decoded['type'] ?? null) === 'status') {
            $status = $decoded;
        } elseif (($decoded['type'] ?? null) === 'transcript') {
            $events[] = $decoded;
        }
    }

    return $status === null ? null : ['status' => $status, 'events' => $events];
}

function emit(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

$feed = fetchFeed(0);
if ($feed === null) {
    emit('mic feed UNREACHABLE at arm time: pet-native not answering on 127.0.0.1:47831');
    exit(1);
}

$cursor = (int) ($feed['status']['latestSequence'] ?? 0);
$mode = audioMode();
emit(sprintf('mic feed armed: audio mode %s, state=%s model=%s cursor=%d', $mode ? 'ON' : 'OFF', $feed['status']['state'] ?? 'unknown', $feed['status']['model'] ?? 'unknown', $cursor));

$down = false;
$lastError = '';
$lastState = $feed['status']['state'] ?? 'unknown';
$lastAdvance = time();
$lastRepair = 0;

while (true) {
    sleep(INTERVAL);

    $feed = fetchFeed($cursor);
    if ($feed === null) {
        if (!$down) {
            $down = true;
            emit('mic feed DOWN: pet-native stopped answering');
        }
        continue;
    }

    if ($down) {
        $down = false;
        emit('mic feed RECOVERED');
    }

    $latest = (int) ($feed['status']['latestSequence'] ?? 0);
    if ($latest < $cursor) {
        emit(sprintf('mic feed sequence reset: %d -> %d, rewinding cursor', $cursor, $latest));
        $cursor = 0;
        $lastAdvance = time();
        $feed = fetchFeed($cursor) ?? $feed;
        $latest = (int) ($feed['status']['latestSequence'] ?? 0);
    }

    if ($latest > $cursor) {
        $lastAdvance = time();
    }

    if (time() - $lastAdvance > STALL_SECONDS && time() - $lastRepair > REPAIR_COOLDOWN) {
        $idle = audioThreadsIdle();
        $lastRepair = time();
        $lastAdvance = time();
        if ($idle === true) {
            emit('mic feed STALLED: audio threads idle while state claims listening. Not touching pet.');
        }
    }

    $nowMode = audioMode();
    if ($nowMode !== $mode) {
        $mode = $nowMode;
        emit('audio mode ' . ($mode ? 'ON' : 'OFF'));
    }

    if (!$mode) {

        $cursor = (int) ($feed['status']['latestSequence'] ?? $cursor);
        continue;
    }

    $state = $feed['status']['state'] ?? 'unknown';
    $normal = ['listening', 'transcribing'];
    if ($state !== $lastState
        && (!in_array($state, $normal, true) || !in_array($lastState, $normal, true))) {
        emit(sprintf('mic feed state changed: %s -> %s', $lastState, $state));
    }
    $lastState = $state;

    $error = trim((string) ($feed['status']['error'] ?? ''));
    if ($error !== '' && $error !== $lastError) {
        emit('mic feed ERROR: ' . $error);
    }
    $lastError = $error;

    foreach ($feed['events'] as $event) {
        $text = trim((string) ($event['text'] ?? ''));
        if (isNonSpeech($text)) {
            continue;
        }
        emit(sprintf( 'heard [seq %d, %dms]: %s', (int) ($event['sequence'] ?? 0), (int) ($event['durationMs'] ?? 0), $text ));
    }

    $cursor = (int) ($feed['status']['latestSequence'] ?? $cursor);
}
