<?php

declare(strict_types=1);

namespace NaviBrain\Core;

/**
 * Choosing one thing to do, and suppressing the rest.
 *
 * Navi has four ways to spend a wake — speak, look, think, consolidate — and
 * until now each carried its own gate on its own rhythm with nothing arbitrating
 * between them. That is not a small omission. Several of the worst failures so
 * far were subsystems firing in parallel and starving each other: a background
 * worker holding the heartbeat for over two minutes while someone waited to be
 * answered, a stale alarm preempting every interrupt, the reflection pass and
 * the speech pass queueing behind the same single inference slot.
 *
 * The missing organ is the one that picks. Selection here follows the shape the
 * basal ganglia are usually described in: candidates compete on a common
 * currency, the winner is released, and the losers are actively inhibited rather
 * than merely not chosen. Inhibition is the part that matters — an action that
 * simply was not selected will be retried immediately by its own gate, which is
 * how parallel starvation happens in the first place.
 *
 * The currency already exists. Affect computes valence, arousal and urgency
 * every step and nothing spent them. This is what spends them.
 */
final class ActionSelector
{
    /**
     * Everything Navi can decide to do with a wake.
     *
     * Kept as data rather than as branches, so adding a capability is a matter
     * of describing what it wants rather than editing the arbiter.
     */
    public const ACTIONS = ['answer', 'speak', 'look', 'think', 'consolidate'];

    /**
     * @param array<string, bool> $available which actions could run at all
     * @param array<string, mixed> $situation counts and flags read from live state
     */
    public static function choose(
        EmotionalAppraisal $affect,
        array $available,
        array $situation
    ): array {
        $scores = [];
        $reasons = [];

        // Answering is not scored against the others. Someone waiting is not a
        // competing preference, it is the situation having already decided.
        // Scoring it would eventually let a good mood outrank a person.
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
                'suppressed' => array_values(array_filter(
                    self::ACTIONS,
                    static fn (string $a): bool => $a !== 'answer' && ($available[$a] ?? false)
                )),
            ];
        }

        $emotions = $affect->emotions;
        $unanswered = (int) ($situation['unexplained_edges'] ?? 0);
        $unconsolidated = (int) ($situation['unconsolidated_episodes'] ?? 0);
        $heldTrack = ($situation['holding_a_track'] ?? false) === true;

        // Speaking wants a reason and an audience. Excitement buys it, sadness
        // and frustration take it away, and the appraisal already encodes both.
        $scores['speak'] = $affect->speechLikelihood()
            * (($situation['user_present'] ?? false) === true ? 1.0 : 0.15);
        $reasons['speak'] = 'there is something worth saying and someone to say it to';

        // Looking is what curiosity does instead of speculating. It rises with
        // things noticed and not explained, and with holding a track that could
        // be moved by finding something out.
        $scores['look'] = min(1.0, ($unanswered / 6.0))
            * (0.4 + (0.6 * $emotions['surprise']))
            + ($heldTrack ? 0.25 : 0.0);
        $reasons['look'] = 'something was noticed that is not understood, and looking would settle it';

        // Thinking is the default when nothing external is pressing. Boredom is
        // its signal, which is what boredom is for.
        $scores['think'] = (0.25 + (0.5 * $emotions['boredom']))
            * (1.0 - min(1.0, $unanswered / 8.0));
        $reasons['think'] = 'nothing outside is pressing, so the time goes to thinking';

        // Consolidation is housekeeping and should lose to anything live. It
        // wins when the backlog is large and the room is quiet, which is the
        // condition sleep exists for.
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

        // The other-agent model is allowed to inhibit unprompted speech only.
        // Keep the pre-model and counterfactual distributions beside the actual
        // one so functional uptake is measurable and ablation changes no input.
        $baselineScores = $scores;
        $counterfactualScores = $scores;
        $actualFactor = max(0.0, min(1.0, (float) ($situation['other_agent_speech_factor'] ?? 1.0)));
        $counterfactualFactor = max(0.0, min(
            1.0,
            (float) ($situation['other_agent_counterfactual_speech_factor'] ?? $actualFactor)
        ));
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
            'counterfactual_other_model_scores' => array_map(
                static fn (float $v): float => round($v, 3),
                $counterfactualScores
            ),
            'counterfactual_other_model_action_distribution' => self::distribution($counterfactualScores),
            'other_agent_speech_factor' => round($actualFactor, 3),
            'other_agent_counterfactual_speech_factor' => round($counterfactualFactor, 3),
            'other_agent_attention_mode' => $situation['other_agent_attention_mode'] ?? 'unmodeled',
            'other_agent_model_ablated' => ($situation['other_agent_model_ablated'] ?? false) === true,
            // Named explicitly. A losing action is inhibited for this wake, not
            // merely unpicked, so it does not turn round and run itself anyway.
            'suppressed' => array_values(array_filter(
                array_keys($scores),
                static fn (string $a): bool => $a !== $chosen
            )),
        ];
    }

    /** @param array<string, float> $scores @return array<string, float> */
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
        return array_map(
            static fn (float $score): float => round(max(0.0, $score) / $sum, 4),
            $scores
        );
    }
}
