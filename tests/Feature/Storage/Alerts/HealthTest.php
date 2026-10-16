<?php

use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Health;

const SHARED_CACHE = 'Several servers run the scheduler, but the cache isn\'t shared.';

function healthTitles(): array
{
    app()->forgetInstance(Health::class);

    return array_column(app(Health::class)->problems(), 'title');
}

function serverAt(string $name, int $at): void
{
    app(Storage::class)->store([], [['timestamp' => $at, 'type' => 'server', 'key' => $name, 'value' => '{}']]);
}

it('warns when two servers reported in the last hour and the cache is file or array', function () {
    config(['cache.default' => 'file', 'cache.stores.file' => ['driver' => 'file', 'path' => sys_get_temp_dir()]]);
    $now = now()->getTimestamp();

    serverAt('web-1', $now);
    expect(healthTitles())->not->toContain(SHARED_CACHE);

    serverAt('web-2', $now - 600);
    expect(healthTitles())->toContain(SHARED_CACHE);

    config(['cache.default' => 'redis', 'cache.stores.redis' => ['driver' => 'redis']]);
    expect(healthTitles())->not->toContain(SHARED_CACHE);

    config(['cache.default' => 'array']);
    serverAt('web-2', $now - 7_200);
    expect(healthTitles())->not->toContain(SHARED_CACHE);
});
