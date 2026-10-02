<?php

use Livewire\Livewire;
use MohammedMojaly\Laralyze\Dashboard\Pages;
use MohammedMojaly\Laralyze\LaralyzeServiceProvider;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

it('serves every built-in page', function (string $page) {
    $this->get($page === Pages::HOME ? '/laralyze' : "/laralyze/{$page}")->assertOk();
})->with(['dashboard', 'requests', 'jobs', 'commands', 'scheduled', 'exceptions', 'queries', 'cache', 'outgoing-requests', 'mail', 'notifications', 'visits', 'users', 'logs', 'servers']);

it('lists every page in the sidebar, grouped by section', function () {
    $this->get('/laralyze')->assertOk()->assertSeeInOrder([
        'Dashboard',
        'Activity', 'Requests', 'Jobs', 'Commands', 'Scheduled Tasks',
        'Application', 'Exceptions', 'Queries', 'Cache', 'Outgoing Requests', 'Mail', 'Notifications',
        'Monitoring', 'Visits', 'Users', 'Logs', 'Servers',
    ]);
});

it('renders every card with nothing recorded', function (string $card) {
    // The group card shows one route, query or job, so it needs to know which.
    $props = $card === 'group' ? ['page' => 'requests', 'name' => 'GET /'] : [];

    Livewire::withoutLazyLoading()->test("laralyze.{$card}", $props)->assertOk();
})->with(array_keys(LaralyzeServiceProvider::CARDS));
