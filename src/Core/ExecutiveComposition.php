<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use JsonException;

/**
 * Builds a prompt out of what Navi's executive functions currently report.
 *
 * The prompts this replaces were fixed English: the same paragraph every time,
 * describing a situation that had already been decided elsewhere. That has two
 * failure modes and both showed up. A constant cannot say "nothing is salient
 * right now", so every wake looked equally eventful. And whatever the constant
 * happened to mention became the only thing there was to talk about — a prompt
 * built from relationship pressure and sensor edges can only produce lines
 * about relationship pressure and sensor edges, which is exactly what it did.
 *
 * Here each function states what it currently holds and what it therefore
 * demands. A function holding nothing contributes nothing, so the prompt is
 * shorter on a quiet evening than during a busy one, and the model is reading
 * Navi's actual state rather than a description of a generic state.
 *
 * Order is deliberate and is itself an executive claim: goals before drives,
 * because something worth saying outranks the urge to say something.
 */
final class ExecutiveComposition
{
    /** @var list<array{function: string, demands: ?string, holds: mixed}> */
    private array $faculties = [];

    public function __construct(private readonly string $situation)
    {
    }

    /**
     * Record one function's contribution.
     *
     * A function with nothing to hold and nothing to demand is not in the
     * prompt at all. Silence from a faculty is information, and padding it with
     * "no data" would tell the model to reason about an absence it cannot act
     * on.
     */
    public function contribute(string $function, ?string $demands, mixed $holds = null): self
    {
        if ($demands === null && ($holds === null || $holds === [] || $holds === '')) {
            return $this;
        }
        $this->faculties[] = ['function' => $function, 'demands' => $demands, 'holds' => $holds];
        return $this;
    }

    /** @return list<string> */
    public function functions(): array
    {
        return array_map(
            static fn (array $faculty): string => $faculty['function'],
            $this->faculties
        );
    }

    /**
     * @throws JsonException
     */
    public function prompt(): string
    {
        $lines = [$this->situation, ''];

        $demands = [];
        foreach ($this->faculties as $faculty) {
            if ($faculty['demands'] !== null) {
                $demands[] = sprintf('- %s: %s', $faculty['function'], $faculty['demands']);
            }
        }
        if ($demands !== []) {
            $lines[] = 'What each part of Navi is asking of this moment:';
            $lines[] = implode("\n", $demands);
            $lines[] = '';
        }

        foreach ($this->faculties as $faculty) {
            if ($faculty['holds'] === null || $faculty['holds'] === [] || $faculty['holds'] === '') {
                continue;
            }
            $lines[] = sprintf(
                '%s holds: %s',
                ucfirst(str_replace('_', ' ', $faculty['function'])),
                is_string($faculty['holds'])
                    ? $faculty['holds']
                    : json_encode(
                        $faculty['holds'],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    )
            );
        }

        return implode("\n", $lines);
    }
}
