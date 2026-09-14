<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Support\Name;
use NaviBrain\Support\PlainText;

class ExecutiveComposition
{
    /** @var list<array{function: string, demands: ?string, holds: mixed}> */
    private array $faculties = [];

    public function __construct(private readonly string $situation)
    {
    }

    public function contribute(string $function, ?string $demands, mixed $holds = null): self
    {
        if ($demands === null && ($holds === null || $holds === [] || $holds === '')) {
            return $this;
        }
        $this->faculties[] = ['function' => $function, 'demands' => $demands, 'holds' => $holds];
        return $this;
    }

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
            $lines[] = sprintf('What each part of %s is asking of this moment:', Name::get());
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
                    ? PlainText::sanitize($faculty['holds'])
                    : PlainText::render($faculty['holds'], 12000, 20)
            );
        }

        return PlainText::sanitize(implode("\n", $lines));
    }
}
