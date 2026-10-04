<?php

use MohammedMojaly\Laralyze\Assistant\Chats;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;

beforeEach(fn () => usingClickHouse() || $this->markTestSkipped('Needs LARALYZE_TEST_STORAGE=clickhouse.'));

it('drops whole days instead of deleting rows', function () {
    $now = time();
    $row = fn (int $at, int $period, string $key) => ['bucket' => $at - $at % $period, 'period' => $period, 'type' => 'request', 'aggregate' => 'count', 'key' => $key, 'value' => 1.0];

    app(Storage::class)->store([
        $row($now - 600, 60, 'minute-now'),
        $row($now - 3 * 86_400, 60, 'minute-old'),
        $row($now - 3 * 86_400, 3_600, 'hour-kept'),
        $row($now - 40 * 86_400, 3_600, 'hour-old'),
    ], []);

    app(Storage::class)->trim(30);

    $mutations = app(ClickHouseStorage::class)->client()->select(
        "SELECT count() AS found FROM system.mutations WHERE database = currentDatabase() AND table = 'laralyze_aggregates'",
    );

    expect(laralyzeRows('laralyze_aggregates')->pluck('key')->sort()->values()->all())->toBe(['hour-kept', 'minute-now'])
        ->and((int) $mutations[0]['found'])->toBe(0)
        ->and(app(Storage::class)->lastTrimmedAt())->toBeGreaterThanOrEqual($now)->toBeLessThanOrEqual(time());
});

it('forgets old values, conversations and visitors', function () {
    $storage = app(Storage::class);
    $this->travel(-40)->days();
    $storage->put('server', 'old', '1');
    $this->travelBack();
    $this->travel(-8)->days();
    $storage->put(Chats::TYPE, 'chat', '{}');
    $storage->put('server', 'recent', '1');
    $this->travelBack();
    $this->travel(-2)->days();
    $storage->put('visitor_seen', 'v', '1');
    $this->travelBack();

    $storage->trim(30);

    expect(laralyzeRows('laralyze_values')->where('type', '!=', 'laralyze')->pluck('key')->all())->toBe(['recent']);
});
