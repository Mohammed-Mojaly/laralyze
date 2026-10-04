<?php

use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;

beforeEach(fn () => usingClickHouse() || $this->markTestSkipped('Needs LARALYZE_TEST_STORAGE=clickhouse.'));

function recordRequests(): void
{
    foreach ([[120, 'GET /'], [30, 'GET /'], [500, 'GET /slow'], [80, 'GET /']] as [$ms, $route]) {
        Laralyze::record('request', $route, $ms)->count()->sum()->min()->max()->histogram();
        Laralyze::flush(); // one flush each: duplicate rows until ClickHouse merges them
    }
}

it('gives the same numbers before and after merging', function () {
    recordRequests();
    $read = fn () => [
        app(Storage::class)->aggregate('request', ['count', 'sum', 'min', 'max', 'avg', 'p95'], 3_600, 'count')->toArray(),
        (array) app(Storage::class)->total('request', ['count', 'max', 'p50'], 3_600),
        app(Storage::class)->graph('request', 'count', 3_600)->filter()->values()->all(),
    ];

    $before = json_encode($read());
    app(ClickHouseStorage::class)->client()->statement('OPTIMIZE TABLE laralyze_aggregates FINAL');

    expect(json_encode($read()))->toBe($before)
        ->and(app(Storage::class)->total('request', ['count'], 3_600)->count)->toBe(4.0)
        ->and(app(Storage::class)->total('request', ['min'], 3_600)->min)->toBe(30.0)
        ->and(app(Storage::class)->total('request', ['max'], 3_600)->max)->toBe(500.0)
        ->and(app(Storage::class)->aggregate('request', ['count', 'avg'], 3_600, 'count')->first())
        ->toMatchArray(['key' => 'GET /', 'count' => 3.0, 'avg' => 230 / 3]);
});

it('returns nulls like SQL when nothing matches', function () {
    $storage = app(Storage::class);
    $total = $storage->total('nothing', ['count', 'max', 'avg', 'p95'], 3_600);

    expect($total->count)->toBeNull()->and($total->max)->toBeNull()->and($total->avg)->toBeNull()->and($total->p95)->toBeNull()
        ->and($storage->aggregate('nothing', ['count'], 3_600))->toBeEmpty()
        ->and($storage->oldestBucket())->toBeNull()
        ->and($storage->keyFor(['request'], 'missing'))->toBeNull()
        ->and($storage->countKeys('nothing', 3_600))->toBe(0);
});

it('leaves max empty for keys that only have counts', function () {
    Laralyze::record('job', 'SendMail')->count();
    Laralyze::flush();

    $job = app(Storage::class)->aggregate('job', ['count', 'max', 'avg'], 3_600)->first();

    expect($job->max)->toBeNull()->and($job->avg)->toBeNull();
});
