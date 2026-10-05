<?php

namespace MohammedMojaly\Laralyze\Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Schema;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;

trait UsesStorage
{
    use RefreshDatabase;

    /**
     * Laralyze refuses to write inside an open transaction, so tests can't
     * rely on transaction rollbacks. Tables are truncated instead.
     *
     * @var list<string>
     */
    protected $connectionsToTransact = [];

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function afterRefreshingDatabase(): void
    {
        if (config('laralyze.storage.driver') === 'clickhouse') {
            $storage = app(ClickHouseStorage::class);

            // Tables once per run, emptied before every test.
            static $installed = false;
            $installed = $installed || $storage->install() !== '';

            foreach (Schema::TABLES as $table) {
                $storage->client()->statement("TRUNCATE TABLE {$table}");
            }

            return;
        }

        DB::table('laralyze_aggregates')->truncate();
        DB::table('laralyze_values')->truncate();
        DB::table('laralyze_executions')->truncate();
        DB::table('laralyze_ingest')->truncate();
    }
}
