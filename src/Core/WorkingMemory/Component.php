<?php

declare(strict_types=1);

namespace NaviBrain\Core\WorkingMemory;

use NaviBrain\Core\WorkingMemory;

abstract class Component
{
    public function __construct(protected readonly WorkingMemory $owner)
    {
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->owner->$method(...$arguments);
    }
}
