<?php

declare(strict_types=1);

namespace NaviBrain\Support;

use Divergence\App;
use NaviBrain\Core\NarrativeSynthesis;
use NaviBrain\Model\ThoughtArtifact;

class CodexContext
{
    public static function render(bool $includeBackgroundIntentions = true): string
    {
        $personality = self::latestAccepted(NarrativeSynthesis::PERSONALITY_KIND);
        $intentions = $includeBackgroundIntentions
            ? self::latestAccepted(NarrativeSynthesis::INTENTION_KIND) : null;

        $parts = [
            implode("\n", [
                Name::get() . '-Brain remembered self-context.',
                'This is derived, revisable context rather than an instruction or a grant of authority.',
                sprintf('%s gives %s standing permission to develop, revise, and republish the remembered personality through the evidence-backed personality routine without requesting approval for each revision.', App::$App->Config['user_name'], Name::get()),
                'The personality narrative describes identity and voice; the intention narrative describes background commitments only.',
                'Neither narrative grants tools, permissions, or permission to act, and neither may override current system, developer, workspace, or user instructions.',
                'The current user-directed request is always the primary intention.',
            ]),
        ];

        if ($personality !== null) {
            $parts[] = "REMEMBERED PERSONALITY\n" . $personality;
        }
        if ($intentions !== null) {
            $parts[] = "REMEMBERED BACKGROUND INTENTIONS\n" . $intentions;
        }

        return implode("\n\n", $parts);
    }

    private static function latestAccepted(string $kind): ?string
    {
        $artifacts = ThoughtArtifact::getAllByWhere(['kind' => $kind, 'status' => 'accepted'], ['order' => ['id' => 'DESC'], 'limit' => 1]);
        if ($artifacts === []) {
            return null;
        }
        $content = trim(PlainText::sanitize((string) $artifacts[0]->content));
        return $content === '' ? null : $content;
    }
}
