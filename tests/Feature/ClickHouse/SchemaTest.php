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

it('adds the columns of later releases to tables from earlier ones, on the first read that needs them', function () {
    $client = app(ClickHouseStorage::class)->client();
    $client->statement('ALTER TABLE laralyze_executions DROP INDEX IF EXISTS calls_idx');
    $client->statement('ALTER TABLE laralyze_executions DROP COLUMN IF EXISTS calls');

    expect(app(ClickHouseStorage::class)->executions(['call' => 'h1'], 3_600))->toBeEmpty();

    $columns = array_column($client->select("SELECT name FROM system.columns WHERE database = currentDatabase() AND table = 'laralyze_executions'"), 'name');

    expect($columns)->toContain('calls');
});
