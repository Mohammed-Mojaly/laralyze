<?php

use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Facades\Laralyze;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

/**
 * What the Requests recorder writes for one finished request.
 */
function recordRequest(string $key, int $status, float $milliseconds): void
{
    Laralyze::record('request', $key, $milliseconds)->avg()->max()->histogram();
    Laralyze::record('request_'.intdiv($status, 100).'xx', $key)->count();

    if ($milliseconds >= 1_000) {
        Laralyze::record('slow_request', $key, $milliseconds)->count()->max();
    }
}

function card(string $name): Testable
{
    return Livewire::withoutLazyLoading()->test("laralyze.{$name}");
}

it('shows an empty state before anything is recorded', function (string $name, string $message) {
    card($name)->assertOk()->assertSee($message);
})->with([
    ['request-totals', 'No requests in the last hour.'],
    ['request-duration', 'No requests in the last hour.'],
    ['routes', 'No requests in the last hour.'],
    ['slow-requests', 'No slow requests in the last hour.'],
]);

it('counts requests by status class', function () {
    recordRequest('GET /users', 200, 20);
    recordRequest('GET /users', 200, 40);
    recordRequest('GET /users', 404, 5);
    recordRequest('POST /orders', 500, 90);
    Laralyze::flush();

    card('request-totals')
        ->assertSeeInOrder(['4', 'requests'])
        ->assertSeeInOrder(['2xx', '2', '50%', '4xx', '1', '25%', '5xx', '1', '25%'])
        ->assertSee('lz-seg lz-s-5xx', escape: false);
});

it('shows average and percentile timings', function () {
    foreach ([10, 20, 30, 40, 1_500] as $milliseconds) {
        recordRequest('GET /users', 200, $milliseconds);
    }
    Laralyze::flush();

    card('request-duration')
        ->assertSee('320 ms')
        ->assertSee('1.50 s')
        ->assertSee('lz-line lz-s-p95', escape: false);
});

it('lists routes with their status classes', function () {
    recordRequest('GET /users/{user}', 200, 20);
    recordRequest('GET /users/{user}', 500, 80);
    recordRequest('POST /orders', 201, 300);
    Laralyze::flush();

    card('routes')
        ->assertSeeInOrder(['GET', '/users/{user}', 'POST', '/orders'])
        ->assertSee('lz-num lz-bad', escape: false)
        ->set('sort', 'avg')
        ->assertSeeInOrder(['orders', 'users']);
});

it('lists slow requests with their threshold', function () {
    recordRequest('GET /reports', 200, 2_400);
    recordRequest('GET /users', 200, 20);
    Laralyze::flush();

    card('slow-requests')
        ->assertSee('/reports')
        ->assertSee('2.40 s')
        ->assertSee('1.00 s')
        ->assertDontSee('/users');
});

it('reads the period from the URL', function () {
    Livewire::withQueryParams(['period' => '7d'])
        ->withoutLazyLoading()
        ->test('laralyze.request-totals')
        ->assertSet('period', '7d')
        ->assertSee('No requests in the last 7 days.');
});

it('falls back to the last hour for an unknown period', function () {
    Livewire::withQueryParams(['period' => 'forever'])
        ->withoutLazyLoading()
        ->test('laralyze.request-totals')
        ->assertSee('No requests in the last hour.');
});

it('shares query results between viewers for a few seconds', function () {
    card('request-totals')->assertSee('No requests in the last hour.');

    recordRequest('GET /users', 200, 20);
    Laralyze::flush();

    card('request-totals')->assertSee('No requests in the last hour.');

    $this->travel(6)->seconds();

    card('request-totals')->assertDontSee('No requests in the last hour.');
});

it('flags scheduled tasks whose next run has long passed', function () {
    Laralyze::record('scheduled', 'reports:send', 120)->avg()->max();
    Laralyze::set('scheduled_task', 'reports:send', (string) json_encode(['expression' => '* * * * *', 'status' => 'processed', 'duration' => 120, 'ran_at' => time() - 600, 'next_at' => time() - 540]));
    Laralyze::record('scheduled', 'backups:run', 900)->avg()->max();
    Laralyze::set('scheduled_task', 'backups:run', (string) json_encode(['expression' => '0 * * * *', 'status' => 'processed', 'duration' => 900, 'ran_at' => time() - 60, 'next_at' => time() + 3_000]));
    Laralyze::flush();

    card('scheduled-tasks')->assertSeeInOrder(['backups:run', 'reports:send', 'overdue']);
});
