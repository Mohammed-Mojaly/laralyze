<?php

use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\LaralyzeServiceProvider;
use MohammedMojaly\Laralyze\Recorders;

it('merges the package config', function () {
    expect(config('laralyze.enabled'))->toBeTrue();
});

it('turns off when LARALYZE_ENABLED is false in the environment', function () {
    $_ENV['LARALYZE_ENABLED'] = $_SERVER['LARALYZE_ENABLED'] = 'false';
    $this->refreshApplication();

    expect(Laralyze::isEnabled())->toBeFalse()
        ->and(Laralyze::isRecording())->toBeFalse();
})->after(function () {
    unset($_ENV['LARALYZE_ENABLED'], $_SERVER['LARALYZE_ENABLED']);
});

it('reports its status in php artisan about', function () {
    $this->artisan('about', ['--only' => 'laralyze'])
        ->expectsOutputToContain('Laralyze')
        ->expectsOutputToContain('ENABLED')
        ->assertSuccessful();
});

it('turns on recorders that are newer than a published config', function () {
    // As if config/laralyze.php was published before the AI recorder existed.
    config(['laralyze.recorders' => [
        Recorders\Requests::class => ['enabled' => true],
        Recorders\Queries::class => ['enabled' => false],
    ]]);

    (new LaralyzeServiceProvider(app()))->register();

    expect(config('laralyze.recorders.'.Recorders\Ai::class.'.enabled'))->toBeTrue()
        ->and(config('laralyze.recorders.'.Recorders\Queries::class.'.enabled'))->toBeFalse();
});
