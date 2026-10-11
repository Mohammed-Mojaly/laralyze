<?php

namespace MohammedMojaly\Laralyze\Database;

/**
 * One line on the Database page: what was checked, how it looks, and what
 * to do about it. A check the database user isn't allowed to run only
 * names the privilege it needs, and isn't shown.
 */
final class Check
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const PROBLEM = 'problem';

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function make(string $key, string $label, string $status, string $value, string $advice = '', ?float $percent = null, array $rows = []): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'value' => $value, 'advice' => $status === self::OK ? '' : $advice, 'percent' => $percent, 'rows' => $rows];
    }

    /**
     * @return array{key: string, needs: string}
     */
    public static function needs(string $key, string $privilege): array
    {
        return ['key' => $key, 'needs' => $privilege];
    }

    /**
     * A share against its warning and problem marks.
     */
    public static function status(float $percent, float $warning = 70, float $problem = 90): string
    {
        return match (true) {
            $percent >= $problem => self::PROBLEM,
            $percent >= $warning => self::WARNING,
            default => self::OK,
        };
    }
}
