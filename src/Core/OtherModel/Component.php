<?php

declare(strict_types=1);

namespace NaviBrain\Core\OtherModel;

use NaviBrain\Core\OtherModel;

class Component
{
    public function __construct(protected readonly OtherModel $facade) {
    }

    public function __call(string $name, array $arguments): mixed {
        return $this->facade->{$name}(...$arguments);
    }

    public function __get(string $name): mixed {
        return $this->facade->{$name};
    }
}
