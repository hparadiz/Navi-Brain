<?php

declare(strict_types=1);

namespace NaviBrain\Core;

/**
 * Appraisal-based affect, computed from live state on every step.
 *
 * This is not a description of a mood handed to a model and it is not a style
 * instruction. It is a calculator: six gauges read off things that are actually
 * measured elsewhere in the system, combined into named emotions, and used to
 * decide whether Navi speaks at all and how much room the answer gets.
 *
 * The structure follows Marsella and Gratch's EMA (2009). Their appraisal
 * checks are relevance (novelty and bearing on goals), implication (cause,
 * goal conduciveness, urgency), coping potential (control, and the power to
 * exercise it) and normative significance (standing against expectations).
 * Specific emotions are "associated with certain configurations of these
 * criteria" rather than being primitives, which is why nothing here stores an
 * emotion directly: joy and frustration are readings, not variables.
 *
 * EMA treats the checks as "uniformly lightweight, fast and operating in
 * parallel", which is the property that makes this affordable every step. None
 * of these gauges costs a model call.
 *
 * Mood is the slow term EMA allows as an "incidental influence on emotional
 * state": an exponential carry of recent valence and arousal, so a bad hour
 * still colours a good minute instead of affect resetting on each wake.
 */
final class EmotionalAppraisal
{
    /** How much of the previous mood survives into the next reading. */
    private const MOOD_CARRY = 0.85;

    /**
     * @param array<string, float> $gauges
     * @param array<string, float> $emotions
     */
    private function __construct(
        public readonly array $gauges,
        public readonly array $emotions,
        public readonly float $valence,
        public readonly float $arousal,
        public readonly string $dominant,
        public readonly float $moodValence,
        public readonly float $moodArousal,
        /**
         * The shortest and longest turn available, in words. Supplied rather
         * than defined here: how long Navi may hold the floor is a fact about
         * the voice and the situation, not about how emotion works, and the
         * caller derives it from a measured speaking rate.
         */
        private readonly int $wordsFloor,
        private readonly int $wordsCeiling
    ) {
    }

    /**
     * @param array<string, float> $gauges relevance, desirability, expectedness,
     *                                     control, urgency, standing
     * @param array{valence: float, arousal: float}|null $previousMood
     * @param array{0: int, 1: int} $wordRange shortest and longest turn, in words.
     *        Required, with no default: a default here would be exactly the
     *        hard-coded length this class is not supposed to own.
     */
    public static function from(
        array $gauges,
        ?array $previousMood,
        array $wordRange
    ): self {
        $relevance = self::unit($gauges['relevance'] ?? 0.0);
        $desirability = self::signed($gauges['desirability'] ?? 0.0);
        $expectedness = self::unit($gauges['expectedness'] ?? 0.5);
        $control = self::unit($gauges['control'] ?? 0.5);
        $urgency = self::unit($gauges['urgency'] ?? 0.0);
        $standing = self::unit($gauges['standing'] ?? 0.5);

        $good = max(0.0, $desirability);
        $bad = max(0.0, -$desirability);
        $novelty = 1.0 - $expectedness;

        // Configurations, not primitives. Each reads as a sentence about the
        // person-environment relationship, which is what an appraisal is.
        //
        // Combined as a geometric mean rather than a product. A product
        // punishes an emotion for being defined by more conditions, so the
        // two-factor ones outranked the three-factor ones by construction and
        // the dominant reading came out "surprise" for a situation that was
        // plainly anxiety. The mean keeps the conjunctive meaning — any factor
        // near zero still collapses the whole reading — while leaving states
        // comparable to each other.
        $emotions = [
            // Things are going well and Navi has a hand in it.
            'joy' => self::conjoin([$good, $control]),
            // Going well, it matters, and it is new. This is the state that
            // earns room to talk: something was found, not merely survived.
            'excitement' => self::conjoin([$good, $relevance, $novelty]),
            // Going badly with no way to act on it.
            'sadness' => self::conjoin([$bad, 1.0 - $control]),
            // Going badly, it matters, and acting is possible. Frustration is
            // the one that wants to do something rather than say something.
            'frustration' => self::conjoin([$bad, $relevance, $control]),
            // Going badly, no control, and it will not wait.
            'anxiety' => self::conjoin([$bad, 1.0 - $control, $urgency]),
            // The world did something unmodelled, and it is not yet known
            // whether that is good. EMA runs the relevance check ahead of the
            // implication check, so surprise is the reading you have before
            // implications land; once desirability is clear it gives way to the
            // valenced emotion instead of competing with it.
            'surprise' => self::conjoin([$novelty, $relevance, 1.0 - abs($desirability)]),
            // Nothing bears on anything and it is all foreseeable.
            'boredom' => self::conjoin([1.0 - $relevance, $expectedness]),
            // Fine, unhurried, nothing demanding attention.
            'contentment' => self::conjoin([$good, 1.0 - $urgency, 1.0 - $relevance]),
            // Lines have been landing, or they have not.
            'confidence' => self::conjoin([$standing, $control]),
        ];

        $valence = self::unit($emotions['joy'] + $emotions['excitement'] + $emotions['contentment'])
            - self::unit($emotions['sadness'] + $emotions['frustration'] + $emotions['anxiety']);
        $arousal = self::unit(max(
            $emotions['excitement'],
            $emotions['frustration'],
            $emotions['anxiety'],
            $emotions['surprise']
        ) + ($urgency * 0.3));

        arsort($emotions);
        $dominant = (string) array_key_first($emotions);
        if ($emotions[$dominant] < 0.05) {
            // Nothing is strong enough to name. Saying "neutral" is honest;
            // picking the largest of eight near-zero numbers is not.
            $dominant = 'neutral';
        }

        $moodValence = $previousMood === null
            ? $valence
            : (self::MOOD_CARRY * $previousMood['valence']) + ((1.0 - self::MOOD_CARRY) * $valence);
        $moodArousal = $previousMood === null
            ? $arousal
            : (self::MOOD_CARRY * $previousMood['arousal']) + ((1.0 - self::MOOD_CARRY) * $arousal);

        return new self(
            gauges: [
                'relevance' => round($relevance, 3),
                'desirability' => round($desirability, 3),
                'expectedness' => round($expectedness, 3),
                'control' => round($control, 3),
                'urgency' => round($urgency, 3),
                'standing' => round($standing, 3),
            ],
            emotions: array_map(static fn (float $v): float => round($v, 3), $emotions),
            valence: round($valence, 3),
            arousal: round($arousal, 3),
            dominant: $dominant,
            moodValence: round($moodValence, 3),
            moodArousal: round($moodArousal, 3),
            wordsFloor: max(3, $wordRange[0]),
            wordsCeiling: max($wordRange[0] + 1, $wordRange[1])
        );
    }

