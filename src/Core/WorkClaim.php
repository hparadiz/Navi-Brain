<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use InvalidArgumentException;

class WorkClaim
{
    public array $excludedWorkTypes = [];
    public array $includedWorkTypes = [];
    public ?int $workId = null;
    public string $lane = 'general';

    public function __construct(public string $owner, public int $leaseSeconds = 180)
    {
    }

    public function validate(): void
    {
        if (trim($this->owner) === '') {
            throw new InvalidArgumentException('Worker owner cannot be empty.');
        }
        if ($this->leaseSeconds < 10 || $this->leaseSeconds > 3600) {
            throw new InvalidArgumentException('Work lease must be between 10 and 3600 seconds.');
        }
        if ($this->workId !== null && $this->workId < 1) {
            throw new InvalidArgumentException('An exact work claim requires a positive work ID.');
        }
        if (!in_array($this->lane, ['general', 'opencode-dream'], true)
            || ($this->lane === 'opencode-dream' && $this->workId === null)) {
            throw new InvalidArgumentException('Dream work requires an exact claim in its dedicated worker lane.');
        }
        foreach (['includedWorkTypes', 'excludedWorkTypes'] as $field) {
            foreach ($this->$field as $type) {
                if (!is_string($type) || trim($type) === '') {
                    throw new InvalidArgumentException('Work type filters must be non-empty strings.');
                }
            }
            $this->$field = array_values(array_unique($this->$field));
        }
    }
}
