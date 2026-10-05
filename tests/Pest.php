<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;
use MohammedMojaly\Laralyze\Tests\Concerns\UsesStorage;
use MohammedMojaly\Laralyze\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

pest()->use(UsesStorage::class)->group('storage')->in('Feature/Storage');

pest()->use(UsesStorage::class)->group('dashboard')->in('Feature/Dashboard');

pest()->use(UsesStorage::class)->group('clickhouse')->in('Feature/ClickHouse');

/**
 * Whether this run keeps Laralyze's data in ClickHouse (LARALYZE_TEST_STORAGE=clickhouse).
 */
function usingClickHouse(): bool
{
    return getenv('LARALYZE_TEST_STORAGE') === 'clickhouse';
}

/**
 * A Laralyze table as rows, whichever storage runs: aggregates with their
 * merged value, values without forgotten keys.
 *
 * @return Collection<int, stdClass>
 */
function laralyzeRows(string $table): Collection
{
    if (! usingClickHouse()) {
        return DB::table($table)->get();
    }

    $sql = match ($table) {
        'laralyze_aggregates' => "SELECT bucket, period, type, aggregate, key_hash, any(key) AS key,
            toFloat64(multiIf(aggregate = 'min', min(lowest), aggregate = 'max', max(highest), sum(total))) AS value
            FROM laralyze_aggregates GROUP BY bucket, period, type, aggregate, key_hash ORDER BY bucket, key_hash, aggregate",
        'laralyze_values' => 'SELECT type, key_hash, key, value, timestamp FROM laralyze_values FINAL WHERE deleted = 0 ORDER BY type, key',
        default => "SELECT * FROM {$table} ORDER BY started_at",
    };

    return collect(app(ClickHouseStorage::class)->client()->select($sql))->map(fn (array $row) => (object) $row);
}

/**
 * Run the callback as if it were a web request, not a console command.
 */
function asWebRequest(callable $callback): void
{
    (fn () => $this->isRunningInConsole = false)->call(app());

    try {
        $callback();
    } finally {
        (fn () => $this->isRunningInConsole = null)->call(app());
    }
}
