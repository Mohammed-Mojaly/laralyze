<?php

use Illuminate\Support\Facades\Gate;

it('allows viewing Laralyze in the local environment', function () {
    app()->detectEnvironment(fn () => 'local');

    expect(Gate::allows('viewLaralyze'))->toBeTrue();
});

it('forbids viewing Laralyze outside the local environment by default', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    expect(Gate::allows('viewLaralyze'))->toBeFalse();
})->with(['production', 'staging']);

it('lets the application redefine who can view Laralyze', function () {
    app()->detectEnvironment(fn () => 'production');

    Gate::define('viewLaralyze', fn ($user = null) => true);

    expect(Gate::allows('viewLaralyze'))->toBeTrue();
});
