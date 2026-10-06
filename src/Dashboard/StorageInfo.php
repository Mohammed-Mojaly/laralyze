<?php

namespace MohammedMojaly\Laralyze\Dashboard;

use Illuminate\Contracts\Foundation\Application;
use MohammedMojaly\Laralyze\Ingest\Drivers;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Client;

/**
 * Where Laralyze keeps its data, for the sidebar. Names only, never credentials.
 */
final class StorageInfo
{
    public const NAMES = [
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'pgsql' => 'PostgreSQL',
        'sqlite' => 'SQLite',
        'sqlsrv' => 'SQL Server',
    ];

    /**
     * @return array{label: string, writes: string|null, detail: string}
     */
    public static function describe(Application $app): array
    {
        $config = $app->make('config');

        if ($config->get('laralyze.storage.driver', 'database') === 'clickhouse') {
            $database = (string) $config->get('laralyze.storage.clickhouse.database', 'default');
            $url = Client::displayUrl((string) $config->get('laralyze.storage.clickhouse.url', 'http://127.0.0.1:8123'));

            return [
                'label' => "ClickHouse · {$database}",
                'writes' => null,
                'detail' => "Storage: ClickHouse at {$url}, database {$database}.",
            ];
        }

        $connection = (string) ($config->get('laralyze.storage.connection') ?? $config->get('database.default'));
        $driver = (string) $config->get("database.connections.{$connection}.driver");
        $kind = self::NAMES[$driver] ?? $driver;
        $database = (string) $config->get("database.connections.{$connection}.database");

        // A SQLite path says more than it should, and is long.
        if ($driver === 'sqlite' && $database !== ':memory:') {
            $database = basename($database);
        }

        $queued = Drivers::name($app) === 'database';

        return [
            'label' => "{$kind} · {$database}",
            'writes' => $queued ? 'Queued writes' : 'Direct writes',
            'detail' => "Storage: database ({$kind}), connection [{$connection}], database {$database}. "
                .($queued ? 'Writes wait in laralyze_ingest and are merged every minute.' : 'Each request, job and command writes directly.'),
        ];
    }
}
