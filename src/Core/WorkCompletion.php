<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use InvalidArgumentException;
use NaviBrain\Model\WorkItem;

class WorkCompletion
{
    public bool $succeeded = false;
    public array $result = [];
    public ?string $model = null;
    public ?string $error = null;

    public function __construct(public WorkItem $work)
    {
    }

    public static function success(WorkItem $work, array $result, string $model): static
    {
        $completion = new static($work);
        $completion->succeeded = true;
        $completion->result = $result;
        $completion->model = $model;
        return $completion;
    }

    public static function failure(WorkItem $work, string $error, ?string $model = null): static
    {
        $completion = new static($work);
        $completion->error = $error;
        $completion->model = $model;
        return $completion;
    }

    public function validate(): void
    {
        if ((int) $this->work->id < 1 || trim((string) $this->work->lease_owner) === '') {
            throw new InvalidArgumentException('Work completion requires a leased work item.');
        }
        if ($this->succeeded && trim((string) $this->model) === '') {
            throw new InvalidArgumentException('Successful work requires a model identifier.');
        }
        if (!$this->succeeded && trim((string) $this->error) === '') {
            throw new InvalidArgumentException('Failed work requires an error.');
        }
    }
}
