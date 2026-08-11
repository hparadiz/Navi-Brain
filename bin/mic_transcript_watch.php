#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Transcript watcher for navi-transcript.
 *
 * Emits one stdout line per finalized utterance, but only after Aku has stopped
 * talking. The capture cuts the user off mid-thought to finalize a chunk and keeps
 * recording, so waking on each chunk means answering a fragment while the rest
 * of the sentence is still in flight. Chunks are held until the feed has been
 * quiet for DEBOUNCE_MS, then released together as one batch.
 *
 * Also emits on bridge outage and on sequence reset, so silence from this
 * watcher means "nothing was said", never "the watcher died quietly".
 */

const BRIDGE_URL = 'http://127.0.0.1:47831/transcripts';
const POLL_SECONDS = 1;
const DEBOUNCE_MS = 4000;
const OUTAGE_POLLS = 5;

function emit(string $line): void
{
    echo $line . "\n";
    flush();
}

/** Fetch newline-delimited feed. Returns null on any transport failure. */
function fetchFeed(int $after): ?array
{
    $context = stream_context_create([
        'http' => ['timeout' => 3, 'ignore_errors' => true],
    ]);

    $body = @file_get_contents(BRIDGE_URL . '?after=' . $after, false, $context);
    if ($body === false) {
        return null;
    }

    $status = null;
    $events = [];
    foreach (explode("\n", trim($body)) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }

        if (($row['type'] ?? '') === 'status') {
            $status = $row;
        } elseif (($row['type'] ?? '') === 'transcript') {
            $events[] = $row;
        }
    }

    return ['status' => $status, 'events' => $events];
}

$cursorScript = '/home/akujin/Sources/Navi-Brain/bin/mic_transcript_cursor.php';
$after = 0;
if (is_executable($cursorScript)) {
    $out = [];
    @exec(escapeshellarg($cursorScript) . ' --get --client claude 2>/dev/null', $out);
    $after = (int) trim(implode('', $out));
}

$pending = [];
$newestMs = 0;
$failures = 0;
$outageReported = false;

while (true) {
    $feed = fetchFeed($after);

    if ($feed === null) {
        $failures++;
        if ($failures >= OUTAGE_POLLS && !$outageReported) {
            emit('[watch] pet-native bridge unreachable on 127.0.0.1:47831 — not hearing anything');
            $outageReported = true;
        }
        sleep(POLL_SECONDS);
        continue;
    }

    if ($outageReported) {
        emit('[watch] bridge back up, listening again');
    }
    $failures = 0;
    $outageReported = false;

    // pet-native restarted and renumbered the feed from 1; rewind or we go deaf.
    $latest = (int) ($feed['status']['latestSequence'] ?? 0);
    if ($latest > 0 && $latest < $after) {
        emit("[watch] sequence reset: feed restarted at {$latest}, rewinding cursor");
        $after = 0;
        $pending = [];
        $newestMs = 0;
        sleep(POLL_SECONDS);
        continue;
    }

    foreach ($feed['events'] as $event) {
        $sequence = (int) ($event['sequence'] ?? 0);
        if ($sequence <= $after) {
            continue;
        }

        $after = $sequence;
        $text = trim((string) ($event['text'] ?? ''));
        $createdMs = (int) ($event['createdAtMs'] ?? 0);
        if ($createdMs > $newestMs) {
            $newestMs = $createdMs;
        }

        // Whisper's non-speech markers are not speech worth waking for, but
        // they still advance the cursor so they are not re-read forever.
        if ($text === '' || $text === '[BLANK_AUDIO]') {
            continue;
        }

        $pending[] = "[{$sequence}] {$text}";
    }

    // Hold the batch until the user has actually stopped talking.
    if ($pending !== [] && (int) (microtime(true) * 1000) - $newestMs >= DEBOUNCE_MS) {
        foreach ($pending as $line) {
            emit($line);
        }
        $pending = [];
        $newestMs = 0;
    }

    sleep(POLL_SECONDS);
}
