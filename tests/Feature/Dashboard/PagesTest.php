<?php

use Illuminate\Support\Facades\View;
use MohammedMojaly\Laralyze\Dashboard\StorageInfo;
use MohammedMojaly\Laralyze\Recorders\Requests;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

it('shows the dashboard at /laralyze', function () {
    $this->get('/laralyze')
        ->assertOk()
        ->assertSee('<title>Dashboard · Laralyze</title>', escape: false)
        ->assertSee('laralyze.request-totals');
});

it('says where the data lives, and has a menu button for phones', function () {
    $storage = StorageInfo::describe(app());

    $this->get('/laralyze/requests')
        ->assertOk()
        ->assertSee($storage['label'])
        ->assertSee($storage['detail'])
        ->assertSee('class="lz-menu-button"', escape: false)
        ->assertSee('<span class="lz-sidebar-page">Requests</span>', escape: false);
});

it('serves each page on its own route', function () {
    $this->get('/laralyze/requests')
        ->assertOk()
        ->assertSee('<h1>Requests</h1>', escape: false)
        ->assertSee('aria-current="page"', escape: false);
});

it('ships its own assets and loads nothing from elsewhere', function () {
    $html = $this->get('/laralyze')->assertOk()->getContent();

    expect($html)->toContain('<style>')
        ->toContain('--lz-ink')
        ->toContain('window.livewireScriptConfig')
        ->not->toMatch('/<(link|script)[^>]+(href|src)="https?:/');
});

it('returns 404 for unknown pages', function () {
    $this->get('/laralyze/nope')->assertNotFound();
});

it('keeps the chosen period in the sidebar links', function () {
    $this->get('/laralyze?period=7d')
        ->assertOk()
        ->assertSee('Last 7 days')
        ->assertSee('/laralyze/requests?period=7d', escape: false);
});

it('hides pages whose recorder is off', function () {
    config(['laralyze.recorders' => [Requests::class => ['enabled' => false]]]);

    $this->get('/laralyze')->assertOk()->assertDontSee('/laralyze/requests');
    $this->get('/laralyze/requests')->assertNotFound();
});

it('adds pages from config to the sidebar', function () {
    View::addNamespace('app', __DIR__.'/../../Fixtures/views');
    config(['laralyze.pages.checkout' => ['label' => 'Checkout', 'section' => 'Business', 'view' => 'app::checkout']]);

    $this->get('/laralyze')
        ->assertOk()
        ->assertSeeInOrder(['Activity', 'Requests', 'Business', 'Checkout']);

    $this->get('/laralyze/checkout')
        ->assertOk()
        ->assertSee('<h1>Checkout</h1>', escape: false)
        ->assertSee('Conversion goes here');
});

it('can hide a built-in page', function () {
    config(['laralyze.pages.requests' => false]);

    $this->get('/laralyze/requests')->assertNotFound();
});

it('lives under a custom path', function () {
    $this->rebootWith(['laralyze.path' => 'ops/monitor']);
    app()->detectEnvironment(fn () => 'local');

    $this->get('/ops/monitor/requests')->assertOk();
    $this->get('/laralyze')->assertNotFound();
});

it('registers no routes when Laralyze is off', function () {
    $this->rebootWith(['laralyze.enabled' => false]);
    app()->detectEnvironment(fn () => 'local');

    $this->get('/laralyze')->assertNotFound();
});
