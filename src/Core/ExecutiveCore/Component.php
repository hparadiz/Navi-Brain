<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

abstract class Component
{
    public function __construct(protected Executive $executive)
    {
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->executive->$method(...$arguments);
    }

}
