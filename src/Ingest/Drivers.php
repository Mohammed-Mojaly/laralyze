<?php

namespace MohammedMojaly\Laralyze\Ingest;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use MohammedMojaly\Laralyze\Contracts\Ingest;
use MohammedMojaly\Laralyze\Contracts\Storage;

/**
 * Picks how flushes reach storage, from `laralyze.ingest.driver`.
 */
final class Drivers
{
    /**
     * Databases where concurrent upserts lock each other out.
     */
    public const QUEUED = ['mysql', 'mariadb', 'pgsql', 'sqlsrv'];

    /**
     * 'database' or 'direct'. Left empty, busy databases get 'database', and
     * SQLite, where one writer at a time is the rule anyway, gets 'direct'.
     * ClickHouse only ever appends, so it always writes directly.
     */
    public static function name(Application $app): string
    {
        $config = $app->make('config');

        if ($config->get('laralyze.storage.driver', 'database') === 'clickhouse') {
            return 'direct';
        }

        $driver = $config->get('laralyze.ingest.driver');

        if ($driver !== null && $driver !== '') {
            return match ($driver) {
                'database', 'direct' => $driver,
                default => throw new InvalidArgumentException("Laralyze's ingest driver [{$driver}] isn't supported. Use database or direct."),
            };
        }

        $connection = $config->get('laralyze.storage.connection') ?? $config->get('database.default');

        return in_array($config->get("database.connections.{$connection}.driver"), self::QUEUED, true) ? 'database' : 'direct';
    }

    public static function resolve(Application $app): Ingest
    {
        return self::name($app) === 'database'
            ? $app->make(DatabaseIngest::class)
            : new DirectIngest($app->make(Storage::class));
    }
}
