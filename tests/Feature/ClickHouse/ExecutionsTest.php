<?php

use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Contracts\Storage;

beforeEach(fn () => usingClickHouse() || $this->markTestSkipped('Needs LARALYZE_TEST_STORAGE=clickhouse.'));

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function execution(array $overrides = []): array
{
    return [
        'uuid' => (string) Str::ulid(), 'trace' => 'T1', 'type' => 'request', 'name' => 'GET /books', 'status' => '200',
        'failed' => false, 'duration' => 12.5, 'user_id' => null, 'server' => 'web-1', 'started_at' => time(),
        'exceptions' => [], 'counts' => ['query' => 2], 'meta' => ['stages' => []], 'job_uuid' => null,
        'events' => [['kind' => 'query', 'sql' => 'select ?']], ...$overrides,
    ];
}

it('finds one execution with its events, and nulls stay null', function () {
    $run = execution();
    app(Storage::class)->store([], [], [$run]);

    $found = app(Storage::class)->execution($run['uuid']);

    expect($found->name)->toBe('GET /books')
        ->and($found->events)->toBe([['kind' => 'query', 'sql' => 'select ?']])
        ->and($found->counts)->toBe(['query' => 2])
        ->and($found->user_id)->toBeNull()
        ->and($found->job_uuid)->toBeNull()
        ->and($found->failed)->toBeFalse()
        ->and(app(Storage::class)->execution((string) Str::ulid()))->toBeNull()
        ->and(app(Storage::class)->execution('not-a-ulid'))->toBeNull();
});

it('filters by name, user, exception, failure and slowness, newest or slowest first', function () {
    app(Storage::class)->store([], [], [
        execution(['name' => 'GET /a', 'duration' => 5, 'started_at' => time() - 30, 'user_id' => '7']),
        execution(['name' => 'GET /a', 'duration' => 900, 'started_at' => time() - 20, 'failed' => true, 'exceptions' => ['abc']]),
        execution(['name' => 'GET /b', 'duration' => 50, 'started_at' => time() - 10]),
    ]);
    $storage = app(Storage::class);

    expect($storage->executions(['name' => 'GET /a'], 3_600)->pluck('duration')->all())->toBe([900.0, 5.0])
        ->and($storage->executions([], 3_600, 'slowest')->pluck('duration')->all())->toBe([900.0, 50.0, 5.0])
        ->and($storage->executions(['user' => '7'], 3_600))->toHaveCount(1)
        ->and($storage->executions(['exception' => 'abc'], 3_600))->toHaveCount(1)
        ->and($storage->executions(['failed' => true], 3_600))->toHaveCount(1)
        ->and($storage->executions(['slower' => 40.0], 3_600))->toHaveCount(2)
        ->and($storage->executions([], 3_600, limit: 1, offset: 1)->first()->duration)->toBe(900.0);
});

it('finds the rest of a trace and every attempt of a job', function () {
    $request = execution(['trace' => 'T9']);
    $job = execution(['trace' => 'T9', 'type' => 'job', 'job_uuid' => 'J1', 'started_at' => time() + 1]);
    $retry = execution(['trace' => 'T9', 'type' => 'job', 'job_uuid' => 'J1', 'started_at' => time() + 2]);
    app(Storage::class)->store([], [], [$request, $job, $retry]);

    expect(app(Storage::class)->related('T9', $request['uuid'])->pluck('uuid')->all())->toBe([$job['uuid'], $retry['uuid']])
        ->and(app(Storage::class)->attempts('J1')->pluck('uuid')->all())->toBe([$job['uuid'], $retry['uuid']]);
});
