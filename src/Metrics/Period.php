<?php

namespace Laralyze\Metrics;

/**
 * Metrics are stored at two resolutions: minute buckets for recent,
 * detailed charts and hour buckets for everything older than a day.
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
     * Pick the resolution that can answer a window of the given length.
     */
    public static function forWindow(int $seconds): int
    {
        return $seconds <= self::MINUTE_RETENTION ? self::MINUTE : self::HOUR;
    }
}
