<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use RuntimeException;

class ModelRateLimit extends RuntimeException
{
    public readonly int $retryAt;

    public function __construct(array $headers)
    {
        $value = trim((string) (array_change_key_case($headers, CASE_LOWER)['retry-after'] ?? ''));
        $now = time();
        $this->retryAt = ctype_digit($value)
            ? $now + min(PHP_INT_MAX - $now, (int) $value)
            : max($now, strtotime($value) ?: $now);
        parent::__construct('OpenCode provider rate limit (HTTP 429); retry deferred.');
    }
}
