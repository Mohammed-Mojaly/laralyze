<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MohammedMojaly\Laralyze\Dashboard\Health;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Metrics\Period;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function storedRows(string $type): int
{
    return DB::table('laralyze_aggregates')->where('type', $type)->where('period', Period::MINUTE)->count();
}

function failOneWrite(): void
{
    config(['laralyze.storage.connection' => 'nowhere']);
    Laralyze::record('checkout', 'pro')->count();
    Laralyze::flush();
    config(['laralyze.storage.connection' => null]);
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

    expect(Laralyze::lastFailure()['message'] ?? null)->toContain('[nowhere]');

    $this->get('/laralyze')
        ->assertOk()
        ->assertSee("Laralyze couldn't save data")
        ->assertSee('[nowhere]');
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
        ->and((float) DB::table('laralyze_aggregates')->where('type', 'laralyze_dropped')->where('period', Period::MINUTE)->value('value'))->toBe(3.0);

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
});

it('says so when its database is out of reach', function () {
    config(['laralyze.storage.connection' => 'nowhere']);

    $this->get('/laralyze')->assertOk()->assertSee("Laralyze can't reach its database [nowhere].");

    // Teardown on a real database goes through this connection again.
    config(['laralyze.storage.connection' => null]);
});

it('shows its health in php artisan about', function () {
    Artisan::call('about', ['--only' => 'laralyze']);

    expect(Artisan::output())->toMatch('/Health \.+ OK/');
});
