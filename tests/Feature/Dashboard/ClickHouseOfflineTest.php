<?php

use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Health;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;
use MohammedMojaly\Laralyze\Support\Outage;

it('pauses recording and says so when ClickHouse is unreachable', function () {
    config(['laralyze.storage.driver' => 'clickhouse', 'laralyze.storage.clickhouse' => ['url' => 'http://admin:url-pw@127.0.0.1:9', 'password' => 'secret-pw', 'timeout' => 1]]);
    app()->forgetInstance(Storage::class);
    app()->forgetInstance(ClickHouseStorage::class);
    app()->forgetInstance(Health::class);
    app()->detectEnvironment(fn () => 'local');

    Laralyze::record('request', 'GET /')->count();
    $started = microtime(true);
    Laralyze::flush();

    expect(microtime(true) - $started)->toBeLessThan(3.0)
        ->and(Outage::active())->toBeTrue();

    $this->get('/laralyze')->assertSee("Laralyze can't reach ClickHouse at http://127.0.0.1:9.")->assertDontSee('secret-pw')->assertDontSee('url-pw');
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

it('gives up within the timeout when ClickHouse accepts connections but never answers', function () {
    // Connections complete in the listen backlog, but nothing ever replies.
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = (string) stream_socket_get_name($server, false);

    config(['laralyze.storage.driver' => 'clickhouse', 'laralyze.storage.clickhouse' => ['url' => "http://{$address}", 'timeout' => 1]]);
    app()->forgetInstance(Storage::class);
    app()->forgetInstance(ClickHouseStorage::class);

    Laralyze::record('request', 'GET /')->count();
    $started = microtime(true);
    Laralyze::flush();

    expect(microtime(true) - $started)->toBeLessThan(4.0)
        ->and(Outage::active())->toBeTrue();

    fclose($server);
});
