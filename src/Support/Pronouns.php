<?php

declare(strict_types=1);

namespace NaviBrain\Support;

class Pronouns
{
    public function __construct(public string $subject = 'she', public string $object = 'her', public string $reflexive = 'herself')
    {
    }

    public static function get(): self
    {
        static $pronouns = null;
        return $pronouns ??= new self(...(require dirname(__DIR__, 2) . '/config/app.php')['pronouns']);
    }

    public function subjectWithBe(): string
    {
        return $this->subject . (strtolower($this->subject) === 'they' ? ' are' : ' is');
    }
}
