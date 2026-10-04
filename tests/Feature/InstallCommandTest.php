<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;

function publishedMigrations(): array
{
    return File::glob(database_path('migrations/*_create_laralyze_tables.php'));
}

function cleanUpInstall(): void
{
    File::delete(config_path('laralyze.php'));
    File::delete(publishedMigrations());
}

beforeEach(function () {
    cleanUpInstall();

    // Installing runs real migrations, so keep them away from the shared test database.
    config(['database.connections.install' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    config(['database.default' => 'install']);
});
afterEach(fn () => cleanUpInstall());

it('publishes the config file', function () {
    $this->artisan('laralyze:install')->assertSuccessful();

    expect(config_path('laralyze.php'))->toBeFile()
        ->and(File::get(config_path('laralyze.php')))->toContain('LARALYZE_ENABLED');
});

it('publishes the migration and creates the tables', function () {
    $this->artisan('laralyze:install')->assertSuccessful();

    expect(publishedMigrations())->toHaveCount(1)
        ->and(Schema::hasTable('laralyze_aggregates'))->toBeTrue()
        ->and(Schema::hasTable('laralyze_values'))->toBeTrue()
        ->and(Schema::hasTable('laralyze_executions'))->toBeTrue();
});

it('does not publish the migration twice', function () {
    $this->artisan('laralyze:install')->assertSuccessful();
    $this->artisan('laralyze:install')->assertSuccessful();

    expect(publishedMigrations())->toHaveCount(1);
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

it('creates ClickHouse tables instead of publishing the migration', function () {
    config(['laralyze.storage.driver' => 'clickhouse']);
    $storage = Mockery::mock(ClickHouseStorage::class);
    $storage->shouldReceive('install')->once()->andReturn('26.9.1');
    $storage->shouldReceive('client->url')->andReturn('http://ch.test:8123');
    app()->instance(ClickHouseStorage::class, $storage);

    $this->artisan('laralyze:install')->assertSuccessful()->expectsOutputToContain('ClickHouse at http://ch.test:8123');

    expect(publishedMigrations())->toBe([]);
});

it('warns when ClickHouse is older than supported', function () {
    config(['laralyze.storage.driver' => 'clickhouse']);
    $storage = Mockery::mock(ClickHouseStorage::class);
    $storage->shouldReceive('install')->once()->andReturn('23.8.2');
    $storage->shouldReceive('client->url')->andReturn('http://ch.test:8123');
    app()->instance(ClickHouseStorage::class, $storage);

    $this->artisan('laralyze:install')->assertSuccessful()->expectsOutputToContain('ClickHouse 23.8.2 is older than 24.8');
});
