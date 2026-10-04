<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Http\Middleware\Authorize;
use MohammedMojaly\Laralyze\Recorders\Requests;

/**
 * @return array<string, stdClass>
 */
function stored(string $type, array $aggregates = ['count']): array
{
    return app(Storage::class)->aggregate($type, $aggregates, 3_600)->keyBy('key')->all();
}

/**
 * Feed one finished request to a recorder built with the given options.
 *
 * @param  array<string, mixed>  $config
 */
function finish(Request $request, int $status = 200, float $milliseconds = 20, array $config = []): void
{
    $recorder = app()->make(Requests::class, ['config' => $config]);

    $recorder->recordRequest(Carbon::now()->subMilliseconds((int) $milliseconds), $request, new Response('', $status));

    Laralyze::flush();
}

function routed(string $method, string $uri, string $template): Request
{
    $request = Request::create($uri, $method);
    $route = (new Illuminate\Routing\Route([$method], $template, fn () => null))->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('records real requests by route template after the response', function () {
    Route::get('/users/{user}', fn (string $user) => "user {$user}");

    $this->get('/users/1')->assertOk();
    $this->get('/users/2')->assertOk();

    expect(stored('request', ['count', 'avg', 'max', 'p95']))->toHaveKey('GET /users/{user}')
        ->and(stored('request')['GET /users/{user}']->count)->toBe(2.0)
        ->and(stored('request_2xx')['GET /users/{user}']->count)->toBe(2.0);
});

it('splits requests by status class', function () {
    finish(routed('GET', '/a', '/a'), 200);
    finish(routed('GET', '/a', '/a'), 302);
    finish(routed('GET', '/a', '/a'), 422);
    finish(routed('GET', '/a', '/a'), 503);

    expect(stored('request_2xx')['GET /a']->count)->toBe(1.0)
        ->and(stored('request_3xx')['GET /a']->count)->toBe(1.0)
        ->and(stored('request_4xx')['GET /a']->count)->toBe(1.0)
        ->and(stored('request_5xx')['GET /a']->count)->toBe(1.0)
        ->and(stored('request')['GET /a']->count)->toBe(4.0);
});

it('keeps requests without a route in one row', function () {
    $this->get('/nowhere')->assertNotFound();
    $this->get('/somewhere-else')->assertNotFound();

    expect(stored('request_4xx'))->toHaveKey('GET (unmatched)')
        ->and(stored('request_4xx')['GET (unmatched)']->count)->toBe(2.0);
});

it('lists requests slower than the threshold', function () {
    finish(routed('GET', '/fast', '/fast'), milliseconds: 50, config: ['threshold' => 100]);
    finish(routed('GET', '/slow', '/slow'), milliseconds: 250, config: ['threshold' => 100]);

    expect(stored('slow_request', ['count', 'max']))->toHaveKeys(['GET /slow'])
        ->not->toHaveKey('GET /fast');
});

it('supports per-route thresholds', function () {
    $config = ['threshold' => ['#^GET /reports#' => 5_000, 'default' => 100]];

    finish(routed('GET', '/reports', '/reports'), milliseconds: 1_000, config: $config);
    finish(routed('GET', '/users', '/users'), milliseconds: 1_000, config: $config);

    expect(stored('slow_request'))->toHaveKey('GET /users')
        ->not->toHaveKey('GET /reports');
});

it('skips ignored paths', function () {
    finish(routed('GET', '/up', '/up'), config: ['ignore' => ['#^/up$#']]);

    expect(stored('request'))->toBe([]);
});

it('skips everything when the sample rate is zero', function () {
    finish(routed('GET', '/a', '/a'), config: ['sample_rate' => 0]);

    expect(stored('request'))->toBe([]);
});

it('scales sampled requests back up', function () {
    mt_srand(1);

    foreach (range(1, 200) as $i) {
        finish(routed('GET', '/a', '/a'), config: ['sample_rate' => 0.5]);
    }

    expect(stored('request')['GET /a']->count)->toBeGreaterThan(140.0)->toBeLessThan(260.0);
});

it('never records the dashboard itself', function () {
    $request = routed('GET', '/laralyze', '/laralyze');
    $request->attributes->set(Authorize::SKIP_RECORDING, true);

    finish($request);

    expect(stored('request'))->toBe([]);
});

it('applies group rules to the path', function () {
    finish(routed('GET', '/legacy/7', '/legacy/7'), config: ['groups' => ['#^/legacy/\d+$#' => '/legacy/*']]);

    expect(stored('request'))->toHaveKey('GET /legacy/*');
});

it('registers nothing when disabled', function () {
    $this->rebootWith(['laralyze.recorders' => [Requests::class => ['enabled' => false]]]);

    Route::get('/a', fn () => 'ok');
    $this->get('/a')->assertOk();

    expect(stored('request'))->toBe([]);
});

it('records again once re-enabled', function () {
    $this->rebootWith(['laralyze.recorders' => [Requests::class => ['enabled' => true]]]);

    Route::get('/a', fn () => 'ok');
    $this->get('/a')->assertOk();

    expect(stored('request'))->toHaveKey('GET /a');
});

it('leaves out developer tools by default', function (string $path) {
    Route::post($path, fn () => 'ok');

    $this->post($path)->assertOk();

    expect(stored('request'))->toBe([]);
})->with(['/_boost/browser-logs', '/_debugbar/open', '/telescope/requests', '/horizon/api/stats', '/pulse']);

it('still records the app\'s own POST requests', function () {
    Route::post('/orders', fn () => 'ok');

    $this->post('/orders')->assertOk();

    expect(stored('request'))->toHaveKey('POST /orders');
});

it('groups Livewire updates by component and method', function () {
    $request = Request::create('/livewire/update', 'POST', [
        'components' => [[
            'snapshot' => json_encode(['memo' => ['name' => 'checkout-form']]),
            'calls' => [['method' => 'placeOrder', 'params' => []]],
        ]],
    ]);
    $route = (new Illuminate\Routing\Route(['POST'], '/livewire/update', fn () => null))->name('livewire.update')->bind($request);
    $request->setRouteResolver(fn () => $route);

    finish($request);

    expect(stored('request'))->toHaveKey('POST livewire:checkout-form@placeOrder');
});
