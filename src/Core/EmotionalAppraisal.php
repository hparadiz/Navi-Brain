<?php

declare(strict_types=1);

namespace NaviBrain\Core;

class EmotionalAppraisal
{

    private const MOOD_CARRY = 0.85;

    /**
     * @param array<string, float> $gauges
     * @param array<string, float> $emotions
     */
    public readonly array $gauges;
    public readonly array $emotions;
    public readonly float $valence;
    public readonly float $arousal;
    public readonly string $dominant;
    public readonly float $moodValence;
    public readonly float $moodArousal;
    private readonly int $wordsFloor;
    private readonly int $wordsCeiling;

    private function __construct()
    {
    }

    /**
     * @param array<string, float> $gauges
     * @param array{valence: float, arousal: float}|null $previousMood
     * @param array{0: int, 1: int} $wordRange
     */
    public static function from(array $gauges, ?array $previousMood, array $wordRange): self {
        $relevance = self::unit($gauges['relevance'] ?? 0.0);
        $desirability = self::signed($gauges['desirability'] ?? 0.0);
        $expectedness = self::unit($gauges['expectedness'] ?? 0.5);
        $control = self::unit($gauges['control'] ?? 0.5);
        $urgency = self::unit($gauges['urgency'] ?? 0.0);
        $standing = self::unit($gauges['standing'] ?? 0.5);

        $good = max(0.0, $desirability);
        $bad = max(0.0, -$desirability);
        $novelty = 1.0 - $expectedness;

        $emotions = [

            'joy' => self::conjoin([$good, $control]),

            'excitement' => self::conjoin([$good, $relevance, $novelty]),

            'sadness' => self::conjoin([$bad, 1.0 - $control]),

            'frustration' => self::conjoin([$bad, $relevance, $control]),

            'anxiety' => self::conjoin([$bad, 1.0 - $control, $urgency]),

            'surprise' => self::conjoin([$novelty, $relevance, 1.0 - abs($desirability)]),

            'boredom' => self::conjoin([1.0 - $relevance, $expectedness]),

            'contentment' => self::conjoin([$good, 1.0 - $urgency, 1.0 - $relevance]),

            'confidence' => self::conjoin([$standing, $control]),
        ];

        $valence = self::unit($emotions['joy'] + $emotions['excitement'] + $emotions['contentment'])
            - self::unit($emotions['sadness'] + $emotions['frustration'] + $emotions['anxiety']);
        $arousal = self::unit(max( $emotions['excitement'], $emotions['frustration'], $emotions['anxiety'], $emotions['surprise'] ) + ($urgency * 0.3));

        arsort($emotions);
        $dominant = (string) array_key_first($emotions);
        if ($emotions[$dominant] < 0.05) {

            $dominant = 'neutral';
        }

        $moodValence = $previousMood === null
            ? $valence
            : (self::MOOD_CARRY * $previousMood['valence']) + ((1.0 - self::MOOD_CARRY) * $valence);
        $moodArousal = $previousMood === null
            ? $arousal
            : (self::MOOD_CARRY * $previousMood['arousal']) + ((1.0 - self::MOOD_CARRY) * $arousal);

        $appraisal = new self();
        $appraisal->gauges = [
                'relevance' => round($relevance, 3),
                'desirability' => round($desirability, 3),
                'expectedness' => round($expectedness, 3),
                'control' => round($control, 3),
                'urgency' => round($urgency, 3),
                'standing' => round($standing, 3),
            ];
        $appraisal->emotions = array_map(static fn (float $v): float => round($v, 3), $emotions);
        $appraisal->valence = round($valence, 3);
        $appraisal->arousal = round($arousal, 3);
        $appraisal->dominant = $dominant;
        $appraisal->moodValence = round($moodValence, 3);
        $appraisal->moodArousal = round($moodArousal, 3);
        $appraisal->wordsFloor = max(3, $wordRange[0]);
        $appraisal->wordsCeiling = max($wordRange[0] + 1, $wordRange[1]);
        return $appraisal;
    }

    public function speechLikelihood(): float
    {
        $base = 0.28
            + (0.55 * max(0.0, $this->valence) * $this->arousal)
            + (0.10 * $this->emotions['confidence']);

        $base -= 0.35 * $this->emotions['sadness'];
        $base -= 0.30 * $this->emotions['frustration'];
        $base -= 0.20 * $this->emotions['anxiety'];
        $base -= 0.15 * $this->emotions['boredom'];

        $base += 0.12 * $this->moodValence;

        return max(0.03, min(0.95, $base));
    }

    public function wordBudget(): int
    {
        $room = $this->emotions['excitement'] * max(0.0, $this->valence);
        $room += 0.35 * $this->emotions['joy'];
        $room -= 0.5 * ($this->emotions['sadness'] + $this->emotions['frustration']);
        $room = max(0.0, min(1.0, $room));

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

    /** @param list<float> $factors */
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
