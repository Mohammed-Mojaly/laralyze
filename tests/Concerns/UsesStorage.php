<?php

namespace MohammedMojaly\Laralyze\Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
        DB::table('laralyze_aggregates')->truncate();
        DB::table('laralyze_values')->truncate();
        DB::table('laralyze_executions')->truncate();
    }
}
