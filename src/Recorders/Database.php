<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Database\Check;
use MohammedMojaly\Laralyze\Database\Errors;
use MohammedMojaly\Laralyze\Database\Incidents;
use MohammedMojaly\Laralyze\Database\Reader;
use MohammedMojaly\Laralyze\Laralyze;

/**
 * How the database itself is doing: connections, long-running queries,
 * locks, restarts, size and ids running out. A task Laralyze adds to your
 * schedule looks once a minute, and at size once an hour. It only reads.
 *
 * Database errors by kind are counted by the Exceptions recorder.
 */
class Database extends Recorder
{
    /**
     * Seconds between two looks at the size and the ids.
     */
    public const MEASURE_EVERY = 3_600;

    /**
     * Largest tables kept with each measure.
     */
    public const TABLES = 30;

    /**
     * Days of size history kept, one point a day.
     */
    public const DAYS = 8;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(Laralyze $laralyze, array $config, protected DatabaseManager $databases, protected Storage $storage)
    {
        parent::__construct($laralyze, $config);
    }

    public function register(Application $app): void
    {
        $app->afterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($app, $schedule));

        if ($app->resolved(Schedule::class)) {
            $this->schedule($app, $app->make(Schedule::class));
        }
    }

    protected function schedule(Application $app, Schedule $schedule): void
    {
        $event = $schedule->call(fn () => $this->snapshot())
            ->everyMinute()
            ->name('laralyze:database');

        // Every server may run the scheduler, but they share the database: one looks. It needs a cache that can lock.
        if ($this->laralyze->rescue(fn () => $app->make('cache')->store()->getStore() instanceof LockProvider, false)) {
            $event->onOneServer();
        }
    }

    /**
     * Names of the connections to watch; null is the default one.
     *
     * @return list<string>
     */
    public function connections(): array
    {
        return self::names((array) ($this->config['connections'] ?? [null]));
    }

    /**
     * The connections the configured recorder watches, without building it.
     *
     * @return list<string>
     */
    public static function watched(): array
    {
        foreach ((array) config('laralyze.recorders', []) as $class => $options) {
            if (is_a((string) $class, self::class, true)) {
                return self::names((array) ($options['connections'] ?? [null]));
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $names
     * @return list<string>
     */
    protected static function names(array $names): array
    {
        return array_values(array_unique(array_map(fn ($name) => (string) ($name ?? config('database.default')), $names)));
    }

    public function snapshot(): void
    {
        foreach ($this->connections() as $name) {
            $this->laralyze->rescue(fn () => $this->look($name));
        }
    }

    protected function look(string $name): void
    {
        $at = now()->getTimestamp();

        // Its own queries, and its own cache, aren't the app's.
        [$now, $incidents, $size] = $this->laralyze->ignore(function () use ($name, $at) {
            $db = $this->databases->connection($name);
            $reader = Reader::for($db, (int) ($this->config['long_running'] ?? 30));

            $look = (array) $this->laralyze->rescue(fn () => $reader->look(), []);
            $now = [
                'engine' => $reader->engine(),
                'started_at' => $this->laralyze->rescue(fn () => $reader->startedAt()),
                'checks' => (array) ($look['checks'] ?? []),
                'sessions' => (array) ($look['sessions'] ?? []),
            ];

            $previous = $this->storage->values('database_size', [$name])->first();
            $size = $this->due($name, (int) ($previous->timestamp ?? 0)) ? $this->measure($db, $reader, json_decode((string) ($previous->value ?? ''), true)) : null;

            return [$now, $this->incidents($name, [...$look, 'started_at' => $now['started_at']], $at), $size];
        });

        foreach ($now['checks'] as $check) {
            match ($check['key']) {
                'connections' => $this->laralyze->record('db_connections', $name, (float) $check['percent'])->avg()->max(),
                'long_running' => $this->laralyze->record('db_long_running', $name, count($check['rows']))->max(),
                default => null,
            };
        }

        [$save, $new, $deadlocks] = $incidents;

        foreach ($save as $incident) {
            $this->laralyze->set('db_incident', "{$name}|{$incident['id']}", (string) json_encode(['connection' => $name, ...$incident], JSON_INVALID_UTF8_SUBSTITUTE), $at);
        }

        foreach ($new as $incident) {
            $this->laralyze->record('db_incident', Errors::key($name, $incident['kind']))->count();
        }

        // Every deadlock, including the ones Laravel retried and the ones between other apps.
        if ($deadlocks > 0) {
            $this->laralyze->merge('db_deadlocks', $name, ['count' => $deadlocks]);
        }

        $this->laralyze->set('database', $name, (string) json_encode(['at' => $at, ...$now], JSON_INVALID_UTF8_SUBSTITUTE), $at);

        if ($size !== null) {
            $this->laralyze->record('db_size', $name, (float) $size['total'])->max();
            $this->laralyze->set('database_size', $name, (string) json_encode($size, JSON_INVALID_UTF8_SUBSTITUTE), $size['at']);
        }
    }

    /**
     * The incidents to save, the new ones among them, and how many deadlocks
     * happened since the last look. What the last look saw is kept in the
     * cache, which is faster than reading it back and doesn't wait for ingest.
     *
     * @param  array<string, mixed>  $look
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: int}
     */
    protected function incidents(string $name, array $look, int $at): array
    {
        // A cache that forgets between runs of the scheduler would start every incident anew.
        if ($this->laralyze->rescue(fn () => in_array(Cache::getStore()::class, [ArrayStore::class, NullStore::class], true), true)) {
            return [[], [], 0];
        }

        $key = "laralyze:database:state:{$name}";
        $before = (array) $this->laralyze->rescue(fn () => Cache::get($key, []), []);
        $today = now()->toDateString();
        $kept = ($before['day'] ?? null) === $today ? (int) ($before['kept'] ?? 0) : 0;

        [$open, $save, $new] = Incidents::track((array) ($before['open'] ?? []), Incidents::found($look, $before), $at, max(0, Incidents::PER_DAY - $kept));

        $deadlocks = isset($look['deadlocks']) ? (int) $look['deadlocks'] : null;
        $counted = isset($before['deadlocks']) ? (int) $before['deadlocks'] : null;

        $this->laralyze->rescue(fn () => Cache::put($key, [
            'day' => $today,
            'kept' => $kept + count(array_filter($new, fn (array $incident) => $incident['kept'])),
            'open' => $open,
            'deadlocks' => $deadlocks ?? $counted,
            'deadlock_at' => $look['deadlock']['at'] ?? $before['deadlock_at'] ?? null,
            'started_at' => $look['started_at'] ?? $before['started_at'] ?? null,
        ], 86_400));

        return [$save, $new, $deadlocks !== null && $counted !== null ? max(0, $deadlocks - $counted) : 0];
    }

    /**
     * Whether the hourly measure is due. A minute early, so the scheduler's
     * own timing doesn't skip an hour. With database ingest the last measure
     * is only readable after the next digest, so the cache remembers it too.
     */
    protected function due(string $name, int $measuredAt): bool
    {
        if ($measuredAt > now()->getTimestamp() - self::MEASURE_EVERY + 60) {
            return false;
        }

        return (bool) $this->laralyze->rescue(fn () => Cache::add("laralyze:database:measured:{$name}", true, self::MEASURE_EVERY - 60), true);
    }

    /**
     * Size, the largest tables and ids, with a point a day kept for growth.
     *
     * @param  mixed  $previous  The last measure, decoded.
     * @return array<string, mixed>
     */
    protected function measure(Connection $db, Reader $reader, mixed $previous): array
    {
        $prefix = $db->getTablePrefix();
        $schema = in_array($db->getDriverName(), ['mysql', 'mariadb'], true) ? $db->getDatabaseName() : null;

        $tables = collect($db->getSchemaBuilder()->getTables($schema))
            ->map(fn (array $table) => ['name' => (string) $table['name'], 'size' => isset($table['size']) ? (int) $table['size'] : null, 'own' => str_starts_with((string) $table['name'], $prefix.'laralyze_')])
            ->sortByDesc('size')
            ->values();

        $total = $tables->sum('size');

        // SQLite can lack the table that sizes each table; the file is the whole database.
        if ($total === 0 && $db->getDriverName() === 'sqlite' && is_file($file = (string) $db->getDatabaseName())) {
            $total = (int) filesize($file);
        }

        $largest = $tables->take(self::TABLES)->all();
        $history = collect((array) ($previous['history'] ?? []))
            ->reject(fn (array $point) => ($point['day'] ?? null) === now()->toDateString())
            ->push(['day' => now()->toDateString(), 'total' => $total, 'tables' => array_column(array_filter($largest, fn (array $table) => $table['size'] !== null), 'size', 'name')])
            ->take(-self::DAYS)
            ->values()
            ->all();

        $ids = $this->laralyze->rescue(fn () => $reader->ids());

        return [
            'at' => now()->getTimestamp(),
            'total' => $total,
            'tables' => $largest,
            'count' => $tables->count(),
            'ids' => $ids === null ? null : $this->ids($ids),
            'history' => $history,
        ];
    }

    /**
     * The ids closest to running out, with how they stand.
     *
     * @param  list<array{table: string, column: string, type: string, used: float, max: float}>  $ids
     * @return list<array<string, mixed>>
     */
    protected function ids(array $ids): array
    {
        return array_values(collect($ids)
            ->map(fn (array $id) => [...$id, 'percent' => $id['max'] > 0 ? $id['used'] / $id['max'] * 100 : 0])
            ->map(fn (array $id) => [...$id, 'status' => Check::status($id['percent'])])
            ->sortByDesc('percent')
            ->take(5)
            ->all());
    }
}
