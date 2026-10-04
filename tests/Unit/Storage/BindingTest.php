<?php

use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;

it('uses the database storage unless told otherwise', function () {
    expect(app(Storage::class))->toBeInstanceOf(DatabaseStorage::class)
        ->and(app(Storage::class))->toBe(app(Storage::class));
});

it('keeps working with a config published before drivers existed', function () {
    config(['laralyze.storage' => ['connection' => null]]);
    app()->forgetInstance(Storage::class);

    expect(app(Storage::class))->toBeInstanceOf(DatabaseStorage::class);
});
