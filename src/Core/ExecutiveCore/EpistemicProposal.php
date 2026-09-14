<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\ThreadStep;

class EpistemicProposal
{
    public CognitiveThread $thread;
    public ThreadStep $step;
    public int $workId;
    public string $operation;
    public ?string $model = null;
    public array $proposal = ['kind' => '', 'content' => '', 'confidence' => 0.0, 'challenged_assumption' => ''];
    public array $checks = [];
}
