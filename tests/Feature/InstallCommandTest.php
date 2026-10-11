<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;

// php artisan migrate is followed by its CommandFinished event, as outside tests.
uses(WithConsoleEvents::class);

function publishedMigrations(): array
{
    return File::glob(database_path('migrations/*_create_laralyze_*.php'));
}

function cleanUpInstall(): void
{
    File::delete(config_path('laralyze.php'));
    File::delete(publishedMigrations());
}

/**
 * Artisan and its migrator as a new php artisan migrate would make them,
 * with the config and files as they are now.
 */
function freshMigrator(): void
{
    app()->forgetInstance('migrator');
    app()->forgetInstance(MigrateCommand::class);
    app(ConsoleKernel::class)->setArtisan(null);
}

function laralyzeTables(): array
{
    return array_values(array_filter(['laralyze_aggregates', 'laralyze_values', 'laralyze_executions', 'laralyze_ingest', 'laralyze_logs'], fn (string $table) => Schema::hasTable($table)));
}

beforeEach(function () {
    cleanUpInstall();

    // Installing runs real migrations, so keep them away from the shared test database.
    config(['database.connections.install' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    config(['database.default' => 'install']);
    freshMigrator();
});
afterEach(fn () => cleanUpInstall());

it('creates the tables with php artisan migrate, without copying anything into the app', function () {
    $this->artisan('migrate')->assertSuccessful();

    expect(laralyzeTables())->toHaveCount(5)
        ->and(publishedMigrations())->toBe([]);
});

it('runs only the migrations an older install didn\'t copy into the app', function () {
    // A 0.3 install: the first migration, published under the date it was installed.
    File::copy(__DIR__.'/../../database/migrations/2026_09_30_000000_create_laralyze_tables.php', database_path('migrations/2026_10_04_101500_create_laralyze_tables.php'));

    $this->artisan('migrate')->assertSuccessful();
    $this->artisan('migrate')->assertSuccessful();

    expect(laralyzeTables())->toHaveCount(5)
        ->and(publishedMigrations())->toBe([database_path('migrations/2026_10_04_101500_create_laralyze_tables.php')]);
});

it('leaves tables alone that an older install made, after its copies were pruned', function () {
    // A file, so the tables outlive the first migrate.
    $database = (string) tempnam(sys_get_temp_dir(), 'laralyze');
    config(['database.connections.install.database' => $database]);
    DB::purge('install');

    try {
        // laralyze:install copied the migrations under the date it ran, then schema:dump --prune deleted them.
        foreach (File::glob(__DIR__.'/../../database/migrations/*.php') as $index => $migration) {
            File::copy($migration, database_path('migrations/2026_10_0'.($index + 4).'_101500_'.substr(basename($migration), 18)));
        }
        $this->artisan('migrate')->assertSuccessful();
        cleanUpInstall();
        freshMigrator();

        $this->artisan('migrate')->assertSuccessful();

        expect(laralyzeTables())->toHaveCount(5);
    } finally {
        DB::purge('install');
        File::delete($database);
    }
});

it('leaves the app\'s database alone on ClickHouse, and creates the ClickHouse tables after migrate', function () {
    config(['laralyze.storage.driver' => 'clickhouse']);
    freshMigrator();
    $storage = Mockery::mock(ClickHouseStorage::class);
    $storage->shouldReceive('install')->once()->andReturn('26.9.1');
    $storage->shouldReceive('client->url')->andReturn('http://ch.test:8123');
    app()->instance(ClickHouseStorage::class, $storage);

    $this->artisan('migrate')->assertSuccessful()->expectsOutputToContain('ClickHouse at http://ch.test:8123');

    expect(laralyzeTables())->toBe([]);
});

it('says so when ClickHouse can\'t be reached after migrate, and migrate still succeeds', function () {
    config(['laralyze.storage.driver' => 'clickhouse']);
    freshMigrator();
    $storage = Mockery::mock(ClickHouseStorage::class);
    $storage->shouldReceive('install')->once()->andThrow(new RuntimeException('Connection refused'));
    app()->instance(ClickHouseStorage::class, $storage);

    $this->artisan('migrate')->assertSuccessful()->expectsOutputToContain("Laralyze couldn't create its tables in ClickHouse: Connection refused");
});

it('publishes the config file', function () {
    $this->artisan('laralyze:install')->assertSuccessful();

    expect(config_path('laralyze.php'))->toBeFile()
        ->and(File::get(config_path('laralyze.php')))->toContain('LARALYZE_ENABLED');
});

it('creates the tables on install, without copying the migrations', function () {
    $this->artisan('laralyze:install')->assertSuccessful();

    expect(laralyzeTables())->toHaveCount(5)
        ->and(publishedMigrations())->toBe([]);
});

it('still copies the migrations for apps that want them', function () {
    $this->artisan('vendor:publish', ['--tag' => 'laralyze-migrations'])->assertSuccessful();
    freshMigrator();

    $this->artisan('migrate')->assertSuccessful();

    expect(publishedMigrations())->toHaveCount(3)
        ->and(laralyzeTables())->toHaveCount(5);
});

it('keeps an edited config file when run again', function () {
    File::put(config_path('laralyze.php'), '<?php return ["edited" => true];');

    $this->artisan('laralyze:install')->assertSuccessful();

    expect(File::get(config_path('laralyze.php')))->toContain('edited');
});

it('overwrites the config file when forced', function () {
    File::put(config_path('laralyze.php'), '<?php return ["edited" => true];');

    $this->artisan('laralyze:install', ['--force' => true])->assertSuccessful();

    expect(File::get(config_path('laralyze.php')))->not->toContain('edited');
});

it('creates ClickHouse tables on install', function () {
    config(['laralyze.storage.driver' => 'clickhouse']);
    freshMigrator();
    $storage = Mockery::mock(ClickHouseStorage::class);
    $storage->shouldReceive('install')->once()->andReturn('26.9.1');
    $storage->shouldReceive('client->url')->andReturn('http://ch.test:8123');
    app()->instance(ClickHouseStorage::class, $storage);

    $this->artisan('laralyze:install')->assertSuccessful()->expectsOutputToContain('ClickHouse at http://ch.test:8123');

    expect(publishedMigrations())->toBe([]);
});

it('warns when ClickHouse is older than supported', function () {
    config(['laralyze.storage.driver' => 'clickhouse']);
    freshMigrator();
    $storage = Mockery::mock(ClickHouseStorage::class);
    $storage->shouldReceive('install')->once()->andReturn('23.8.2');
    $storage->shouldReceive('client->url')->andReturn('http://ch.test:8123');
    app()->instance(ClickHouseStorage::class, $storage);

    $this->artisan('laralyze:install')->assertSuccessful()->expectsOutputToContain('ClickHouse 23.8.2 is older than 24.8');
});

it('stops when .env asks for ClickHouse but the published config predates it', function () {
    putenv('LARALYZE_STORAGE=clickhouse');
    config(['laralyze.storage' => ['connection' => null]]);

    try {
        $this->artisan('laralyze:install')->assertFailed()->expectsOutputToContain('config/laralyze.php was published before ClickHouse support');
    } finally {
        putenv('LARALYZE_STORAGE');
    }

    expect(publishedMigrations())->toBe([]);
});
