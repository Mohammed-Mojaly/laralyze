<?php

namespace MohammedMojaly\Laralyze\Support;

use Illuminate\Support\Facades\Date;

/**
 * After a failed write, Laralyze stops writing for a minute. A database
 * that is down would otherwise make every request wait for its own
 * connection timeout.
 *
 * The pause is shared through APCu when it's available (PHP-FPM), and
 * kept in the process otherwise (Octane, queue workers, commands).
 */
final class Outage
{
    public const PAUSE = 60;

    private static int $until = 0;

    private static ?string $key = null;

    public static function active(): bool
    {
        // Laravel's clock rather than time(), so tests can travel past the pause.
        $now = Date::now()->getTimestamp();

        if (self::$until > $now) {
            return true;
        }

        if (self::apcu()) {
            self::$until = (int) apcu_fetch(self::key());
        }

        return self::$until > $now;
    }

    public static function start(): void
    {
        self::$until = Date::now()->getTimestamp() + self::PAUSE;

        if (self::apcu()) {
            apcu_store(self::key(), self::$until, self::PAUSE);
        }
    }

    public static function end(): void
    {
        self::$until = 0;

        if (self::apcu()) {
            apcu_delete(self::key());
        }
    }

    private static function apcu(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * Apps on the same server share APCu.
     */
    private static function key(): string
    {
        return self::$key ??= 'laralyze:outage:'.md5(base_path());
    }
}
