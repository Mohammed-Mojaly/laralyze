<?php

use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Issues;
use MohammedMojaly\Laralyze\Facades\Laralyze;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function attention(): Testable
{
    return Livewire::withoutLazyLoading()->test('laralyze.attention');
}

function reportingServer(string $name, int $seenAt, array $data = []): void
{
    app(Storage::class)->store([], [[
        'timestamp' => $seenAt, 'type' => 'server', 'key' => $name,
        'value' => json_encode([...['cpu' => 10, 'memory_used' => 1, 'memory_total' => 10, 'disks' => []], ...$data]),
    ]]);
}

it('shows nothing when nothing needs attention', function () {
    reportingServer('web-1', time());
    Laralyze::record('request', 'GET /', 20)->count();
    Laralyze::flush();

    attention()->assertOk()->assertSee('hidden wire:poll.30s', escape: false)->assertDontSee('lz-attention-item');
});

it('lists the worst problems first, five at most, each linking to its page', function () {
    reportingServer('web-1', time(), ['disks' => [['directory' => '/', 'used' => 95, 'total' => 100]]]);
    $exception = json_encode(['App\Exceptions\ShelfEmpty', 'app/Shelf.php:12']);
    Laralyze::record('exception', $exception, time())->count()->max();
    Laralyze::record('exception', $exception, time())->count()->max();
    Laralyze::record('exception_unhandled', $exception)->count();
    Laralyze::record('job_failed', 'App\Jobs\SendInvoice')->count();
    Laralyze::record('scheduled_failed', 'php artisan backup:run')->count();

    foreach (range(1, 6) as $i) {
        Laralyze::record('http_5xx', 'POST api.stripe.test/v1/charges')->count();
    }

    Laralyze::record('slow_request', 'GET /orders', 3_200)->count()->max();
    Laralyze::record('n_plus_one', json_encode(['select * from authors where id = ?', 'app/Books.php:9']), 27)->count()->max();
    Laralyze::flush();

    attention()
        ->assertSee('Needs attention')
        ->assertSeeInOrder([
            'Server web-1: disk / at 95%.',
            'Unhandled App\Exceptions\ShelfEmpty, 2 times.',
            '1 job failed.',
            'Scheduled task php artisan backup:run failed once.',
            'Calls to api.stripe.test failed 6 times.',
        ])
        ->assertSee('/laralyze/servers', escape: false)
        ->assertSee('/laralyze/jobs', escape: false)
        ->assertSee('/laralyze/outgoing-requests', escape: false)
        ->assertDontSee('GET /orders')
        ->assertDontSee('Possible N+1');
});

it('names a server that stopped reporting', function () {
    reportingServer('web-2', time() - 600);

    attention()->assertSee('Server web-2 stopped reporting 10 minutes ago.');
});

it('leaves out handled, resolved and rare problems', function () {
    $handled = json_encode(['App\Exceptions\Handled', 'app/A.php:1']);
    Laralyze::record('exception', $handled, time())->count()->max();
    $resolved = json_encode(['App\Exceptions\Fixed', 'app/B.php:1']);
    Laralyze::record('exception', $resolved, time() - 60)->count()->max();
    Laralyze::record('exception_unhandled', $resolved)->count();

    foreach (range(1, 4) as $i) {
        Laralyze::record('http_failed', 'GET api.flaky.test/ping')->count();
    }

    Laralyze::record('slow_request', 'GET /orders', 3_200)->count()->max();
    Laralyze::record('n_plus_one', json_encode(['select * from authors where id = ?', 'app/Books.php:9']), 27)->count()->max();
    Laralyze::flush();
    app(Issues::class)->resolve($resolved);

    attention()
        ->assertDontSee('Handled')
        ->assertDontSee('Fixed')
        ->assertDontSee('api.flaky.test')
        ->assertSeeInOrder(['GET /orders took up to 3.20 s, over its', 'Possible N+1 at app/Books.php:9, up to 27× in one execution.']);
});
