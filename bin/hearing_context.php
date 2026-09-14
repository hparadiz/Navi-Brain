#!/usr/bin/env php
<?php

declare(strict_types=1);

const PRESENCE_SCRIPT = '/home/akujin/skills/navi-presence/scripts/presence_snapshot.php';
const CMD_TIMEOUT_SECONDS = 6;

const CAPTURE_IGNORE = [
    'pet-native',
    'evemon-meter',
    'evemon',
    'obs',
    'navi',
    'whisper',
];

const CALL_APPS = [
    'discord' => 'discord',
    'webcord' => 'discord',
    'vesktop' => 'discord',
    'zoom' => 'zoom',
    'teams' => 'teams',
    'slack' => 'slack',
    'signal' => 'signal',
    'telegram' => 'telegram',
    'whatsapp' => 'whatsapp',
    'element' => 'element',
    'mumble' => 'mumble',
    'teamspeak' => 'teamspeak',
    'firefox' => 'browser',
    'librewolf' => 'browser',
    'chromium' => 'browser',
    'brave' => 'browser',
    'chrome' => 'browser',
];

function run(array $cmd): array
{
    if (!is_executable('/usr/bin/timeout')) {
        $full = implode(' ', array_map('escapeshellarg', $cmd));
    } else {
        $full = '/usr/bin/timeout ' . CMD_TIMEOUT_SECONDS . ' '
            . implode(' ', array_map('escapeshellarg', $cmd));
    }

    $out = [];
    $status = 0;
    exec($full . ' 2>/dev/null', $out, $status);

    return ['lines' => $out, 'status' => $status];
}

function presence(): array
{
    if (!is_executable(PRESENCE_SCRIPT)) {
        return [];
    }

    $result = run([PRESENCE_SCRIPT]);
    $snapshot = [];
    foreach ($result['lines'] as $line) {
        if (preg_match('/^([A-Za-z_]+)=(.*)$/', trim($line), $m)) {
            $snapshot[$m[1]] = trim($m[2]);
        }
    }

    return $snapshot;
}

function commForPid(?int $pid): string
{
    if ($pid === null || $pid <= 0) {
        return '';
    }

    $comm = @file_get_contents("/proc/{$pid}/comm");

    return $comm === false ? '' : trim($comm);
}

function captureStreams(): array
{
    $result = run(['pactl', '-f', 'json', 'list', 'source-outputs']);
    if ($result['status'] !== 0) {
        return [];
    }

    $decoded = json_decode(implode("\n", $result['lines']), true);
    if (!is_array($decoded)) {
        return [];
    }

    $streams = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $props = is_array($entry['properties'] ?? null) ? $entry['properties'] : [];
        $pid = isset($props['application.process.id']) ? (int) $props['application.process.id'] : null;

        $identity = strtolower(trim(implode(' ', array_filter([
            (string) ($props['application.name'] ?? ''),
            (string) ($props['application.process.binary'] ?? ''),
            (string) ($props['application.id'] ?? ''),
            (string) ($props['media.name'] ?? ''),
            (string) ($props['node.name'] ?? ''),
            commForPid($pid),
        ]))));

        if ($identity === '') {
            continue;
        }

        $streams[] = [
            'identity' => $identity,
            'corked' => (bool) ($entry['corked'] ?? false),
        ];
    }

    return $streams;
}

function isIgnored(string $identity): bool
{
    foreach (CAPTURE_IGNORE as $needle) {
        if (str_contains($identity, $needle)) {
            return true;
        }
    }

    return false;
}

function callAppLabel(string $identity): ?string
{
    foreach (CALL_APPS as $needle => $label) {
        if (str_contains($identity, $needle)) {
            return $label;
        }
    }

    return null;
}

function callState(array $streams): array
{
    $live = 0;
    $labels = [];

    foreach ($streams as $stream) {
        if ($stream['corked'] || isIgnored($stream['identity'])) {
            continue;
        }

        $live++;
        $label = callAppLabel($stream['identity']);
        if ($label !== null && !in_array($label, $labels, true)) {
            $labels[] = $label;
        }
    }

    return [
        'hint' => $labels === [] ? 'none' : $labels[0],
        'apps' => $labels,
        'live_streams' => $live,
    ];
}

$presence = presence();
$call = callState(captureStreams());

echo "active_window_caption=unavailable\n";
echo "active_window_class=unavailable\n";
echo "active_window_name=unavailable\n";
echo "window_call_hint=" . $call['hint'] . "\n";
echo "call_in_progress=" . ($call['apps'] === [] ? 'false' : 'true') . "\n";
echo "call_capture_apps=" . ($call['apps'] === [] ? 'none' : implode(',', $call['apps'])) . "\n";
echo "live_capture_streams=" . $call['live_streams'] . "\n";
echo "media_playing=" . ($presence['media_playing'] ?? 'null') . "\n";
echo "active_media_app=" . ($presence['active_media_app'] ?? 'null') . "\n";
echo "active_media_title=" . ($presence['active_media_title'] ?? 'null') . "\n";
echo "game_running=" . ($presence['game_running'] ?? 'null') . "\n";
echo "likely_activity=" . ($presence['likely_activity'] ?? 'null') . "\n";
