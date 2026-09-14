<?php

declare(strict_types=1);

namespace NaviBrain\Core;

class ProbabilityScorer
{
    private const EPSILON = 0.000001;

    /**
     * @param array<string, int|float> $distribution
     * @return array<string, float>
     */
    public static function normalize(array $distribution, array $domain): array
    {
        $normalized = [];
        foreach (array_values(array_unique($domain)) as $value) {
            $normalized[$value] = max(0.0, (float) ($distribution[$value] ?? 0.0));
        }
        $sum = array_sum($normalized);
        if ($sum <= 0.0) {
            $uniform = $normalized === [] ? 0.0 : 1.0 / count($normalized);
            return array_map(static fn (): float => $uniform, $normalized);
        }
        return array_map(static fn (float $value): float => round($value / $sum, 6), $normalized);
    }

    /** @param array<string, int|float> $distribution */
    public static function probability(array $distribution, string $observed): float
    {
        return max(self::EPSILON, min(1.0, (float) ($distribution[$observed] ?? 0.0)));
    }

    /** @param array<string, int|float> $distribution */
    public static function brier(array $distribution, string $observed): float
    {
        $sum = 0.0;
        foreach ($distribution as $value => $probability) {
            $target = (string) $value === $observed ? 1.0 : 0.0;
            $sum += ((float) $probability - $target) ** 2;
        }
        return round($sum, 6);
    }

    /** @param array<string, int|float> $distribution */
    public static function logLoss(array $distribution, string $observed): float
    {
        return round(-log(self::probability($distribution, $observed)), 6);
    }

    public static function entropy(array $distribution): float
    {
        $count = count($distribution);
        if ($count < 2) {
            return 0.0;
        }
        $entropy = 0.0;
        foreach ($distribution as $probability) {
            $probability = (float) $probability;
            if ($probability > 0.0) {
                $entropy -= $probability * log($probability);
            }
        }
        return round(max(0.0, min(1.0, $entropy / log($count))), 6);
    }

    /** @param array<string, int|float> $distribution */
    public static function top(array $distribution): array
    {
        if ($distribution === []) {
            return ['value' => null, 'probability' => 0.0];
        }
        arsort($distribution);
        $value = (string) array_key_first($distribution);
        return ['value' => $value, 'probability' => round((float) $distribution[$value], 6)];
    }
}
