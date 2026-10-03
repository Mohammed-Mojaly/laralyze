<?php

namespace MohammedMojaly\Laralyze\Support;

/**
 * Finds the line in the app's own code that led to something, skipping
 * the framework, vendor packages and Laralyze itself.
 */
final class Location
{
    private static ?string $src = null;

    /**
     * The first app frame of the current call stack, e.g. "app/Models/User.php:42".
     */
    public static function here(): ?string
    {
        return self::fromTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40));
    }

    /**
     * @param  array<int, array<string, mixed>>  $trace
     */
    public static function fromTrace(array $trace, ?string $file = null, ?int $line = null): ?string
    {
        $frames = $file === null ? $trace : [['file' => $file, 'line' => $line], ...$trace];
        $base = self::base();

        foreach ($frames as $frame) {
            $path = $frame['file'] ?? null;

            if (is_string($path) && self::inApp($path, $base)) {
                return self::relative($path).':'.(is_int($frame['line'] ?? null) ? $frame['line'] : 0);
            }
        }

        return null;
    }

    public static function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    public static function isApp(string $path): bool
    {
        return self::inApp($path, self::base());
    }

    /**
     * Runs for every frame of a call stack, so the base path comes from the caller.
     */
    private static function inApp(string $path, string $base): bool
    {
        $path = str_replace('\\', '/', $path);

        return ! str_contains($path, '/vendor/')
            && ! str_starts_with($path, self::$src ??= str_replace('\\', '/', dirname(__DIR__, 2)).'/src/')
            && ! str_contains($path, '/storage/framework/')
            && $path !== $base.'/artisan'
            && ! str_ends_with($path, '/public/index.php');
    }

    private static function base(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/');
    }
}
