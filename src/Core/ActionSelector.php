<?php

declare(strict_types=1);

namespace NaviBrain\Core;

class ActionSelector
{

    public const ACTIONS = ['answer', 'speak', 'look', 'think', 'consolidate'];

    /**
     * @param array<string, bool> $available
     * @param array<string, mixed> $situation
     */
    public static function choose(EmotionalAppraisal $affect, array $available, array $situation): array {
        $scores = [];
        $reasons = [];

        if (($available['answer'] ?? false) === true) {
            return [
                'chosen' => 'answer',
                'because' => 'someone is waiting on an answer, which outranks anything Navi might prefer',
                'scores' => ['answer' => 1.0],
                'action_distribution' => ['answer' => 1.0],
                'baseline_chosen' => 'answer',
                'baseline_scores' => ['answer' => 1.0],
                'baseline_action_distribution' => ['answer' => 1.0],
                'counterfactual_other_model_chosen' => 'answer',
                'counterfactual_other_model_scores' => ['answer' => 1.0],
                'counterfactual_other_model_action_distribution' => ['answer' => 1.0],
                'other_agent_speech_factor' => 1.0,
                'other_agent_counterfactual_speech_factor' => 1.0,
                'other_agent_attention_mode' => $situation['other_agent_attention_mode'] ?? 'unmodeled',
                'other_agent_model_ablated' => ($situation['other_agent_model_ablated'] ?? false) === true,
                'suppressed' => array_values(array_filter( self::ACTIONS, static fn (string $a): bool => $a !== 'answer' && ($available[$a] ?? false) )),
            ];
        }

        $emotions = $affect->emotions;
        $unanswered = (int) ($situation['unexplained_edges'] ?? 0);
        $unconsolidated = (int) ($situation['unconsolidated_episodes'] ?? 0);
        $heldTrack = ($situation['holding_a_track'] ?? false) === true;

        $scores['speak'] = $affect->speechLikelihood()
            * (($situation['user_present'] ?? false) === true ? 1.0 : 0.15);
        $reasons['speak'] = 'there is something worth saying and someone to say it to';

        $scores['look'] = min(1.0, ($unanswered / 6.0))
            * (0.4 + (0.6 * $emotions['surprise']))
            + ($heldTrack ? 0.25 : 0.0);
        $reasons['look'] = 'something was noticed that is not understood, and looking would settle it';

        $scores['think'] = (0.25 + (0.5 * $emotions['boredom']))
            * (1.0 - min(1.0, $unanswered / 8.0));
        $reasons['think'] = 'nothing outside is pressing, so the time goes to thinking';

        $scores['consolidate'] = min(1.0, $unconsolidated / 40.0)
            * (($situation['user_present'] ?? false) === true ? 0.2 : 1.0);
        $reasons['consolidate'] = 'there is a backlog of episodes and nothing more urgent to do';

        foreach (self::ACTIONS as $action) {
            if (($available[$action] ?? false) !== true) {
                unset($scores[$action]);
            }
        }
        if ($scores === []) {
            return [
                'chosen' => null,
                'because' => 'nothing is available to do',
                'scores' => [],
                'suppressed' => [],
            ];
        }

        $baselineScores = $scores;
        $counterfactualScores = $scores;
        $actualFactor = max(0.0, min(1.0, (float) ($situation['other_agent_speech_factor'] ?? 1.0)));
        $counterfactualFactor = max(0.0, min( 1.0, (float) ($situation['other_agent_counterfactual_speech_factor'] ?? $actualFactor) ));
        if (isset($scores['speak'])) {
            $scores['speak'] *= $actualFactor;
            $counterfactualScores['speak'] *= $counterfactualFactor;
        }

        arsort($baselineScores);
        arsort($counterfactualScores);
        arsort($scores);
        $chosen = (string) array_key_first($scores);

        return [
            'chosen' => $chosen,
            'because' => $reasons[$chosen] ?? '',
            'scores' => array_map(static fn (float $v): float => round($v, 3), $scores),
            'action_distribution' => self::distribution($scores),
            'baseline_chosen' => (string) array_key_first($baselineScores),
            'baseline_scores' => array_map(static fn (float $v): float => round($v, 3), $baselineScores),
            'baseline_action_distribution' => self::distribution($baselineScores),
            'counterfactual_other_model_chosen' => (string) array_key_first($counterfactualScores),
            'counterfactual_other_model_scores' => array_map(static fn (float $v): float => round($v, 3), $counterfactualScores),
            'counterfactual_other_model_action_distribution' => self::distribution($counterfactualScores),
            'other_agent_speech_factor' => round($actualFactor, 3),
            'other_agent_counterfactual_speech_factor' => round($counterfactualFactor, 3),
            'other_agent_attention_mode' => $situation['other_agent_attention_mode'] ?? 'unmodeled',
            'other_agent_model_ablated' => ($situation['other_agent_model_ablated'] ?? false) === true,

            'suppressed' => array_values(array_filter( array_keys($scores), static fn (string $a): bool => $a !== $chosen )),
        ];
    }

    /** @param array<string, float> $scores */
    private static function distribution(array $scores): array
    {
        $sum = array_sum(array_map(static fn (float $score): float => max(0.0, $score), $scores));
        if ($scores === []) {
            return [];
        }
        if ($sum <= 0.0) {
            $uniform = 1.0 / count($scores);
            return array_map(static fn (): float => round($uniform, 4), $scores);
        }
        return array_map(static fn (float $score): float => round(max(0.0, $score) / $sum, 4), $scores);
    }
}
