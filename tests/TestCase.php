<?php

namespace MohammedMojaly\Laralyze\Tests;

use Illuminate\Contracts\Config\Repository;
use Laravel\Ai\AiServiceProvider;
use Livewire\LivewireServiceProvider;
use MohammedMojaly\Laralyze\LaralyzeServiceProvider;
use MohammedMojaly\Laralyze\Support\Outage;
use MohammedMojaly\Laralyze\Tests\Concerns\UsesStorage;
use MohammedMojaly\Laralyze\Tests\Fixtures\AdminsOnly;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Config applied before providers boot, for settings that are only
     * read at boot (recorders, cards, published views).
     *
     * @var array<string, mixed>
     */
    protected static array $bootConfig = [];

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            LaralyzeServiceProvider::class,
            ...(class_exists(AiServiceProvider::class) ? [AiServiceProvider::class] : []),
        ];
    }

    protected function defineEnvironment($app): void
    {
        // An app's own middleware alias, for the dashboard's middleware setting.
        $app['router']->aliasMiddleware('laralyze-admins', AdminsOnly::class);

        tap($app['config'], function (Repository $config) {
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));

            // Cache like a real app: values are serialized, and Laravel 13
            // refuses to rebuild objects from them.
            $config->set('cache.stores.array.serialize', true);
            $config->set('cache.serializable_classes', false);

            // The 1-in-1,000 cleanup after a flush would make tests flaky.
            $config->set('laralyze.trim_lottery', [0, 1]);
            $config->set('laralyze.ingest.lottery', [0, 1]);

            // Most tests read what a flush wrote straight away; the ingest tests switch to the queued path.
            $config->set('laralyze.ingest.driver', 'direct');
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', $this->databaseConnection());

            // LARALYZE_TEST_STORAGE=clickhouse keeps Laralyze's own data in ClickHouse.
            if (getenv('LARALYZE_TEST_STORAGE') === 'clickhouse') {
                $config->set('laralyze.storage.driver', 'clickhouse');
                $config->set('laralyze.storage.clickhouse', [
                    'url' => getenv('LARALYZE_TEST_CLICKHOUSE_URL') ?: 'http://127.0.0.1:8123',
                    'database' => getenv('LARALYZE_TEST_CLICKHOUSE_DATABASE') ?: 'laralyze_test',
                    'username' => getenv('LARALYZE_TEST_CLICKHOUSE_USERNAME') ?: 'default',
                    'password' => getenv('LARALYZE_TEST_CLICKHOUSE_PASSWORD') ?: '',
                    'timeout' => 30,
                    'connect_timeout' => 10,
                    'wait' => true,
                ]);
            }

            foreach (static::$bootConfig as $key => $value) {
                $config->set($key, $value);
            }
        });
    }

    /**
     * Start the app again with the given config in place at boot.
     *
     * @param  array<string, mixed>  $config
     */
    protected function rebootWith(array $config = []): void
    {
        static::$bootConfig = $config;

        $this->refreshApplication();

        if (in_array(UsesStorage::class, class_uses_recursive($this), true)) {
            $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../database/migrations'), '--realpath' => true]);
        }
    }

    protected function tearDown(): void
    {
        static::$bootConfig = [];

        // A failed write in one test must not pause the next.
        Outage::end();

        // Tests switch to production to check the gate; migrations roll back
        // on teardown and would ask for confirmation there.
        $this->app?->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }

    /**
     * LARALYZE_TEST_DB picks the database: sqlite (default), mysql, mariadb,
     * pgsql or sqlsrv. Credentials come from the usual DB_* variables.
     *
     * @return array<string, mixed>
     */
    protected function databaseConnection(): array
    {
        $driver = getenv('LARALYZE_TEST_DB') ?: 'sqlite';

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }

        $defaults = [
            'mysql' => ['port' => 3306, 'username' => 'root'],
            'mariadb' => ['port' => 3306, 'username' => 'root'],
            'pgsql' => ['port' => 5432, 'username' => 'postgres'],
            'sqlsrv' => ['port' => 1433, 'username' => 'sa'],
        ][$driver];

        return [
            'driver' => $driver,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: $defaults['port'],
            'database' => getenv('DB_DATABASE') ?: 'laralyze_test',
            // An empty DB_USERNAME means Windows authentication on SQL Server.
            'username' => getenv('DB_USERNAME') !== false ? getenv('DB_USERNAME') : $defaults['username'],
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'prefix' => '',
            'trust_server_certificate' => true,
        ];
    }
}
