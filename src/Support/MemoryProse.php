<?php

declare(strict_types=1);

namespace NaviBrain\Support;

use UnexpectedValueException;

/** Keeps storage bookkeeping outside the memory prose presented to the model. */
final class MemoryProse
{
    /**
     * Unwrap the exact legacy automatic-capture serialization. Other prose is
     * returned byte-for-byte, including manually authored session summaries.
     * This method is pure and can be loaded without the application bootstrap.
     */
    public static function fromLegacy(string $content): string
    {
        if (!preg_match(
            '/\ASession in (.*?) \((?:rolling|finalized) automatic capture\): (.*) Earlier rolling revisions for this (?:Codex|Claude Code|Opencode) session are superseded\.\z/s',
            $content,
            $matches
        )) {
            return $content;
        }

        $body = $matches[2];
        if (!str_starts_with($body, 'User: ')) {
            throw new UnexpectedValueException('Legacy automatic memory has no initial user span.');
        }
        $turns = explode(' User: ', substr($body, strlen('User: ')));
        if (count($turns) > 4) {
            throw new UnexpectedValueException('Legacy automatic memory has ambiguous turn boundaries.');
        }

        $prose = [];
        foreach ($turns as $turn) {
            $boundary = strpos($turn, ' Navi: ');
            if ($boundary === false || $boundary > 420) {
                throw new UnexpectedValueException('Legacy automatic memory has an ambiguous assistant boundary.');
            }
            $assistant = substr($turn, $boundary + strlen(' Navi: '));
            if (strlen($assistant) > 650) {
                throw new UnexpectedValueException('Legacy automatic memory exceeds its assistant span limit.');
            }
            // This exact value was emitted by the capture writer for no text.
            if ($assistant !== '' && $assistant !== '(no final text)') {
                $prose[] = $assistant;
            }
        }
        return implode("\n\n", $prose);
    }

    /** @param list<array<string, mixed>> $turns */
    public static function fromTurns(array $turns): string
    {
        $prose = [];
        foreach ($turns as $turn) {
            $content = is_array($turn) ? ($turn['assistant'] ?? null) : null;
            if (is_string($content) && trim($content) !== '') {
                $prose[] = $content;
            }
        }
        return implode("\n\n", $prose);
    }

    /** @param list<array<string, mixed>> $records */
    public static function recalled(array $records): string
    {
        $prose = [];
        foreach ($records as $record) {
            if (is_string($record['content'] ?? null) && $record['content'] !== '') {
                $content = self::fromLegacy($record['content']);
                if ($content !== '') {
                    $prose[] = $content;
                }
            }
        }
        return implode("\n\n", $prose);
    }
}
