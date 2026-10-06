<?php

use Illuminate\Support\Facades\DB;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Laralyze as LaralyzeCore;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

it('cuts keys and types to a size every database takes, without breaking UTF-8', function () {
    $key = str_repeat('é', 40_000);
    Laralyze::record(str_repeat('t', 100), $key)->count();
    Laralyze::set('note', $key, 'v');
    Laralyze::flush();

    $metric = app(Storage::class)->aggregate(str_repeat('t', 64), ['count'], 3_600)->first();
    $value = app(Storage::class)->values('note')->first();

    expect(strlen($metric->key))->toBeLessThanOrEqual(LaralyzeCore::MAX_KEY)
        ->and(mb_check_encoding($metric->key, 'UTF-8'))->toBeTrue()
        ->and($metric->key)->toStartWith('éé')
        ->and((float) $metric->count)->toBe(1.0)
        ->and(strlen($value->key))->toBeLessThanOrEqual(LaralyzeCore::MAX_KEY);
});

it('cuts a single run\'s name, server and user to their columns', function () {
    Laralyze::addExecution([
        'uuid' => '01J00000000000000000000009', 'trace' => '01J00000000000000000000009', 'type' => 'request',
        'name' => 'GET /'.str_repeat('x', 100_000), 'status' => '200', 'failed' => false, 'duration' => 1.0,
        'user_id' => str_repeat('u', 200), 'server' => str_repeat('s', 300), 'started_at' => time(),
        'exceptions' => [], 'counts' => [], 'meta' => [], 'events' => [],
    ]);
    Laralyze::flush();

    $row = DB::table('laralyze_executions')->first();

    expect(strlen($row->name))->toBe(LaralyzeCore::MAX_KEY)
        ->and(strlen($row->user_id))->toBe(64)
        ->and(strlen($row->server))->toBe(128);
})->skip(fn () => usingClickHouse(), 'Checks the database columns.');
