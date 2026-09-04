<?php

declare(strict_types=1);

namespace NaviBrain\Support;

/**
 * Deterministic, delimiter-free text for prompts and normal command output.
 *
 * Arrays remain the internal source of truth. This renderer is the boundary
 * that prevents their wire/storage notation from leaking into model context.
 */
final class PlainText
{
    /** @var array<string, int> */
    private const KEY_PRIORITY = [
        'record_counts' => 5,
        'primary_intention' => 7,
        'background_intentions' => 10,
        'active_intentions' => 11,
        'blocked_intentions' => 20,
        'pending_actions' => 30,
        'recent_discrepancies' => 40,
        'working_memory_slots' => 50,
        'working_memory' => 60,
        'semantic_memory' => 70,
        'self_model' => 80,
        'needs' => 90,
        'heartbeat' => 100,
        'proposed_thoughts' => 110,
        'procedural_memory' => 120,
        'procedures' => 130,
        'decision_cycles' => 140,
        'decision_comparison' => 150,
        'other_model' => 160,
        'top_appraisals' => 170,
        'title' => 200,
        'next_action' => 210,
        'success_condition' => 220,
        'content' => 230,
        'claim' => 240,
        'fact_key' => 250,
        'fact_value' => 260,
        'status' => 270,
        'confidence' => 280,
        'id' => 290,
    ];

    public static function render(
        mixed $value,
        int $maxCharacters = 12000,
        int $listLimit = 8
    ): string {
        $lines = [];
        self::append($value, $lines, 0, null, max(1, $listLimit));
        $text = trim(self::sanitize(implode("\n", $lines)));
        if ($text === '') {
            return 'none';
        }
        if (mb_strlen($text) <= $maxCharacters) {
            return $text;
        }

        $cut = mb_substr($text, 0, max(1, $maxCharacters - 80));
        $lastLine = mb_strrpos($cut, "\n");
        if ($lastLine !== false && $lastLine > (int) ($maxCharacters * 0.7)) {
            $cut = mb_substr($cut, 0, $lastLine);
        }
        return rtrim($cut) . "\noutput truncated — request narrower context or debug output";
    }

    public static function inline(mixed $value, int $maxCharacters = 2400): string
    {
        return trim((string) preg_replace(
            '/\s*\R\s*/u',
            '; ',
            self::render($value, $maxCharacters, 16)
        ));
    }

    public static function sanitize(string $text): string
    {
        $text = str_replace(['{', '}', '[', ']'], '', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\R */u', "\n", $text) ?? $text;
        return $text;
    }

    /** @param list<string> $lines */
    private static function append(
        mixed $value,
        array &$lines,
        int $depth,
        ?string $label,
        int $listLimit
    ): void {
        $indent = str_repeat('  ', min($depth, 8));
        $label = $label === null ? null : self::label($label);

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_string($value)) {
            $decoded = self::decodeStructuredString($value);
            if ($decoded !== null) {
                self::append($decoded, $lines, $depth, $label, $listLimit);
                return;
            }
        }

        if (!is_array($value)) {
            $scalar = self::scalar($value);
            $lines[] = $label === null
                ? $indent . $scalar
                : $indent . $label . ': ' . $scalar;
            return;
        }

        if ($value === []) {
            $lines[] = $label === null ? $indent . 'none' : $indent . $label . ': none';
            return;
        }

        if ($label !== null) {
            $lines[] = $indent . $label;
            $depth++;
            $indent = str_repeat('  ', min($depth, 8));
        }

        if (array_is_list($value)) {
            $visible = array_slice($value, 0, $listLimit);
            foreach ($visible as $index => $item) {
                $lines[] = $indent . 'item ' . ($index + 1);
                self::append($item, $lines, $depth + 1, null, $listLimit);
            }
            $omitted = count($value) - count($visible);
            if ($omitted > 0) {
                $lines[] = $indent . $omitted . ' more items omitted';
            }
            return;
        }

        foreach (self::ordered($value) as $key => $item) {
            self::append($item, $lines, $depth, (string) $key, $listLimit);
        }
    }

    /** @param array<string|int, mixed> $value
     *  @return array<string|int, mixed>
     */
    private static function ordered(array $value): array
    {
        $indexed = [];
        $position = 0;
        foreach ($value as $key => $item) {
            $indexed[] = [
                'key' => $key,
                'value' => $item,
                'priority' => self::KEY_PRIORITY[(string) $key] ?? 1000,
                'position' => $position++,
            ];
        }
        usort($indexed, static fn (array $left, array $right): int =>
            $left['priority'] <=> $right['priority'] ?: $left['position'] <=> $right['position']
        );

        $ordered = [];
        foreach ($indexed as $entry) {
            $ordered[$entry['key']] = $entry['value'];
        }
        return $ordered;
    }

    private static function decodeStructuredString(string $value): mixed
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !in_array($trimmed[0], ['{', '['], true)) {
            return null;
        }
        $decoded = json_decode($trimmed, true);
        return is_array($decoded) && json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return 'none';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        $text = trim(self::sanitize((string) $value));
        if (mb_strlen($text) > 1200) {
            $text = rtrim(mb_substr($text, 0, 1190)) . ' …';
        }
        return $text === '' ? 'none' : $text;
    }

    private static function label(string $label): string
    {
        return trim(self::sanitize(str_replace('_', ' ', $label)));
    }
}
