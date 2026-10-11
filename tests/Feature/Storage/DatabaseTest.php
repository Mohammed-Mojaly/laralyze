<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Database\Check;
use MohammedMojaly\Laralyze\Database\Errors;
use MohammedMojaly\Laralyze\Database\Reader;
use MohammedMojaly\Laralyze\Database\Readers\MySql;
use MohammedMojaly\Laralyze\Database\Readers\Postgres;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Recorders;
use MohammedMojaly\Laralyze\Support\Format;

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
        $sizes += (int) preg_match('/pragma_table_list|data_length|pg_total_relation_size|total_pages/i', $query->sql);
    });

    app(Recorders\Database::class, ['config' => []])->snapshot();
    app(Recorders\Database::class, ['config' => []])->snapshot();

    expect($sizes)->toBe(1);
});

it('keeps no incidents with a cache that forgets between runs', function () {
    config(['cache.default' => 'array']);

    $recorder = app(Recorders\Database::class, ['config' => []]);

    expect((fn () => $this->incidents('testing', ['checks' => [Check::make('connections', 'Connections', Check::PROBLEM, '95 / 100', percent: 95.0)]], time()))->call($recorder))
        ->toBe([[], [], 0]);
});

it('keeps going when a connection fails', function () {
    config(['database.connections.broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'none']]);

    lookAtDatabase(['connections' => ['broken', null]]);

    expect(databaseValue('database'))->not->toBeEmpty()
        ->and(databaseValue('database', 'broken'))->toBe([]);
});

it('records none of its own queries as the app\'s', function () {
    lookAtDatabase();

    $own = fn (string $key) => preg_match('/processlist|innodb_trx|@@max_connections|global status|c\.extra like|pg_stat_activity|pg_sequences|pg_postmaster_start_time/i', $key);

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
    // Incidents need a cache that outlives one run of the scheduler.
    config(['cache.default' => 'file']);
    Cache::forget('laralyze:database:state:testing');

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

    $now = databaseValue('database');
    $open = collect($now['checks'])->firstWhere('key', 'open_transactions');
    $holder = $open['rows'][0]['id'];
    $incidents = app(Storage::class)->values('db_incident')->map(fn ($row) => json_decode((string) $row->value, true));

    expect($open['status'])->toBe('warning')
        ->and($open['rows'][0]['seconds'])->toBeGreaterThanOrEqual(1)
        ->and(collect($now['sessions'])->firstWhere('id', $holder)['state'])->toBe('idle, transaction open')
        ->and($incidents)->toHaveCount(1)
        ->and($incidents->first()['kind'])->toBe('open_transaction')
        ->and($incidents->first()['summary'])->toContain("Session {$holder}")
        ->and(app(Storage::class)->aggregate('db_incident', ['count'], 3_600)->first()->count)->toBe(1.0);
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

function onPostgres(): bool
{
    return DB::connection()->getDriverName() === 'pgsql';
}

it('reads connections, uptime, deadlocks and ids on PostgreSQL', function () {
    Schema::create('tiny_ids', fn ($table) => $table->smallIncrements('id'));
    DB::statement("select setval(pg_get_serial_sequence('tiny_ids', 'id'), 30000)");

    try {
        lookAtDatabase();
    } finally {
        Schema::drop('tiny_ids');
    }

    $now = databaseValue('database');
    $checks = collect($now['checks'])->keyBy('key');
    $tiny = collect(databaseValue('database_size')['ids'])->firstWhere('table', 'tiny_ids');

    expect($now['engine'])->toMatch('/^PostgreSQL \d+\.\d+$/')
        ->and($checks->keys()->all())->toBe(['connections', 'long_running', 'blocking', 'open_transactions'])
        ->and($checks['connections']['percent'])->toBeGreaterThan(0)
        ->and($checks['blocking']['status'])->toBe('ok')
        ->and($now['started_at'])->toBeLessThanOrEqual(time())
        ->and($tiny['column'])->toBe('id')
        ->and($tiny['max'])->toEqual(32_767)
        ->and($tiny['status'])->toBe('problem');
})->skip(fn () => ! onPostgres(), 'PostgreSQL only');

it('finds a transaction left open, and who it blocks, on PostgreSQL', function () {
    config(['cache.default' => 'file']);
    Cache::forget('laralyze:database:state:testing');

    config(['database.connections.second' => config('database.connections.testing')]);
    Schema::create('held_rows', fn ($table) => $table->id());
    DB::table('held_rows')->insert(['id' => 1]);

    $second = DB::connection('second');
    $second->beginTransaction();
    $second->update('update held_rows set id = 1 where id = 1');
    $holder = (int) $second->scalar('select pg_backend_pid()');

    // A third session waits for the row, from another process.
    $config = config('database.connections.testing');
    $waiter = proc_open([PHP_BINARY, '-r', sprintf(
        '$pdo = new PDO(%s, %s, %s); $pdo->exec("update held_rows set id = 1 where id = 1");',
        var_export("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", true),
        var_export($config['username'], true),
        var_export($config['password'], true),
    )], [], $pipes);
    sleep(2);

    try {
        lookAtDatabase(['long_running' => 1]);
    } finally {
        $second->rollBack();
        proc_close($waiter);
        Schema::drop('held_rows');
    }

    $now = databaseValue('database');
    $checks = collect($now['checks'])->keyBy('key');
    $incidents = app(Storage::class)->values('db_incident')->map(fn ($row) => json_decode((string) $row->value, true))->keyBy('kind');

    expect($checks['open_transactions']['status'])->toBe('warning')
        ->and($checks['open_transactions']['rows'][0]['id'])->toBe($holder)
        ->and($checks['blocking']['status'])->toBe('problem')
        ->and($checks['blocking']['rows'][0]['blocking'])->toBe($holder)
        ->and($checks['blocking']['rows'][0]['query'])->toBe('update held_rows set id = ? where id = ?')
        ->and(collect($now['sessions'])->firstWhere('id', $holder)['state'])->toBe('idle, transaction open')
        ->and(collect($now['sessions'])->firstWhere('blocked_by', $holder)['state'])->toBe('waiting for a lock')
        ->and($incidents->keys()->sort()->values()->all())->toBe(['blocking', 'long_running', 'open_transaction'])
        ->and($incidents['blocking']['summary'])->toStartWith("Session {$holder} ({$config['username']}) blocked 1 session")
        ->and($incidents['open_transaction']['summary'])->toEndWith('kept a transaction open, idle for '.Format::duration($checks['open_transactions']['rows'][0]['seconds'] * 1_000));
})->skip(fn () => ! onPostgres(), 'PostgreSQL only');

it('reads PostgreSQL sessions: running, waiting and idle in a transaction', function () {
    $reader = new class(DB::connection(), 30) extends Postgres
    {
        public function read(array $processes): array
        {
            return [$this->longRunning($processes), $this->blocking($processes), $this->openTransactions($processes), $this->sessions($processes)];
        }
    };

    $process = fn (int $pid, ?string $state, int $running, int $idle, string $blocking = '{}', ?string $wait = null, ?string $query = null) => (object) [
        'pid' => $pid, 'usename' => 'app', 'host' => '10.0.0.5', 'datname' => 'shop', 'state' => $state,
        'running' => $running, 'idle' => $idle, 'wait_event_type' => $wait, 'blocking' => $blocking, 'query' => $query,
    ];

    [$long, $blocking, $open, $sessions] = $reader->read([
        $process(11, 'active', 45, 45, query: "select * from users where email = 'sara@example.com'"),
        $process(12, 'active', 40, 40, '{13}', 'Lock', 'update orders set paid = 1 where id = 7'),
        $process(13, 'idle in transaction', 60, 50, query: 'update orders set paid = 0 where id = 7'),
        $process(14, 'idle', 900, 900, query: 'select 1'),
        // Another user's session, without pg_read_all_stats.
        $process(15, null, 0, 0, query: '<insufficient privilege>'),
    ]);

    expect($long['rows'])->toBe([
        ['id' => 11, 'user' => 'app', 'seconds' => 45, 'query' => 'select * from users where email = ?'],
        ['id' => 12, 'user' => 'app', 'seconds' => 40, 'query' => 'update orders set paid = ? where id = ?'],
    ])
        ->and($blocking['rows'])->toBe([['waiting' => 12, 'blocking' => 13, 'blocker' => 'app', 'seconds' => 40, 'query' => 'update orders set paid = ? where id = ?']])
        ->and($open['rows'])->toBe([['id' => 13, 'user' => 'app', 'seconds' => 50]])
        ->and(array_column($sessions, 'id'))->toBe([13, 11, 12])
        ->and(array_column($sessions, 'state'))->toBe(['idle, transaction open', 'active', 'waiting for a lock'])
        ->and(array_column($sessions, 'blocked_by'))->toBe([null, null, 13])
        ->and($sessions[0]['query'])->toBe('update orders set paid = ? where id = ?');
});
