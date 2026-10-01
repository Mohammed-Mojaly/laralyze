<?php

namespace Laralyze\Support;

class Format
{
    /**
     * 950, 1.2K, 3.4M.
     */
    public static function number(int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        $value = (float) $value;

        return match (true) {
            abs($value) >= 1_000_000 => self::trim($value / 1_000_000).'M',
            abs($value) >= 10_000 => self::trim($value / 1_000).'K',
            default => number_format($value, $value == floor($value) ? 0 : 1),
        };
    }

    /**
     * Milliseconds for humans: 0.4 ms, 86 ms, 1.24 s, 2.5 min.
     */
    public static function duration(int|float|null $milliseconds): string
    {
        if ($milliseconds === null) {
            return '—';
        }

        return match (true) {
            $milliseconds >= 60_000 => self::trim($milliseconds / 60_000).' min',
            $milliseconds >= 1_000 => number_format($milliseconds / 1_000, 2).' s',
            $milliseconds >= 10 => number_format($milliseconds).' ms',
            default => self::trim($milliseconds).' ms',
        };
    }

    /**
     * 512 B, 3.2 GB.
     */
    public static function bytes(int|float|null $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1_024)), count($units) - 1) : 0;

        return self::trim($bytes / 1_024 ** $power).' '.$units[$power];
    }

    public static function percent(int|float|null $part, int|float|null $total): string
    {
        if (! $total) {
            return '—';
        }

        $percent = (float) $part / (float) $total * 100;

        return match (true) {
            $percent == 0.0 => '0%',
            $percent < 0.1 => '<0.1%',
            default => self::trim($percent).'%',
        };
    }

    protected static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
