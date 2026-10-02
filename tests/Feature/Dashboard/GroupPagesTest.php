<?php

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Recorders;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function groupPath(string $page, string $key): string
{
    return "/laralyze/{$page}/".hash('xxh128', $key);
}

function listCard(string $name): Testable
{
    return Livewire::withoutLazyLoading()->test("laralyze.{$name}");
}

function groupCard(string $page, string $name): Testable
{
    return Livewire::withoutLazyLoading()->test('laralyze.group', ['page' => $page, 'name' => $name]);
}

it('links every route to its own page', function () {
    Laralyze::record('request', 'GET /books/{book}', 120)->avg()->max()->histogram();
    Laralyze::record('request_2xx', 'GET /books/{book}')->count();
    Laralyze::flush();

    listCard('routes')->assertSee(groupPath('requests', 'GET /books/{book}'));

    $this->get(groupPath('requests', 'GET /books/{book}'))
        ->assertOk()
        ->assertSee('GET /books/{book}')
        ->assertSee('Requests');
});

it('shows how one route went and how long it took', function () {
    foreach ([[200, 100], [200, 300], [404, 20], [500, 80]] as [$status, $ms]) {
        Laralyze::record('request', 'GET /books/{book}', $ms)->avg()->max()->histogram();
        Laralyze::record('request_'.intdiv($status, 100).'xx', 'GET /books/{book}')->count();
    }
    Laralyze::record('request', 'GET /other', 5000)->avg()->max()->histogram();
    Laralyze::record('request_2xx', 'GET /other')->count();
    Laralyze::flush();

    groupCard('requests', 'GET /books/{book}')
        ->assertSeeInOrder(['4', 'calls'])
        ->assertSeeInOrder(['2xx', '2', '4xx', '1', '5xx', '1'])
        ->assertSeeInOrder(['Total time', '500 ms', 'Average', '125 ms', 'p95', 'p99', 'Slowest', '300 ms'])
        ->assertDontSee('5.00 s')
        // Percentiles are estimates; they never pass the slowest call.
        ->assertDontSee('318 ms');
});

it('splits job runs into processed and failed', function () {
    Laralyze::record('job', 'App\Jobs\SendInvoice', 40)->avg()->max()->histogram();
    Laralyze::record('job', 'App\Jobs\SendInvoice', 60)->avg()->max()->histogram();
    Laralyze::record('job', 'App\Jobs\SendInvoice', 50)->avg()->max()->histogram();
    Laralyze::record('job_failed', 'App\Jobs\SendInvoice')->count();
    Laralyze::flush();

    $this->get(groupPath('jobs', 'App\Jobs\SendInvoice'))->assertOk();

    groupCard('jobs', 'App\Jobs\SendInvoice')
        ->assertSeeInOrder(['3', 'calls'])
        ->assertSeeInOrder(['processed', '2', 'failed', '1']);
});

it('shows a query formatted and highlighted', function () {
    $sql = 'select * from `books` where `id` = ? and `deleted_at` is null limit 1';
    Laralyze::merge('query', $sql, ['count' => 3, 'sum' => 6, 'max' => 3, 'h4' => 3]);
    Laralyze::flush();

    listCard('queries')
        ->assertSee('<span class="lz-sql-kw">select</span>', escape: false)
        ->assertSee(groupPath('queries', $sql));

    groupCard('queries', $sql)
        ->assertSee("<span class=\"lz-sql-kw\">from</span> <span class=\"lz-sql-id\">`books`</span>\n<span class=\"lz-sql-kw\">where</span>", escape: false)
        ->assertSeeInOrder(['Calls', '3', 'Total time', '6 ms']);
});

it('opens commands and outgoing URLs too', function () {
    Laralyze::record('command', 'migrate', 3_000)->avg()->max();
    Laralyze::record('http_failed', 'GET api.example.com/users/*')->count();
    Laralyze::flush();

    $this->get(groupPath('commands', 'migrate'))->assertOk()->assertSee('migrate');
    $this->get(groupPath('outgoing-requests', 'GET api.example.com/users/*'))->assertOk();

    groupCard('outgoing-requests', 'GET api.example.com/users/*')->assertSeeInOrder(['failed', '1']);
});

it('answers 404 for unknown rows, pages without row pages, and disabled recorders', function () {
    Laralyze::record('request', 'GET /books', 10)->avg()->max()->histogram();
    Laralyze::record('cache_hit', 'user:*')->count();
    Laralyze::flush();

    $this->get(groupPath('requests', 'GET /nothing'))->assertNotFound();
    $this->get(groupPath('cache', 'user:*'))->assertNotFound();
    $this->get(groupPath('nope', 'GET /books'))->assertNotFound();
    $this->get('/laralyze/requests/not-a-hash')->assertNotFound();

    $this->rebootWith(['laralyze.recorders' => [Recorders\Requests::class => ['enabled' => false]]]);
    app()->detectEnvironment(fn () => 'local');

    $this->get(groupPath('requests', 'GET /books'))->assertNotFound();
});

it('keeps row pages behind the gate', function () {
    Laralyze::record('request', 'GET /books', 10)->avg()->max()->histogram();
    Laralyze::flush();

    app()->detectEnvironment(fn () => 'production');

    $this->get(groupPath('requests', 'GET /books'))->assertForbidden();
});

it('won\'t let the browser point a group card elsewhere', function () {
    groupCard('requests', 'GET /books')->set('name', 'GET /admin');
})->throws(CannotUpdateLockedPropertyException::class);

it('searches and sorts lists by any column', function () {
    foreach (['GET /alpha' => [5, 10], 'GET /beta' => [1, 900], 'GET /gamma' => [3, 50]] as $key => [$calls, $ms]) {
        for ($i = 0; $i < $calls; $i++) {
            Laralyze::record('request', $key, $ms)->avg()->max()->histogram();
            Laralyze::record('request_2xx', $key)->count();
        }
    }
    Laralyze::flush();

    listCard('routes')
        ->assertSeeInOrder(['/alpha', '/gamma', '/beta'])
        ->call('sortBy', 'avg')
        ->assertSeeInOrder(['/beta', '/gamma', '/alpha'])
        ->call('sortBy', 'avg')
        ->assertSeeInOrder(['/alpha', '/gamma', '/beta'])
        ->call('sortBy', 'key')
        ->assertSeeInOrder(['/alpha', '/beta', '/gamma'])
        ->call('sortBy', 'nonsense')
        ->assertSet('sort', 'key')
        ->set('search', 'GAM')
        ->assertSee('/gamma')
        ->assertDontSee('/alpha')
        ->set('search', 'zzz')
        ->assertSee('No routes match');
});
