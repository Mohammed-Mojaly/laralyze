<?php

use Livewire\Livewire;
use MohammedMojaly\Laralyze\Dashboard\Issues;
use MohammedMojaly\Laralyze\Dashboard\Pages;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\LaralyzeServiceProvider;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

it('serves every built-in page', function (string $page) {
    $this->get($page === Pages::HOME ? '/laralyze' : "/laralyze/{$page}")->assertOk();
})->with(['dashboard', 'requests', 'jobs', 'commands', 'scheduled', 'exceptions', 'findings', 'queries', 'cache', 'outgoing-requests', 'ai', 'mail', 'notifications', 'visits', 'users', 'logs', 'servers']);

it('lists every page in the sidebar, grouped by section', function () {
    $this->get('/laralyze')->assertOk()->assertSeeInOrder([
        'Dashboard',
        'Issues', 'Exceptions', 'Findings',
        'Activity', 'Requests', 'Jobs', 'Commands', 'Scheduled Tasks',
        'Inside', 'AI', 'Queries', 'Cache', 'Outgoing Requests', 'Mail', 'Notifications', 'Logs',
        'Audience', 'Users', 'Visits',
        'Servers', 'Recording',
    ]);
});

it('renders every card with nothing recorded', function (string $card) {
    // The group card shows one route, query or job, so it needs to know which.
    $props = $card === 'group' ? ['page' => 'requests', 'name' => 'GET /'] : [];

    Livewire::withoutLazyLoading()->test("laralyze.{$card}", $props)->assertOk();
})->with(array_keys(LaralyzeServiceProvider::CARDS));

it('counts open exceptions and findings next to their links', function () {
    Laralyze::record('exception', 'App\Exceptions\Boom::app/Http/Kernel.php:10', time())->count()->max();
    Laralyze::record('exception', 'App\Exceptions\Done::app/Http/Kernel.php:20', time())->count()->max();
    Laralyze::record('n_plus_one', '["select * from books where author_id = ?","app/Http/Books.php:9"]', 12)->count()->max();
    Laralyze::flush();

    app(Issues::class)->resolve('App\Exceptions\Done::app/Http/Kernel.php:20');

    $this->get('/laralyze')->assertOk()
        ->assertSeeInOrder(['Exceptions', '<span class="lz-nav-count"', '>1</span>', 'Findings', 'lz-nav-count-warn', '>1</span>'], false);
});
