<?php

namespace Laralyze\Dashboard;

/**
 * The time windows offered by the period selector.
 */
enum Range: string
{
    case FifteenMinutes = '15m';
    case Hour = '1h';
    case Day = '24h';
    case Week = '7d';
    case TwoWeeks = '14d';
    case Month = '30d';

    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Hour) : self::Hour;
    }

    public function seconds(): int
    {
        return match ($this) {
            self::FifteenMinutes => 900,
            self::Hour => 3_600,
            self::Day => 86_400,
            self::Week => 604_800,
            self::TwoWeeks => 1_209_600,
            self::Month => 2_592_000,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FifteenMinutes => 'last 15 minutes',
            self::Hour => 'last hour',
            self::Day => 'last 24 hours',
            self::Week => 'last 7 days',
            self::TwoWeeks => 'last 14 days',
            self::Month => 'last 30 days',
        };
    }

    /**
     * How a point on a chart is labelled for this window.
     */
    public function timeFormat(): string
    {
        return match ($this) {
            self::FifteenMinutes, self::Hour => 'H:i',
            default => 'M j, H:i',
        };
    }
}
