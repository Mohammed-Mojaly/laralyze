<?php

use Illuminate\Support\Facades\DB;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Metrics\Period;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;

const STORED_AT = 1_727_700_030;

function storedValue(string $aggregate, string $key = 'GET /', int $period = Period::MINUTE): ?float
{
    $value = DB::table('laralyze_aggregates')
        ->where('period', $period)
        ->where('aggregate', $aggregate)
        ->where('key_hash', hash('xxh128', $key))
        ->value('value');

    return $value === null ? null : (float) $value;
}

function recordAndFlush(callable $record): void
{
    $record();
    Laralyze::flush();
}

it('writes buffered metrics after a flush', function () {
    recordAndFlush(fn () => Laralyze::record('request', 'GET /', 120, STORED_AT)->count()->max());

    expect(storedValue('count'))->toBe(1.0)
        ->and(storedValue('max'))->toBe(120.0)
        ->and(storedValue('count', period: Period::HOUR))->toBe(1.0)
        ->and(Laralyze::buffer()->isEmpty())->toBeTrue();
});

it('adds counts and sums across flushes', function () {
    recordAndFlush(fn () => Laralyze::record('request', 'GET /', 100, STORED_AT)->count()->sum());
    recordAndFlush(fn () => Laralyze::record('request', 'GET /', 50.5, STORED_AT)->count()->sum());

    expect(storedValue('count'))->toBe(2.0)
        ->and(storedValue('sum'))->toBe(150.5);
});

it('keeps the true minimum and maximum across flushes', function () {
    recordAndFlush(fn () => Laralyze::record('request', 'GET /', 80, STORED_AT)->min()->max());
    recordAndFlush(fn () => Laralyze::record('request', 'GET /', 300, STORED_AT)->min()->max());
    recordAndFlush(fn () => Laralyze::record('request', 'GET /', 20, STORED_AT)->min()->max());

    expect(storedValue('min'))->toBe(20.0)
        ->and(storedValue('max'))->toBe(300.0);
});

it('replaces values that are set again', function () {
    recordAndFlush(fn () => Laralyze::set('server', 'web-1', '{"cpu":10}', STORED_AT));
    recordAndFlush(fn () => Laralyze::set('server', 'web-1', '{"cpu":55}', STORED_AT + 5));

    expect(DB::table('laralyze_values')->get(['timestamp', 'value'])->all())
        ->toHaveCount(1)
        ->and(DB::table('laralyze_values')->value('value'))->toBe('{"cpu":55}')
        ->and((int) DB::table('laralyze_values')->value('timestamp'))->toBe(STORED_AT + 5);
});

it('stores thousands of rows in one flush', function () {
    recordAndFlush(function () {
        foreach (range(1, 2_500) as $i) {
            Laralyze::record('import', "row {$i}", $i, STORED_AT)->count();
        }
    });

    expect(DB::table('laralyze_aggregates')->where('type', 'import')->count())->toBe(5_000);
});

it('round-trips keys with multibyte text', function () {
    $key = 'GET /مرحبا/😀';

    recordAndFlush(fn () => Laralyze::record('request', $key, timestamp: STORED_AT)->count());

    expect(DB::table('laralyze_aggregates')->where('key_hash', hash('xxh128', $key))->value('key'))->toBe($key);
});

it('keeps the buffer when the storage connection is inside a transaction', function () {
    Laralyze::record('request', 'GET /', timestamp: STORED_AT)->count();

    DB::transaction(fn () => Laralyze::flush());

    expect(Laralyze::buffer()->isEmpty())->toBeFalse()
        ->and(DB::table('laralyze_aggregates')->count())->toBe(0);

    Laralyze::flush();

    expect(storedValue('count'))->toBe(1.0);
});

it('never throws when storage fails', function () {
    app()->instance(DatabaseStorage::class, new class(app('db'), config()) extends DatabaseStorage
    {
        public function store(array $rows, array $values, array $executions = []): void
        {
            throw new RuntimeException('Database is down');
        }
    });

    Laralyze::record('request', 'GET /', timestamp: STORED_AT)->count();

    expect(fn () => Laralyze::flush())->not->toThrow(Throwable::class);
});

it('ignores its own queries while writing', function () {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        if (Laralyze::isRecording()) {
            $queries++;
        }
    });

    recordAndFlush(fn () => Laralyze::record('request', 'GET /', timestamp: STORED_AT)->count());

    expect($queries)->toBe(0);
});

it('leaves out metrics a filter rejects', function () {
    Laralyze::filter(fn (string $type, string $key) => ! str_contains($key, '@'));

    recordAndFlush(function () {
        Laralyze::record('signup', 'sara@example.com', timestamp: STORED_AT)->count();
        Laralyze::record('signup', 'newsletter', timestamp: STORED_AT)->count();
    });

    expect(DB::table('laralyze_aggregates')->where('type', 'signup')->pluck('key')->unique()->values()->all())->toBe(['newsletter']);
});

it('merges values that were already aggregated', function () {
    recordAndFlush(function () {
        Laralyze::merge('import', 'users', ['count' => 3, 'sum' => 30, 'max' => 20], STORED_AT);
        Laralyze::merge('import', 'users', ['count' => 2, 'sum' => 5, 'max' => 4], STORED_AT);
    });

    $stored = DB::table('laralyze_aggregates')->where('type', 'import')->where('period', 60)->pluck('value', 'aggregate');

    expect((float) $stored['count'])->toBe(5.0)
        ->and((float) $stored['sum'])->toBe(35.0)
        ->and((float) $stored['max'])->toBe(20.0);
});
