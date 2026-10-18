<?php

declare(strict_types=1);

namespace NaviBrain\Support;

use NaviBrain\Core\NarrativeSynthesis;
use NaviBrain\Model\ThoughtArtifact;

class CodexContext
{
    public static function render(bool $includeBackgroundIntentions = true): string
    {
        $personality = self::latestAccepted(NarrativeSynthesis::PERSONALITY_KIND);
        $intentions = $includeBackgroundIntentions
            ? self::latestAccepted(NarrativeSynthesis::INTENTION_KIND) : null;

        $parts = [];
        if ($personality !== null) {
            $parts[] = $personality;
        }
        if ($intentions !== null) {
            $parts[] = $intentions;
        }

        return implode("\n\n", $parts);
    }

    private static function latestAccepted(string $kind): ?string
    {
        $artifacts = ThoughtArtifact::getAllByWhere(['kind' => $kind, 'status' => 'accepted'], ['order' => ['id' => 'DESC'], 'limit' => 1]);
        if ($artifacts === []) {
            return null;
        }
        $content = (string) $artifacts[0]->content;
        return trim($content) === '' ? null : $content;
    }
}
