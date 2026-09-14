<?php

declare(strict_types=1);

namespace NaviBrain\Perception\SensoryCortex;

use NaviBrain\Perception\SensoryCortex;

class Component
{
    public function __construct(protected readonly SensoryCortex $facade) {
    }

    public function __call(string $name, array $arguments): mixed {
        return $this->facade->{$name}(...$arguments);
    }

    public function __get(string $name): mixed {
        return $this->facade->{$name};
    }
}
