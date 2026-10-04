<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Health;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Metrics\Period;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function storedRows(string $type): int
{
    return laralyzeRows('laralyze_aggregates')->where('type', $type)->where('period', Period::MINUTE)->count();
}

function failOneWrite(): void
{
    unreachableStorage(function () {
        Laralyze::record('checkout', 'pro')->count();
        Laralyze::flush();
    });
}

/**
 * Run the callback with Laralyze's storage out of reach: a missing database
 * connection, or a ClickHouse port nothing listens on.
 */
function unreachableStorage(callable $callback): void
{
    $clickhouse = config('laralyze.storage.clickhouse');
    $swap = function () {
        app()->forgetInstance(Storage::class);
        app()->forgetInstance(ClickHouseStorage::class);
        app()->forgetInstance(Health::class);
    };

    usingClickHouse()
        ? config(['laralyze.storage.clickhouse' => [...$clickhouse, 'url' => 'http://127.0.0.1:9', 'timeout' => 1, 'connect_timeout' => 1]])
        : config(['laralyze.storage.connection' => 'nowhere']);
    $swap();

    try {
        $callback();
    } finally {
        config(['laralyze.storage.connection' => null, 'laralyze.storage.clickhouse' => $clickhouse]);
        $swap();
    }
}

/**
 * Where an unreachable storage is named in messages.
 */
function unreachableName(): string
{
    return usingClickHouse() ? '127.0.0.1:9' : '[nowhere]';
}

function renameLaralyzeTable(string $from, string $to): void
{
    usingClickHouse()
        ? app(ClickHouseStorage::class)->client()->statement("RENAME TABLE {$from} TO {$to}")
        : Schema::rename($from, $to);
}

function asWebRequest(callable $callback): void
{
    (fn () => $this->isRunningInConsole = false)->call(app());

    try {
        $callback();
    } finally {
        (fn () => $this->isRunningInConsole = null)->call(app());
    }
}

it('pauses writing for a minute after a failed write, then tries again', function () {
    $reported = [];
    Laralyze::handleExceptionsUsing(function (Throwable $e) use (&$reported) {
        $reported[] = $e;
    });

    failOneWrite();

    Laralyze::record('checkout', 'pro')->count();
    Laralyze::flush();

    expect($reported)->toHaveCount(1)
        ->and(storedRows('checkout'))->toBe(0)
        ->and(Laralyze::buffer()->isEmpty())->toBeTrue();

    $this->travel(61)->seconds();

    Laralyze::record('checkout', 'pro')->count();
    Laralyze::flush();

    expect(storedRows('checkout'))->toBe(1);
});

it('tells the dashboard why the last write failed', function () {
    failOneWrite();

    expect(Laralyze::lastFailure()['message'] ?? null)->toContain(unreachableName());

    $this->get('/laralyze')
        ->assertOk()
        ->assertSee("Laralyze couldn't save data")
        ->assertSee(unreachableName());
});

it('does not count writes before migrate as failures', function () {
    $reported = [];
    Laralyze::handleExceptionsUsing(function (Throwable $e) use (&$reported) {
        $reported[] = $e;
    });

    renameLaralyzeTable('laralyze_aggregates', 'laralyze_aggregates_away');

    try {
        Laralyze::record('checkout', 'pro')->count();
        Laralyze::flush();
    } finally {
        renameLaralyzeTable('laralyze_aggregates_away', 'laralyze_aggregates');
    }

    expect(Laralyze::lastFailure())->toBeNull()
        ->and($reported)->toBe([]);
});

it('counts what a full web request buffer dropped, and warns about it', function () {
    Laralyze::buffer()->limitTo(4);

    // Each new key takes a minute row and an hour row, so two fit.
    asWebRequest(function () {
        foreach (range(1, 5) as $i) {
            Laralyze::record('import', "row {$i}")->count();
        }
    });

    Laralyze::flush();

    expect(storedRows('import'))->toBe(2)
        ->and((float) laralyzeRows('laralyze_aggregates')->where('type', 'laralyze_dropped')->where('period', Period::MINUTE)->value('value'))->toBe(3.0);

    $this->get('/laralyze')->assertSee('3 metrics were dropped in the last 24 hours.')->assertSee('LARALYZE_BUFFER');
});

it('warns when the scheduler hasn\'t cleaned up for hours', function () {
    Laralyze::record('request', 'GET /', timestamp: time() - 5 * 3_600)->count();
    Laralyze::flush();

    $this->get('/laralyze')->assertSee("The scheduler doesn't seem to run.");

    Laralyze::trim();

    $this->get('/laralyze')->assertDontSee("The scheduler doesn't seem to run.");
});

it('doesn\'t blame the scheduler on a fresh install', function () {
    Laralyze::record('request', 'GET /')->count();
    Laralyze::flush();

    expect(app(Health::class)->problems())->toBe([]);
});

it('asks to migrate when its tables are missing, instead of failing', function () {
    config([
        'database.connections.empty' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'laralyze.storage.connection' => 'empty',
    ]);

    $this->get('/laralyze')
        ->assertOk()
        ->assertSee("Laralyze's tables are missing.")
        ->assertSee('php artisan migrate')
        ->assertDontSee('class="lz-grid"', false);
})->skip(fn () => usingClickHouse(), 'ClickHouseOfflineTest covers ClickHouse.');

it('says so when its database is out of reach', function () {
    unreachableStorage(fn () => $this->get('/laralyze')->assertOk()->assertSee(
        usingClickHouse() ? "Laralyze can't reach ClickHouse at http://127.0.0.1:9." : "Laralyze can't reach its database [nowhere].",
    ));
});

it('shows its health in php artisan about', function () {
    Artisan::call('about', ['--only' => 'laralyze']);

    expect(Artisan::output())->toMatch('/Health \.+ OK/');
});

it('warns when queued jobs never run with Laralyze loaded', function () {
    Laralyze::record('queue_queued', 'default')->count();
    Laralyze::flush();

    $this->travel(15)->minutes();

    expect(array_column(app(Health::class)->problems(), 'title'))->toContain("Queued jobs aren't being recorded.");

    Laralyze::record('queue_processing', 'default')->count();
    Laralyze::flush();

    expect(array_column(app(Health::class)->problems(), 'title'))->not->toContain("Queued jobs aren't being recorded.");
});
