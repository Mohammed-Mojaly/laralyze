<?php

use MohammedMojaly\Laralyze\Storage\ClickHouse\Schema;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;

beforeEach(fn () => usingClickHouse() || $this->markTestSkipped('Needs LARALYZE_TEST_STORAGE=clickhouse.'));

it('creates its tables once and finds them', function () {
    $client = app(ClickHouseStorage::class)->client();

    Schema::create($client);
    Schema::create($client);

    expect(Schema::exists($client))->toBeTrue()
        ->and(app(ClickHouseStorage::class)->installed())->toBeTrue()
        ->and(version_compare(Schema::version($client), Schema::MINIMUM_VERSION, '>='))->toBeTrue();
});
