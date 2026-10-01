<?php

use MohammedMojaly\Laralyze\Facades\Laralyze;

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
