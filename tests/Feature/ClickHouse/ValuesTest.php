<?php

use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;

beforeEach(fn () => usingClickHouse() || $this->markTestSkipped('Needs LARALYZE_TEST_STORAGE=clickhouse.'));

it('uses ClickHouse when configured', function () {
    expect(app(Storage::class))->toBeInstanceOf(ClickHouseStorage::class)
        ->and(app(Storage::class)->inTransaction())->toBeFalse();
});

it('keeps the newest value of a key', function () {
    Laralyze::set('server', 'web-1', '{"cpu":10}', 1_000);
    Laralyze::flush();
    Laralyze::set('server', 'web-1', '{"cpu":55}', 1_005);
    Laralyze::flush();

    $values = app(Storage::class)->values('server');

    expect($values)->toHaveCount(1)
        ->and($values->first()->value)->toBe('{"cpu":55}')
        ->and($values->first()->timestamp)->toBe(1_005);
});

it('forgets values with a tombstone', function () {
    $storage = app(Storage::class);
    $storage->put('issue_status', 'abc', 'resolved');
    $storage->forget('issue_status', 'abc');

    expect($storage->values('issue_status'))->toBeEmpty()
        ->and($storage->values('issue_status', ['abc']))->toBeEmpty();

    $storage->put('issue_status', 'abc', 'ignored');

    expect($storage->values('issue_status')->first()->value)->toBe('ignored');
});

it('round-trips awkward text', function () {
    $key = "GET /مرحبا/😀 'q' \\ \"d\" \n\t";
    app(Storage::class)->put('note', $key, $key);

    $row = app(Storage::class)->values('note', [$key])->first();

    expect($row->key)->toBe($key)->and($row->value)->toBe($key);
});

it('counts keys set recently', function () {
    $this->travelTo(now()->setTimestamp(10_000));
    app(Storage::class)->put('visitor_seen', 'a', '1');
    $this->travelTo(now()->setTimestamp(10_400));
    app(Storage::class)->put('visitor_seen', 'b', '1');

    expect(app(Storage::class)->countValues('visitor_seen', 300))->toBe(1);
});

it('stores metric values exactly to four decimals', function () {
    app(Storage::class)->store([
        ['bucket' => 60_000, 'period' => 60, 'type' => 't', 'aggregate' => 'sum', 'key' => 'a', 'value' => 0.00001],
        ['bucket' => 60_000, 'period' => 60, 'type' => 't', 'aggregate' => 'sum', 'key' => 'b', 'value' => 123_456_789.1234],
        ['bucket' => 60_000, 'period' => 60, 'type' => 't', 'aggregate' => 'sum', 'key' => 'c', 'value' => 1e15],
    ], []);

    expect(laralyzeRows('laralyze_aggregates')->pluck('value', 'key')->map(fn ($value) => (float) $value)->sortKeys()->all())
        ->toBe(['a' => 0.0, 'b' => 123_456_789.1234, 'c' => 1e15]);
});

it('remembers when it last cleaned up', function () {
    app(Storage::class)->put('laralyze', 'trimmed_at', '12345');

    expect(app(Storage::class)->lastTrimmedAt())->toBe(12_345);
});
