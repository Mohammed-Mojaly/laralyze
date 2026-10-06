<?php

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Tests\Fixtures\AdminsOnly;

function firstSnapshot(TestResponse $page): string
{
    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);

    expect($matches)->not->toBeEmpty();

    return html_entity_decode($matches[1]);
}

/**
 * Send a real Livewire update with no calls, which is what a poll does.
 */
function updateCard(string $snapshot): TestResponse
{
    return test()->withSession(['_token' => 'laralyze-test'])
        ->withHeaders(['X-Livewire' => '1', 'X-CSRF-TOKEN' => 'laralyze-test'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [],
            ]],
        ]);
}

it('forbids the dashboard outside local by default', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/laralyze')->assertForbidden();
    $this->get('/laralyze/requests')->assertForbidden();
});

it('lets the app decide who gets in', function () {
    app()->detectEnvironment(fn () => 'production');
    Gate::define('viewLaralyze', fn ($user = null) => true);

    $this->get('/laralyze')->assertOk();
});

it('checks the gate on every Livewire update', function () {
    app()->detectEnvironment(fn () => 'local');

    $placeholder = firstSnapshot($this->get('/laralyze/requests')->assertOk());
    $loaded = updateCard($placeholder)->assertOk()->json('components.0.snapshot');

    // Access is revoked while the page is open.
    app()->detectEnvironment(fn () => 'production');

    updateCard($loaded)->assertForbidden();
    updateCard($placeholder)->assertForbidden();
});

it('runs the app\'s own middleware aliases on every Livewire update', function () {
    $this->rebootWith(['laralyze.middleware' => ['web', 'laralyze-admins']]);
    app()->detectEnvironment(fn () => 'local');

    $placeholder = firstSnapshot($this->get('/laralyze/requests')->assertOk());

    // The user loses access while the page is open.
    AdminsOnly::$deny = true;

    try {
        updateCard($placeholder)->assertForbidden();
    } finally {
        AdminsOnly::$deny = false;
    }

    // Groups stay out: Livewire would run the web group again on every update in the app.
    expect(Livewire::getPersistentMiddleware())->toContain(AdminsOnly::class)
        ->not->toContain('web')
        ->not->toContain(StartSession::class);
});
