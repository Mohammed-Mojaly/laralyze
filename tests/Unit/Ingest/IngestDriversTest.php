<?php

use MohammedMojaly\Laralyze\Contracts\Ingest;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Ingest\Batch;
use MohammedMojaly\Laralyze\Ingest\DatabaseIngest;
use MohammedMojaly\Laralyze\Ingest\DirectIngest;
use MohammedMojaly\Laralyze\Ingest\Drivers;

function ingestDriverFor(array $config): string
{
    // The suite also runs with ClickHouse storage, which always writes directly.
    config(['laralyze.storage.driver' => 'database', 'laralyze.ingest.driver' => null, ...$config]);

    return Drivers::name(app());
}

it('queues writes on databases where concurrent upserts lock each other', function (string $driver) {
    config(['database.connections.busy' => ['driver' => $driver]]);

    expect(ingestDriverFor(['laralyze.storage.connection' => 'busy']))->toBe('database');
})->with(['mysql', 'mariadb', 'pgsql', 'sqlsrv']);

it('writes directly to SQLite and ClickHouse', function () {
    config(['database.connections.small' => ['driver' => 'sqlite']]);

    expect(ingestDriverFor(['laralyze.storage.connection' => 'small']))->toBe('direct')
        ->and(ingestDriverFor(['laralyze.storage.driver' => 'clickhouse', 'laralyze.ingest.driver' => 'database']))->toBe('direct');
});

it('follows LARALYZE_INGEST when it is set', function () {
    config(['database.connections.busy' => ['driver' => 'mysql']]);

    expect(ingestDriverFor(['laralyze.storage.connection' => 'busy', 'laralyze.ingest.driver' => 'direct']))->toBe('direct')
        ->and(ingestDriverFor(['laralyze.ingest.driver' => 'database']))->toBe('database');
});

it('works with a config published before ingest existed', function () {
    config(['laralyze' => collect(config('laralyze'))->except('ingest')->all()]);
    config(['database.connections.busy' => ['driver' => 'pgsql'], 'laralyze.storage.connection' => 'busy', 'laralyze.storage.driver' => 'database']);
    app()->forgetInstance(Ingest::class);

    expect(app(Ingest::class))->toBeInstanceOf(DatabaseIngest::class);
});

it('refuses an unknown ingest driver', function () {
    ingestDriverFor(['laralyze.ingest.driver' => 'redis']);
})->throws(InvalidArgumentException::class, 'ingest driver [redis]');

it('binds the matching ingest', function () {
    config(['laralyze.ingest.driver' => 'direct']);
    app()->forgetInstance(Ingest::class);

    expect(app(Ingest::class))->toBeInstanceOf(DirectIngest::class);
});

it('round-trips a flush through its payload', function () {
    $flush = [
        'rows' => [['bucket' => 1_800_000_000, 'period' => 60, 'type' => 'query', 'aggregate' => 'sum', 'key' => "select * from \"users\" where name = 'Zoë'", 'value' => 12.0]],
        'values' => [['timestamp' => 1_800_000_000, 'type' => 'seen', 'key' => '42', 'value' => '{"a":1}']],
        'executions' => [['uuid' => '01J00000000000000000000001', 'duration' => 1.5, 'counts' => ['query' => 3], 'events' => [['type' => 'log', 'message' => 'ünïcödé']]]],
        'logs' => [['uuid' => '01J00000000000000000000002', 'logged_at' => 1_800_000_000, 'level' => 'info', 'message' => 'Zoë signed in', 'context' => null]],
    ];

    expect(Batch::decode(Batch::encode($flush['rows'], $flush['values'], $flush['executions'], $flush['logs'])))->toBe($flush);
});

it('reads a payload queued before logs were added', function () {
    $old = base64_encode((string) gzdeflate((string) json_encode(['rows' => [], 'values' => [], 'executions' => []])));

    expect(Batch::decode($old)['logs'])->toBe([]);
});

it("keeps the newer value when a slow request's flush arrives after a newer one", function () {
    $batch = new Batch;
    $seen = fn (int $timestamp, string $value) => ['rows' => [], 'values' => [['timestamp' => $timestamp, 'type' => 'seen', 'key' => 'user 1', 'value' => $value]], 'executions' => []];

    $batch->add($seen(200, 'newer'));
    $batch->add($seen(100, 'older, from a slow request'));

    expect($batch->values()[0]['value'])->toBe('newer');
});

it('merges flushes by the same rules as storage', function () {
    $batch = new Batch;
    $row = fn (string $aggregate, float $value) => ['bucket' => 60, 'period' => 60, 'type' => 't', 'aggregate' => $aggregate, 'key' => 'k', 'value' => $value];

    $batch->add(['rows' => [$row('count', 1), $row('min', 5), $row('max', 5), $row('h3', 1)], 'values' => [['timestamp' => 10, 'type' => 'v', 'key' => 'k', 'value' => 'first']], 'executions' => [['uuid' => 'a']]]);
    $batch->add(['rows' => [$row('count', 2), $row('min', 3), $row('max', 9), $row('h3', 2)], 'values' => [['timestamp' => 10, 'type' => 'v', 'key' => 'k', 'value' => 'second']], 'executions' => [['uuid' => 'b']]]);

    expect(collect($batch->rows())->pluck('value', 'aggregate')->all())->toBe(['count' => 3.0, 'min' => 3.0, 'max' => 9.0, 'h3' => 3.0])
        ->and($batch->values()[0]['value'])->toBe('second')
        ->and($batch->executions())->toBe([['uuid' => 'a'], ['uuid' => 'b']]);
});

it('writes directly to the storage bound at the time of the write', function () {
    config(['laralyze.ingest.driver' => 'direct']);
    app()->forgetInstance(Ingest::class);
    $ingest = app(Ingest::class);

    // Swapped in after the ingest was resolved, like a test or an app that changes storage.
    $storage = Mockery::mock(Storage::class);
    $storage->shouldReceive('store')->once()->with([], [['timestamp' => 1, 'type' => 't', 'key' => 'k', 'value' => 'v']], [], []);
    app()->instance(Storage::class, $storage);

    $ingest->write([], [['timestamp' => 1, 'type' => 't', 'key' => 'k', 'value' => 'v']], []);
});
