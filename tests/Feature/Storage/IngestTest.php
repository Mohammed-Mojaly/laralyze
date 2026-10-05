<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MohammedMojaly\Laralyze\Contracts\Ingest;
use MohammedMojaly\Laralyze\Dashboard\Health;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Ingest\Batch;
use MohammedMojaly\Laralyze\Ingest\DatabaseIngest;
use MohammedMojaly\Laralyze\Ingest\DirectIngest;
use MohammedMojaly\Laralyze\Metrics\Period;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;
use MohammedMojaly\Laralyze\Support\Outage;

beforeEach(function () {
    if (usingClickHouse()) {
        $this->markTestSkipped('ClickHouse always writes directly.');
    }

    app()->detectEnvironment(fn () => 'local');
    useIngest('database');

    // What the test's own setup recorded stays out of the counts.
    Laralyze::buffer()->clear();
});

function useIngest(string $driver): void
{
    config(['laralyze.ingest.driver' => $driver]);
    app()->forgetInstance(Ingest::class);
    app()->forgetInstance(Health::class);
}

function checkout(int $value): void
{
    Laralyze::record('checkout', 'pro', $value)->count()->sum()->min()->max()->histogram();
}

/**
 * Laralyze's tables as plain rows, without their ids, in a stable order.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function snapshot(): array
{
    $rows = fn (string $table, array $order) => DB::table($table)->orderBy($order[0])->when(isset($order[1]), fn ($q) => $q->orderBy($order[1]))->get()
        // Four decimals, like the columns: SQLite keeps floats, with their last-digit noise.
        ->map(fn ($row) => collect((array) $row)->except('id')->map(fn ($value) => is_numeric($value) ? round((float) $value, 4) : $value)->all())
        ->sortBy(fn ($row) => json_encode($row))->values()->all();

    return [
        'aggregates' => $rows('laralyze_aggregates', ['bucket', 'key_hash']),
        'values' => $rows('laralyze_values', ['type', 'key_hash']),
        'executions' => $rows('laralyze_executions', ['uuid']),
    ];
}

it('turns each flush into one queued row, and nothing else', function () {
    checkout(10);
    Laralyze::set('plan', 'pro', 'yearly');
    Laralyze::flush();

    expect(app(Ingest::class))->toBeInstanceOf(DatabaseIngest::class)
        ->and(DB::table('laralyze_ingest')->count())->toBe(1)
        ->and(DB::table('laralyze_aggregates')->count())->toBe(0)
        ->and(DB::table('laralyze_values')->count())->toBe(0);
});

it('merges the queue into Laralyze\'s tables and empties it', function () {
    foreach ([10, 30, 20] as $value) {
        checkout($value);
        Laralyze::flush();
    }

    expect(Laralyze::digest())->toBe(3)
        ->and(DB::table('laralyze_ingest')->count())->toBe(0);

    $minute = DB::table('laralyze_aggregates')->where('type', 'checkout')->where('period', Period::MINUTE)->pluck('value', 'aggregate')->map(fn ($v) => (float) $v);

    expect($minute['count'])->toBe(3.0)
        ->and($minute['sum'])->toBe(60.0)
        ->and($minute['min'])->toBe(10.0)
        ->and($minute['max'])->toBe(30.0)
        ->and(app(Ingest::class)->digestedAt())->toBe(time());
});

it('stores exactly what writing directly would have stored', function () {
    mt_srand(7);
    $now = time();

    $flushes = array_map(fn (int $i) => [
        'rows' => array_merge(...array_map(function () use ($now) {
            $aggregate = ['count', 'sum', 'min', 'max', 'h12', 'h30'][mt_rand(0, 5)];
            $period = [Period::MINUTE, Period::HOUR][mt_rand(0, 1)];

            return [[
                'bucket' => Period::bucket($now - mt_rand(0, 3) * 60, $period),
                'period' => $period,
                'type' => ['request', 'query', 'job'][mt_rand(0, 2)],
                'aggregate' => $aggregate,
                'key' => 'key '.mt_rand(1, 6),
                'value' => mt_rand(1, 100_000) / 100,
            ]];
        }, range(1, 40))),
        'values' => [['timestamp' => $now - mt_rand(0, 100), 'type' => 'seen', 'key' => 'user '.mt_rand(1, 4), 'value' => "flush {$i}"]],
        'executions' => [[
            'uuid' => sprintf('01J%023d', $i), 'trace' => sprintf('01J%023d', $i), 'type' => 'request', 'name' => 'GET /', 'status' => '200',
            'failed' => false, 'duration' => 12.5, 'user_id' => null, 'server' => 'web-1', 'started_at' => $now,
            'exceptions' => [], 'counts' => ['query' => 2], 'meta' => [], 'events' => [['type' => 'query', 'at' => 1.0]],
        ]],
    ], range(1, 25));

    // Rows merge once per flush; group them the way the buffer would have.
    $flushes = array_map(function (array $flush) {
        $merged = new Batch;
        $merged->add($flush);

        return ['rows' => $merged->rows(), 'values' => $merged->values(), 'executions' => $flush['executions']];
    }, $flushes);

    $direct = new DirectIngest(app(DatabaseStorage::class));

    foreach ($flushes as $flush) {
        $direct->write($flush['rows'], $flush['values'], $flush['executions']);
    }

    $expected = snapshot();

    foreach (['laralyze_aggregates', 'laralyze_values', 'laralyze_executions'] as $table) {
        DB::table($table)->truncate();
    }

    $queued = app(DatabaseIngest::class);

    foreach ($flushes as $flush) {
        $queued->write($flush['rows'], $flush['values'], $flush['executions']);
    }

    $queued->digest();

    expect(snapshot())->toEqual($expected);
});

it('keeps the whole batch when a digest fails half way, and counts it once later', function () {
    checkout(10);
    Laralyze::flush();
    checkout(20);
    Laralyze::flush();

    // The aggregates go in, then writing the values fails.
    $fail = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$fail) {
        if ($fail && str_contains($query, 'laralyze_executions')) {
            throw new RuntimeException('Disk full');
        }
    });
    Laralyze::addExecution([
        'uuid' => '01J00000000000000000000001', 'trace' => '01J00000000000000000000001', 'type' => 'request', 'name' => 'GET /', 'status' => '200',
        'failed' => false, 'duration' => 1.0, 'user_id' => null, 'server' => 'web-1', 'started_at' => time(),
        'exceptions' => [], 'counts' => [], 'meta' => [], 'events' => [],
    ]);
    Laralyze::flush();

    expect(Laralyze::digest())->toBe(0)
        ->and(DB::table('laralyze_ingest')->count())->toBe(3)
        ->and(DB::table('laralyze_aggregates')->count())->toBe(0)
        ->and(Laralyze::lastFailure()['message'] ?? null)->toBe('Disk full');

    $fail = false;
    Outage::end();

    expect(Laralyze::digest())->toBe(3);

    $minute = DB::table('laralyze_aggregates')->where('type', 'checkout')->where('period', Period::MINUTE)->pluck('value', 'aggregate');

    expect((float) $minute['count'])->toBe(2.0)
        ->and((float) $minute['sum'])->toBe(30.0)
        ->and(DB::table('laralyze_executions')->count())->toBe(1);
});

it('lets one digest run at a time', function () {
    checkout(10);
    Laralyze::flush();

    $lock = Cache::lock('laralyze:digest', 300);
    $lock->get();

    expect(Laralyze::digest())->toBe(0)
        ->and(DB::table('laralyze_ingest')->count())->toBe(1);

    $lock->release();

    expect(Laralyze::digest())->toBe(1);
});

it('digests now and then after a flush when the scheduler doesn\'t', function () {
    config(['laralyze.ingest.lottery' => [1, 1]]);

    checkout(10);
    Laralyze::flush();

    expect(DB::table('laralyze_ingest')->count())->toBe(0)
        ->and(DB::table('laralyze_aggregates')->where('type', 'checkout')->count())->toBeGreaterThan(0);

    // A digest ran just now, so the next flush leaves it to the scheduler.
    checkout(10);
    Laralyze::flush();

    expect(DB::table('laralyze_ingest')->count())->toBe(1);
});

it('warns when batches wait for the digest', function () {
    DB::table('laralyze_ingest')->insert(['created_at' => time() - 600, 'server' => 'web-1', 'payload' => '']);

    expect(app(Health::class)->problems())->toHaveCount(1)
        ->and(app(Health::class)->problems()[0]['level'])->toBe('warn')
        ->and(app(Health::class)->problems()[0]['title'])->toBe('1 batch has been waiting since 10 minutes ago.');

    DB::table('laralyze_ingest')->insert(['created_at' => time() - 7_200, 'server' => 'web-1', 'payload' => '']);
    app()->forgetInstance(Health::class);

    expect(app(Health::class)->problems()[0]['level'])->toBe('bad');

    $this->get('/laralyze')->assertSee('2 batches have been waiting since 2 hours ago.')->assertSee('schedule:run');
});

it('writes directly until an upgraded app creates the ingest table', function () {
    Schema::drop('laralyze_ingest');

    try {
        checkout(10);
        Laralyze::flush();

        expect(DB::table('laralyze_aggregates')->where('type', 'checkout')->count())->toBeGreaterThan(0)
            ->and(Laralyze::lastFailure())->toBeNull()
            ->and(collect(app(Health::class)->problems())->pluck('title'))->toContain("Laralyze's ingest table is missing.");
    } finally {
        (include __DIR__.'/../../../database/migrations/2026_10_06_000000_create_laralyze_ingest_table.php')->up();
    }
});

it('removes batches older than the retention period when trimming', function () {
    DB::table('laralyze_ingest')->insert([
        ['created_at' => time() - 31 * 86_400, 'server' => 'web-1', 'payload' => ''],
        ['created_at' => time() - 60, 'server' => 'web-1', 'payload' => ''],
    ]);

    Laralyze::trim();

    expect(DB::table('laralyze_ingest')->count())->toBe(1);
});

it('schedules the digest only when writes are queued', function () {
    $scheduled = fn () => collect(app(Schedule::class)->events())->pluck('description')->all();

    $this->rebootWith(['laralyze.ingest.driver' => 'direct']);

    expect($scheduled())->not->toContain('laralyze:digest');

    $this->rebootWith(['laralyze.ingest.driver' => 'database']);

    expect($scheduled())->toContain('laralyze:digest');
});
