<?php

use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Health;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;
use MohammedMojaly\Laralyze\Support\Outage;

it('pauses recording and says so when ClickHouse is unreachable', function () {
    config(['laralyze.storage.driver' => 'clickhouse', 'laralyze.storage.clickhouse' => ['url' => 'http://127.0.0.1:9', 'password' => 'secret-pw', 'timeout' => 1]]);
    app()->forgetInstance(Storage::class);
    app()->forgetInstance(ClickHouseStorage::class);
    app()->forgetInstance(Health::class);
    app()->detectEnvironment(fn () => 'local');

    Laralyze::record('request', 'GET /')->count();
    $started = microtime(true);
    Laralyze::flush();

    expect(microtime(true) - $started)->toBeLessThan(3.0)
        ->and(Outage::active())->toBeTrue();

    $this->get('/laralyze')->assertSee("Laralyze can't reach ClickHouse at http://127.0.0.1:9.")->assertDontSee('secret-pw');
});

it('asks to run laralyze:install when the ClickHouse tables are missing', function () {
    config(['laralyze.storage.driver' => 'clickhouse']);
    $storage = Mockery::mock(ClickHouseStorage::class);
    $storage->shouldReceive('installed')->andReturn(false);
    app()->instance(ClickHouseStorage::class, $storage);
    app()->forgetInstance(Storage::class);
    app()->forgetInstance(Health::class);
    app()->detectEnvironment(fn () => 'local');

    $this->get('/laralyze')->assertSee('Run `php artisan laralyze:install`.', false);
});