    /**
     * How likely speaking is to be worth it right now, before anything is said.
     *
     * Low and negative states suppress rather than silence. Someone who is flat
     * still speaks occasionally, and a rule that made sadness mute would be a
     * different thing from a rule that makes it quiet.
     */
    public function speechLikelihood(): float
    {
        $base = 0.28
            + (0.55 * max(0.0, $this->valence) * $this->arousal)
            + (0.10 * $this->emotions['confidence']);

        // Being down or wound up both reduce the appetite to talk, and they do
        // it for different reasons: sadness withdraws, frustration turns
        // towards the problem instead of towards the room.
        $base -= 0.35 * $this->emotions['sadness'];
        $base -= 0.30 * $this->emotions['frustration'];
        $base -= 0.20 * $this->emotions['anxiety'];
        $base -= 0.15 * $this->emotions['boredom'];

        // Mood drags the immediate reading toward where the day has been.
        $base += 0.12 * $this->moodValence;

        return max(0.03, min(0.95, $base));
    }

    /**
     * How many words this state has earned.
     *
     * Excitement is the only thing that buys paragraphs, and it is scaled by
     * valence so that being merely agitated does not. Sadness and frustration
     * shorten: when Navi does speak from those, it should be clipped.
     */
    public function wordBudget(): int
    {
        $room = $this->emotions['excitement'] * max(0.0, $this->valence);
        $room += 0.35 * $this->emotions['joy'];
        $room -= 0.5 * ($this->emotions['sadness'] + $this->emotions['frustration']);
        $room = max(0.0, min(1.0, $room));

        // Curved so the top of the range is reserved for genuinely high states
        // rather than reached by any mildly good mood.
        $curved = $room ** 1.6;
        $budget = $this->wordsFloor + (int) round($curved * ($this->wordsCeiling - $this->wordsFloor));
        return max($this->wordsFloor, min($this->wordsCeiling, $budget));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'gauges' => $this->gauges,
            'emotions' => $this->emotions,
            'valence' => $this->valence,
            'arousal' => $this->arousal,
            'dominant' => $this->dominant,
            'mood' => ['valence' => $this->moodValence, 'arousal' => $this->moodArousal],
            'speech_likelihood' => round($this->speechLikelihood(), 3),
            'word_budget' => $this->wordBudget(),
        ];
    }

    /**
     * Geometric mean of the conditions an emotion requires.
     *
     * Conjunctive like a product — one condition at zero zeroes the reading —
     * but independent of how many conditions there are.
     *
     * @param list<float> $factors
     */
    private static function conjoin(array $factors): float
    {
        $product = 1.0;
        foreach ($factors as $factor) {
            $factor = self::unit($factor);
            if ($factor <= 0.0) {
                return 0.0;
            }
            $product *= $factor;
        }
        return $product ** (1.0 / count($factors));
    }

    private static function unit(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private static function signed(float $value): float
    {
        return max(-1.0, min(1.0, $value));
    }
}
