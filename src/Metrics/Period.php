<?php

namespace MohammedMojaly\Laralyze\Metrics;

/**
 * Metrics are stored at two resolutions: minute buckets for the last day,
 * read for windows shorter than a day, and hour buckets for the rest.
 */
final class Period
{
    public const MINUTE = 60;

    public const HOUR = 3600;

    public const ALL = [self::MINUTE, self::HOUR];

    /**
     * How long minute buckets are kept, in seconds.
     */
    public const MINUTE_RETENTION = 86_400;

    public static function bucket(int $timestamp, int $period): int
    {
        return $timestamp - ($timestamp % $period);
    }

    /**
     * Pick the resolution that answers a window of the given length. From a
     * day on, hour buckets: 24 rows per key instead of 1,440, at the cost of
     * starting at the top of the hour.
     */
    public static function forWindow(int $seconds): int
    {
        return $seconds < self::MINUTE_RETENTION ? self::MINUTE : self::HOUR;
    }
}
