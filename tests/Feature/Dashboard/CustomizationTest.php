<?php

use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;
use Laralyze\Cards\Routes;
use Laralyze\Facades\Laralyze;
use Laralyze\Tests\Fixtures\FailingRoutes;
use Livewire\Livewire;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

afterEach(function () {
    File::deleteDirectory(resource_path('views/vendor/laralyze'));
    File::deleteDirectory(resource_path('views/components/laralyze'));
    File::deleteDirectory(resource_path('views/livewire/laralyze'));
    File::deleteDirectory(app_path('Livewire/Laralyze'));
});

function usesLivewire4(): bool
{
    return version_compare((string) InstalledVersions::getVersion('livewire/livewire'), '4.0.0', '>=');
}

it('uses a published card view instead of the built-in one', function () {
    File::ensureDirectoryExists(resource_path('views/vendor/laralyze/cards'));
    File::put(resource_path('views/vendor/laralyze/cards/routes.blade.php'), '<div>My routes: {{ $routes->count() }}</div>');

    $this->rebootWith();
    app()->detectEnvironment(fn () => 'local');

    Livewire::withoutLazyLoading()->test('laralyze.routes')->assertSee('My routes: 0');
});

it('publishes the card views for editing', function () {
    $this->artisan('vendor:publish', ['--tag' => 'laralyze-cards'])->assertSuccessful();

    expect(resource_path('views/vendor/laralyze/cards/routes.blade.php'))->toBeFile();
});

it('publishes the layout and page views', function () {
    $this->artisan('vendor:publish', ['--tag' => 'laralyze-views'])->assertSuccessful();

    expect(resource_path('views/vendor/laralyze/pages/requests.blade.php'))->toBeFile()
        ->and(resource_path('views/vendor/laralyze/components/page.blade.php'))->toBeFile();
});

it('swaps a built-in card for a subclass from config', function () {
    $this->rebootWith(['laralyze.cards' => ['routes' => FailingRoutes::class]]);
    app()->detectEnvironment(fn () => 'local');

    Laralyze::record('request', 'GET /fine', 10)->avg();
    Laralyze::record('request_2xx', 'GET /fine')->count();
    Laralyze::record('request', 'GET /broken', 10)->avg();
    Laralyze::record('request_5xx', 'GET /broken')->count();
    Laralyze::flush();

    Livewire::withoutLazyLoading()->test('laralyze.routes')
        ->assertSee('/broken')
        ->assertDontSee('/fine');

    expect(Livewire::new('laralyze.routes'))->toBeInstanceOf(FailingRoutes::class)
        ->toBeInstanceOf(Routes::class);
});

it('creates a custom card that shows a custom metric', function () {
    $this->artisan('laralyze:make-card', ['name' => 'CheckoutFunnel'])->assertSuccessful();

    if (usesLivewire4()) {
        expect(resource_path('views/components/laralyze/checkout-funnel.blade.php'))->toBeFile();
    } else {
        expect(app_path('Livewire/Laralyze/CheckoutFunnel.php'))->toBeFile()
            ->and(resource_path('views/livewire/laralyze/checkout-funnel.blade.php'))->toBeFile();

        require_once app_path('Livewire/Laralyze/CheckoutFunnel.php');
    }

    Laralyze::record('checkout_funnel', 'pro', 49)->count()->max();
    Laralyze::flush();

    Livewire::withoutLazyLoading()->test('laralyze.checkout-funnel')
        ->assertSee('Checkout Funnel')
        ->assertSee('pro')
        ->assertSee('49');
});

it('refuses to overwrite a card or take a built-in name', function () {
    $this->artisan('laralyze:make-card', ['name' => 'Routes'])->assertFailed();

    $this->artisan('laralyze:make-card', ['name' => 'Signups'])->assertSuccessful();
    $this->artisan('laralyze:make-card', ['name' => 'Signups'])->assertFailed();
    $this->artisan('laralyze:make-card', ['name' => 'Signups', '--force' => true])->assertSuccessful();
});
