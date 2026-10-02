<?php

namespace MohammedMojaly\Laralyze\Support;

/**
 * Finds the line in the app's own code that led to something, skipping
 * the framework, vendor packages and Laralyze itself.
 */
final class Location
{
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

        foreach ($frames as $frame) {
            $path = $frame['file'] ?? null;

            if (is_string($path) && self::isApp($path)) {
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
        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', base_path()), '/');

        return ! str_contains($path, '/vendor/')
            && ! str_starts_with($path, str_replace('\\', '/', dirname(__DIR__, 2)).'/src/')
            && ! str_contains($path, '/storage/framework/')
            && $path !== $base.'/artisan'
            && ! str_ends_with($path, '/public/index.php');
    }
}
