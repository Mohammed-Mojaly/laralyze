<?php

namespace MohammedMojaly\Laralyze\Metrics;

/**
 * Log-scale buckets for estimating percentiles without keeping every
 * sample. Each bin covers 25% more than the previous one, so estimates
 * land within a few percent of the real value.
 */
final class Histogram
{
    public const BASE = 1.25;

    public const MAX_BIN = 63;

    public static function bin(float $value): int
    {
        if ($value <= 1) {
            return 0;
        }

        return min(self::MAX_BIN, (int) ceil(log($value) / log(self::BASE)));
    }

    public static function lowerBound(int $bin): float
    {
        return $bin === 0 ? 0.0 : self::BASE ** ($bin - 1);
    }

    public static function upperBound(int $bin): float
    {
        return self::BASE ** $bin;
    }

    /**
     * @param  array<int, int|float>  $counts  Sample count per bin.
     */
    public static function percentile(array $counts, float $percentile): ?float
    {
        $counts = array_filter($counts, fn ($count) => $count > 0);

        if ($counts === []) {
            return null;
        }

        ksort($counts);

        $target = array_sum($counts) * $percentile;
        $seen = 0.0;

        foreach ($counts as $bin => $count) {
            if ($seen + $count >= $target) {
                // Assume samples are spread evenly inside the bin.
                $position = $count > 0 ? ($target - $seen) / $count : 0;
                $lower = self::lowerBound($bin);

                return $lower + $position * (self::upperBound($bin) - $lower);
            }

            $seen += $count;
        }

        return self::upperBound(array_key_last($counts));
    }
}
