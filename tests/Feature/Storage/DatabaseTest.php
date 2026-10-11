<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Database\Errors;
use MohammedMojaly\Laralyze\Database\Reader;
use MohammedMojaly\Laralyze\Database\Readers\MySql;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Recorders;

function lookAtDatabase(array $config = []): void
{
    app(Recorders\Database::class, ['config' => $config])->snapshot();
    Laralyze::flush();
}

/**
 * @return array<string, mixed>
 */
function databaseValue(string $type, string $connection = 'testing'): array
{
    return json_decode((string) app(Storage::class)->values($type, [$connection])->first()?->value, true) ?: [];
}

function onMySql(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

it('counts database errors by kind and connection', function () {
    $pdo = new PDOException('Deadlock found when trying to get lock');
    $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
    report(new QueryException('testing', 'update orders set paid = 1', [], $pdo));
    report(new RuntimeException('Not a database error'));
    Laralyze::flush();

    expect(app(Storage::class)->aggregate('db_error', ['count'], 3_600)->pluck('count', 'key')->all())
        ->toBe([Errors::key('testing', 'deadlock') => 1.0]);
});

it('looks at the default connection: its engine, size and largest tables', function () {
    lookAtDatabase();

    $now = databaseValue('database');
    $size = databaseValue('database_size');

    expect($now['engine'])->toStartWith(['sqlite' => 'SQLite', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL', 'sqlsrv' => 'SQL Server'][DB::connection()->getDriverName()])
        ->and($size['tables'])->not->toBeEmpty()
        ->and(collect($size['tables'])->firstWhere('name', 'laralyze_values')['own'] ?? null)->toBeTrue()
        ->and($size['history'])->toHaveCount(1)
        ->and($size['history'][0]['day'])->toBe(date('Y-m-d'));
});

it('measures size once an hour, and looks at the rest every minute', function () {
    lookAtDatabase();
    $first = databaseValue('database_size')['at'];

    $this->travel(10)->minutes();
    lookAtDatabase();
    expect(databaseValue('database_size')['at'])->toBe($first)
        ->and(databaseValue('database')['at'])->toBeGreaterThan($first);

    $this->travel(1)->hours();
    lookAtDatabase();
    expect(databaseValue('database_size')['at'])->toBeGreaterThan($first);
});

it('measures once an hour even before the last measure is saved', function () {
    // With database ingest, a measure is only readable after the next digest.
    $sizes = 0;
    DB::listen(function ($query) use (&$sizes) {
        $sizes += (int) preg_match('/pragma_table_list|data_length/i', $query->sql);
    });

    app(Recorders\Database::class, ['config' => []])->snapshot();
    app(Recorders\Database::class, ['config' => []])->snapshot();

    expect($sizes)->toBe(1);
});

it('keeps going when a connection fails', function () {
    config(['database.connections.broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'none']]);

    lookAtDatabase(['connections' => ['broken', null]]);

    expect(databaseValue('database'))->not->toBeEmpty()
        ->and(databaseValue('database', 'broken'))->toBe([]);
});

it('records none of its own queries as the app\'s', function () {
    lookAtDatabase();

    $own = fn (string $key) => preg_match('/processlist|innodb_trx|@@max_connections|global status|c\.extra like/i', $key);

    expect(app(Storage::class)->aggregate('query', ['count'], 3_600)->pluck('key')->filter($own))->toBeEmpty();
});

it('adds the look to the schedule, on one server when the cache can lock', function () {
    config(['cache.default' => 'array']);
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => $event->description === 'laralyze:database');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->onOneServer)->toBeTrue();
});

it('reads connections, uptime and ids on MySQL and MariaDB', function () {
    Schema::create('tiny_ids', fn ($table) => $table->tinyIncrements('id'));
    DB::table('tiny_ids')->insert(array_fill(0, 240, []));

    // MySQL caches table statistics for a day by default.
    if (DB::connection()->getDriverName() === 'mysql' && ! str_contains(DB::connection()->getServerVersion(), 'MariaDB')) {
        DB::statement('set session information_schema_stats_expiry = 0');
    }

    try {
        lookAtDatabase();
    } finally {
        Schema::drop('tiny_ids');
    }

    $now = databaseValue('database');
    $checks = collect($now['checks'])->keyBy('key');
    $tiny = collect(databaseValue('database_size')['ids'])->firstWhere('table', 'tiny_ids');

    expect($checks['connections']['percent'])->toBeGreaterThan(0)
        ->and($checks['long_running']['status'])->toBe('ok')
        ->and($now['started_at'])->toBeLessThanOrEqual(time())
        ->and($tiny['max'])->toEqual(255)
        ->and($tiny['status'])->toBe('problem')
        ->and(app(Storage::class)->total('db_connections', ['max'], 3_600)->max)->toBeGreaterThan(0.0);
})->skip(fn () => ! onMySql(), 'MySQL and MariaDB only');

it('finds a transaction left open on MySQL and MariaDB', function () {
    config(['database.connections.second' => config('database.connections.testing')]);
    Schema::create('held_rows', fn ($table) => $table->id());

    $second = DB::connection('second');
    $second->beginTransaction();
    $second->insert('insert into held_rows () values ()');
    sleep(2);

    try {
        lookAtDatabase(['long_running' => 1]);
    } finally {
        $second->rollBack();
        Schema::drop('held_rows');
    }

    $open = collect(databaseValue('database')['checks'])->firstWhere('key', 'open_transactions');

    expect($open['status'])->toBe('warning')
        ->and($open['rows'][0]['seconds'])->toBeGreaterThanOrEqual(1);
})->skip(fn () => ! onMySql(), 'MySQL and MariaDB only');

it('lists long-running queries without their values', function () {
    $reader = new class(DB::connection(), 30) extends MySql
    {
        public function running(array $processes): array
        {
            return $this->longRunning($processes);
        }
    };

    $process = fn (int $id, string $command, int $time, ?string $info) => (object) ['Id' => $id, 'User' => 'app', 'Command' => $command, 'Time' => $time, 'Info' => $info];

    $check = $reader->running([
        $process(1, 'Query', 45, "select * from users where email = 'sara@example.com'"),
        $process(2, 'Query', 5, 'select 1'),
        $process(3, 'Sleep', 900, null),
    ]);

    expect($check['status'])->toBe('warning')
        ->and($check['rows'])->toHaveCount(1)
        ->and($check['rows'][0]['query'])->toBe('select * from users where email = ?')
        ->and($check['rows'][0]['seconds'])->toBe(45);
});

it('knows the largest id of each column type', function () {
    expect(Reader::maxId('int', false))->toBe(2_147_483_647.0)
        ->and(Reader::maxId('INT', true))->toBe(4_294_967_295.0)
        ->and(Reader::maxId('bigint', false))->toBe(9.223372036854775807E18)
        ->and(Reader::maxId('varchar', false))->toBeNull();
});
