<?php

declare(strict_types=1);

namespace NaviBrain\Support;

class Activity
{
    public string $source;
    public string $phase;
    public string $operation;
    public ?string $domain = null;
    public int $durationMs = 0;
    public string $outcome = 'ok';
    public array $context = [];
    public ?string $flow = null;
}
