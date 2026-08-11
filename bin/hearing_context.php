#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Hearing context snapshot for navi-transcript.
 *
 * Hard rule: nothing in here may be interactive or blocking. The old version
 * called org.kde.KWin.queryWindowInfo, which is KWin's *picker* — it turns the
 * cursor into a crosshair and waits for a click before returning. That made
 * every context snapshot hijack the desktop.
 *
 * There is no non-interactive way to read the focused window on KWin/Wayland
 * without loading a KWin script, so focus is no longer consulted at all. The
 * call hint now comes from PipeWire: an app holding a live (non-corked) mic
 * capture stream is on a call, whether or not its window has focus. That also
 * matches how the skill actually behaves — a call outlives window focus.
 */

const PRESENCE_SCRIPT = '/home/akujin/skills/navi-presence/scripts/presence_snapshot.php';
const CMD_TIMEOUT_SECONDS = 6;

/** Capture streams that are Navi's own plumbing or ambient tooling, never a call. */
const CAPTURE_IGNORE = [
    'pet-native',
    'evemon-meter',
    'evemon',
    'obs',
    'navi',
    'whisper',
];

/** Substring => call hint label. Order matters; first match wins. */
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

/**
 * Run a command with a hard timeout so a wedged helper can never stall the
 * snapshot. Returns stdout lines; stderr is discarded rather than parsed.
 */
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

/** Best-effort process name for a pid, read straight from /proc. */
function commForPid(?int $pid): string
{
    if ($pid === null || $pid <= 0) {
        return '';
    }

    $comm = @file_get_contents("/proc/{$pid}/comm");

    return $comm === false ? '' : trim($comm);
}

/**
 * Live microphone capture streams, one row per stream, with an identity string
 * assembled from every naming hint PipeWire offers.
 */
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

/**
 * Decide the call hint from live capture streams only. Corked streams are apps
 * holding the mic open without using it, which is not a call.
 */
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

// Focus is deliberately unavailable: reading it non-interactively on
// KWin/Wayland is not possible without loading a KWin script, and the
// interactive path is what broke this snapshot in the first place.
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
