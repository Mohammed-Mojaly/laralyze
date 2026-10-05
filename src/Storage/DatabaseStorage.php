<?php

namespace MohammedMojaly\Laralyze\Storage;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use MohammedMojaly\Laralyze\Assistant\Chats;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Metrics\Histogram;
use MohammedMojaly\Laralyze\Metrics\Period;
use MohammedMojaly\Laralyze\Storage\Concerns\ReadsMetrics;
use MohammedMojaly\Laralyze\Support\Contention;
use stdClass;
use Throwable;

class DatabaseStorage implements Storage
{
    use ReadsMetrics;

    public const AGGREGATES = 'laralyze_aggregates';

    public const VALUES = 'laralyze_values';

    public const EXECUTIONS = 'laralyze_executions';

    protected const UNIQUE_AGGREGATE = ['bucket', 'period', 'type', 'aggregate', 'key_hash'];

    /**
     * Tries per statement when concurrent flushes get in each other's way.
     */
    protected const ATTEMPTS = 5;

    /**
     * Values only needed for a day, like when each visitor was last seen.
     */
    public const SHORT_LIVED_VALUES = ['visitor_seen'];

    public function __construct(protected DatabaseManager $db, protected Repository $config) {}

    public function connection(): Connection
    {
        return $this->db->connection($this->config->get('laralyze.storage.connection'));
    }

    /**
     * Whether `migrate` has created Laralyze's tables.
     */
    public function installed(): bool
    {
        $schema = $this->connection()->getSchemaBuilder();

        return $schema->hasTable(self::AGGREGATES) && $schema->hasTable(self::VALUES) && $schema->hasTable(self::EXECUTIONS);
    }

    public function inTransaction(): bool
    {
        return $this->connection()->transactionLevel() > 0;
    }

    /**
     * @param  list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>  $rows
     * @param  list<array{timestamp: int, type: string, key: string, value: string}>  $values
     * @param  list<array<string, mixed>>  $executions
     */
    public function store(array $rows, array $values, array $executions = []): void
    {
        if ($rows === [] && $values === [] && $executions === []) {
            return;
        }

        $connection = $this->connection();
        $merge = new MergeExpressions($connection, self::AGGREGATES);

        // No transaction around the batch: each statement commits on its own and
        // lets go of its locks at once, so concurrent flushes seldom deadlock.
        foreach ($this->groupByMerge($rows) as $kind => $group) {
            foreach ($this->chunk($connection, $this->prepareAggregates($group), 7) as $chunk) {
                $this->retrying(fn () => $connection->table(self::AGGREGATES)->upsert($chunk, self::UNIQUE_AGGREGATE, [
                    'value' => $merge->{$kind}('value'),
                ]));
            }
        }

        foreach ($this->chunk($connection, $this->prepareValues($values), 5) as $chunk) {
            $this->retrying(fn () => $connection->table(self::VALUES)->upsert($chunk, ['type', 'key_hash'], ['timestamp', 'value']));
        }

        foreach ($this->chunk($connection, $this->prepareExecutions($executions), 17) as $chunk) {
            $this->retrying(fn () => $connection->table(self::EXECUTIONS)->insert($chunk));
        }
    }

    /**
     * Each merge kind needs its own update expression, so each gets its own upsert.
     *
     * @param  list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>  $rows
     * @return array<'add'|'min'|'max', list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>>
     */
    protected function groupByMerge(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $kind = match ($row['aggregate']) {
                'min' => 'min',
                'max' => 'max',
                default => 'add',
            };

            $groups[$kind][] = $row;
        }

        return $groups;
    }

    /**
     * @param  list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>  $rows
     * @return list<array<string, int|string>>
     */
    protected function prepareAggregates(array $rows): array
    {
        $prepared = array_map(fn (array $row) => [
            'bucket' => $row['bucket'],
            'period' => $row['period'],
            'type' => $row['type'],
            'aggregate' => $row['aggregate'],
            'key' => $row['key'],
            'key_hash' => hash('xxh128', $row['key']),
            // Bound as strings so SQL Server doesn't choke on mixed int/float rows.
            'value' => sprintf('%.4F', $row['value']),
        ], $rows);

        // A stable write order keeps concurrent flushes from deadlocking each other.
        usort($prepared, fn (array $a, array $b) => [$a['bucket'], $a['period'], $a['type'], $a['aggregate'], $a['key_hash']]
            <=> [$b['bucket'], $b['period'], $b['type'], $b['aggregate'], $b['key_hash']]);

        return $prepared;
    }

    /**
     * @param  list<array{timestamp: int, type: string, key: string, value: string}>  $values
     * @return list<array<string, int|string>>
     */
    protected function prepareValues(array $values): array
    {
        $prepared = array_map(fn (array $value) => [
            'timestamp' => $value['timestamp'],
            'type' => $value['type'],
            'key' => $value['key'],
            'key_hash' => hash('xxh128', $value['key']),
            'value' => $value['value'],
        ], $values);

        usort($prepared, fn (array $a, array $b) => [$a['type'], $a['key_hash']] <=> [$b['type'], $b['key_hash']]);

        return $prepared;
    }

    /**
     * @param  list<array<string, mixed>>  $executions
     * @return list<array<string, int|string|null>>
     */
    protected function prepareExecutions(array $executions): array
    {
        return array_map(fn (array $execution) => [
            'uuid' => (string) $execution['uuid'],
            'trace' => (string) $execution['trace'],
            'type' => (string) $execution['type'],
            'name' => (string) $execution['name'],
            'name_hash' => hash('xxh128', (string) $execution['name']),
            'status' => (string) $execution['status'],
            'failed' => $execution['failed'] ? 1 : 0,
            'duration' => sprintf('%.2F', $execution['duration']),
            'user_id' => $execution['user_id'] === null ? null : (string) $execution['user_id'],
            'server' => (string) $execution['server'],
            'started_at' => (int) $execution['started_at'],
            'exceptions' => $execution['exceptions'] === [] ? '' : ','.implode(',', $execution['exceptions']).',',
            'counts' => (string) json_encode($execution['counts']),
            'meta' => (string) json_encode($execution['meta'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE),
            'job_uuid' => isset($execution['job_uuid']) ? (string) $execution['job_uuid'] : null,
            'events' => (string) json_encode($execution['events'], JSON_INVALID_UTF8_SUBSTITUTE),
        ], $executions);
    }

    /**
     * Single requests, jobs or commands, newest or slowest first. Filter by
     * what ran (type and name), by user, or by an exception they reported.
     *
     * @param  array{type?: string, name?: string, user?: string, exception?: string, failed?: bool, slower?: float}  $filters
     * @return Collection<int, stdClass>
     */
    public function executions(array $filters, int $window, string $order = 'recent', int $limit = 50, int $offset = 0): Collection
    {
        return $this->connection()->table(self::EXECUTIONS)
            ->select(['uuid', 'trace', 'type', 'name', 'status', 'failed', 'duration', 'user_id', 'server', 'started_at', 'counts', 'meta', 'job_uuid'])
            ->where('started_at', '>=', $this->now() - $window)
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['name'] ?? null, fn (Builder $query, string $name) => $query->where('name_hash', hash('xxh128', $name)))
            ->when($filters['user'] ?? null, fn (Builder $query, string $user) => $query->where('user_id', $user))
            ->when($filters['exception'] ?? null, fn (Builder $query, string $hash) => $query->where('exceptions', 'like', '%,'.$hash.',%'))
            ->when(isset($filters['failed']), fn (Builder $query) => $query->where('failed', $filters['failed'] ?? false))
            ->when($filters['slower'] ?? null, fn (Builder $query, float $ms) => $query->where('duration', '>=', $ms))
            ->orderByDesc($order === 'slowest' ? 'duration' : 'started_at')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $row) => $this->castExecution($row));
    }

    /**
     * One execution with its events, or null when it's gone.
     */
    public function execution(string $uuid): ?stdClass
    {
        $row = $this->connection()->table(self::EXECUTIONS)->where('uuid', $uuid)->first();

        return $row === null ? null : $this->castExecution($row);
    }

    /**
     * Everything else in the same trace, e.g. the jobs a request queued.
     *
     * @return Collection<int, stdClass>
     */
    public function related(string $trace, string $except): Collection
    {
        return $this->connection()->table(self::EXECUTIONS)
            ->select(['uuid', 'trace', 'type', 'name', 'status', 'failed', 'duration', 'user_id', 'server', 'started_at', 'counts', 'meta', 'job_uuid'])
            ->where('trace', $trace)
            ->where('uuid', '!=', $except)
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (stdClass $row) => $this->castExecution($row));
    }

    /**
     * Every attempt of one queued job, first to last.
     *
     * @return Collection<int, stdClass>
     */
    public function attempts(string $jobUuid): Collection
    {
        return $this->connection()->table(self::EXECUTIONS)
            ->select(['uuid', 'trace', 'type', 'name', 'status', 'failed', 'duration', 'user_id', 'server', 'started_at', 'counts', 'meta', 'job_uuid'])
            ->where('job_uuid', $jobUuid)
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (stdClass $row) => $this->castExecution($row));
    }

    /**
     * Split rows so no statement goes past the driver's placeholder limit.
     *
     * @template TRow of array<string, int|string|null>
     *
     * @param  list<TRow>  $rows
     * @return list<list<TRow>>
     */
    protected function chunk(Connection $connection, array $rows, int $columns): array
    {
        $placeholders = match ($connection->getDriverName()) {
            'sqlsrv' => 2_100,
            'sqlite' => 32_766,
            default => 65_535,
        };

        // SQL Server also caps a VALUES list at 1,000 rows.
        $size = min(1_000, intdiv($placeholders - 1, $columns));

        return array_chunk($rows, max(1, $size));
    }

    /**
     * Drop minute buckets older than a day and everything older than the
     * retention period.
     */
    public function trim(int $retentionDays, int $traceDays = 7): void
    {
        $now = $this->now();
        $connection = $this->connection();

        $connection->table(self::EXECUTIONS)
            ->where('started_at', '<', $now - min($traceDays, $retentionDays) * 86_400)
            ->delete();

        $connection->table(self::AGGREGATES)
            ->where('period', Period::MINUTE)
            ->where('bucket', '<', $now - Period::MINUTE_RETENTION - Period::MINUTE)
            ->delete();

        $connection->table(self::AGGREGATES)
            ->where('period', Period::HOUR)
            ->where('bucket', '<', $now - $retentionDays * 86_400)
            ->delete();

        $connection->table(self::VALUES)
            ->where('timestamp', '<', $now - $retentionDays * 86_400)
            ->delete();

        $connection->table(self::VALUES)
            ->where('type', Chats::TYPE)
            ->where('timestamp', '<', $now - Chats::DAYS * 86_400)
            ->delete();

        $connection->table(self::VALUES)
            ->whereIn('type', self::SHORT_LIVED_VALUES)
            ->where('timestamp', '<', $now - Period::MINUTE_RETENTION)
            ->delete();

        $this->store([], [['timestamp' => $now, 'type' => 'laralyze', 'key' => 'trimmed_at', 'value' => (string) $now]]);
    }

    public function lastTrimmedAt(): ?int
    {
        $value = $this->values('laralyze', ['trimmed_at'])->first()?->value;

        return $value === null ? null : (int) $value;
    }

    /**
     * When the oldest data still kept starts.
     */
    public function oldestBucket(): ?int
    {
        $bucket = $this->connection()->table(self::AGGREGATES)->where('period', Period::HOUR)->min('bucket');

        return $bucket === null ? null : (int) $bucket;
    }

    /**
     * Per-key totals over a window, e.g. every route with its count and p95.
     *
     * @param  list<string>  $aggregates  Any of count, sum, min, max, avg, and percentiles like p95.
     * @return Collection<int, stdClass>
     */
    public function aggregate(string $type, array $aggregates, int $window, ?string $orderBy = null, int $limit = 100): Collection
    {
        [$plain, $percentiles] = $this->parseAggregates($aggregates);

        // Keys come from the count rows when only percentiles were asked for.
        $selected = $plain === [] ? ['count'] : $plain;
        $grammar = $this->connection()->getQueryGrammar();

        $query = $this->window($type, $window)
            ->whereIn('aggregate', $this->storedAggregatesFor($selected))
            ->select('key_hash')
            ->selectRaw('max('.$grammar->wrap('key').') as '.$grammar->wrap('key'))
            ->groupBy('key_hash')
            ->limit($limit);

        foreach ($selected as $aggregate) {
            $query->selectRaw($this->aggregateSql($aggregate).' as '.$grammar->wrap($aggregate));
        }

        if ($orderBy !== null) {
            $query->orderByDesc($this->assertKnown($orderBy, $selected));
        }

        $rows = $query->get()->map(fn (stdClass $row) => $this->castRow($row, $plain));

        if ($percentiles !== []) {
            $bins = $this->binsByKey($type, $window, $rows->pluck('key_hash')->filter(fn ($hash) => is_string($hash))->values()->all());

            $rows->each(function (stdClass $row) use ($percentiles, $bins) {
                foreach ($percentiles as $name => $percentile) {
                    $row->{$name} = $this->capped(Histogram::percentile($bins[$row->key_hash] ?? [], $percentile), $row);
                }
            });
        }

        return $rows->map(function (stdClass $row) {
            unset($row->key_hash);

            return $row;
        })->values();
    }

    /**
     * One set of totals across every key of a type, or for one key.
     *
     * @param  list<string>  $aggregates
     */
    public function total(string $type, array $aggregates, int $window, ?string $key = null): stdClass
    {
        [$plain, $percentiles] = $this->parseAggregates($aggregates);
        $grammar = $this->connection()->getQueryGrammar();
        $scope = fn (Builder $query) => $key === null ? $query : $query->where('key_hash', hash('xxh128', $key));

        $total = new stdClass;

        if ($plain !== []) {
            $query = $scope($this->window($type, $window))->whereIn('aggregate', $this->storedAggregatesFor($plain));

            foreach ($plain as $aggregate) {
                $query->selectRaw($this->aggregateSql($aggregate).' as '.$grammar->wrap($aggregate));
            }

            $total = $this->castRow($query->first() ?? new stdClass, $plain);
        }

        if ($percentiles !== []) {
            $bins = $this->bins($scope($this->window($type, $window)))->pluck('value', 'aggregate');
            $counts = $this->binCounts($bins->all());

            foreach ($percentiles as $name => $percentile) {
                $total->{$name} = $this->capped(Histogram::percentile($counts, $percentile), $total);
            }
        }

        return $total;
    }

    /**
     * The key behind a hash, from any of the given types, e.g. to open the
     * page of one route. Null once its data is gone.
     *
     * @param  list<string>  $types
     */
    public function keyFor(array $types, string $hash): ?string
    {
        $key = $this->connection()->table(self::AGGREGATES)
            ->whereIn('type', $types)
            ->where('key_hash', $hash)
            ->value('key');

        return $key === null ? null : (string) $key;
    }

    /**
     * Points over time for one aggregate, oldest first. About 60 points,
     * never finer than the stored buckets. Slots without data are null.
     *
     * @return Collection<int, float|null>
     */
    public function graph(string $type, string $aggregate, int $window, ?string $key = null): Collection
    {
        [$plain, $percentiles] = $this->parseAggregates([$aggregate]);
        [$step, $first, $last] = $this->timeline($window);
        $slot = $this->slotSql($step);

        $query = $this->window($type, $window, $first)
            ->when($key !== null, fn ($query) => $query->where('key_hash', hash('xxh128', (string) $key)))
            ->selectRaw($slot.' as slot')
            ->groupByRaw($slot);

        if ($plain !== []) {
            $values = $query->whereIn('aggregate', $this->storedAggregatesFor($plain))
                ->selectRaw($this->aggregateSql($plain[0]).' as value')
                ->pluck('value', 'slot')
                ->map(fn ($value) => $value === null ? null : (float) $value);
        } else {
            $percentile = $percentiles[array_key_first($percentiles)];
            $maxima = (clone $query)->where('aggregate', 'max')->selectRaw($this->aggregateSql('max').' as value')->pluck('value', 'slot');

            $values = $this->bins($query->selectRaw('aggregate')->groupBy('aggregate'))
                ->groupBy('slot')
                ->map(fn (Collection $bins, int|string $slot) => $this->capped(
                    Histogram::percentile($this->binCounts($bins->pluck('value', 'aggregate')->all()), $percentile),
                    (object) ['max' => $maxima[$slot] ?? null],
                ));
        }

        return collect(range($first, $last, $step))
            ->mapWithKeys(fn (int $slot) => [$slot => $values[$slot] ?? null]);
    }

    /**
     * How many different keys had data in each slot, e.g. signed-in users
     * over time. Same slots as graph().
     *
     * @return Collection<int, float|null>
     */
    public function graphKeys(string $type, int $window): Collection
    {
        [$step, $first, $last] = $this->timeline($window);
        $slot = $this->slotSql($step);

        $values = $this->window($type, $window, $first)
            ->where('aggregate', 'count')
            ->selectRaw($slot.' as slot')
            ->selectRaw('count(distinct key_hash) as value')
            ->groupByRaw($slot)
            ->pluck('value', 'slot');

        return collect(range($first, $last, $step))
            ->mapWithKeys(fn (int $slot) => [$slot => isset($values[$slot]) ? (float) $values[$slot] : null]);
    }

    /**
     * How many different keys had data over the window.
     */
    public function countKeys(string $type, int $window): int
    {
        return $this->window($type, $window)->where('aggregate', 'count')->distinct()->count('key_hash');
    }

    /**
     * @param  list<string>|null  $keys
     * @return Collection<int, stdClass> Rows with key, value and timestamp.
     */
    public function values(string $type, ?array $keys = null): Collection
    {
        return $this->connection()->table(self::VALUES)
            ->where('type', $type)
            ->when($keys !== null, fn ($query) => $query->whereIn('key_hash', array_map(fn (string $key) => hash('xxh128', $key), (array) $keys)))
            ->orderBy('key')
            ->get(['key', 'value', 'timestamp'])
            ->map(function (stdClass $row) {
                $row->timestamp = (int) $row->timestamp;

                return $row;
            });
    }

    /**
     * Write one value straight away, e.g. a choice made on the dashboard.
     */
    public function put(string $type, string $key, string $value): void
    {
        $this->store([], [['timestamp' => $this->now(), 'type' => $type, 'key' => $key, 'value' => $value]]);
    }

    public function forget(string $type, string $key): void
    {
        $this->connection()->table(self::VALUES)->where('type', $type)->where('key_hash', hash('xxh128', $key))->delete();
    }

    /**
     * How many keys of a type were set within the last few seconds, e.g.
     * visitors seen in the last five minutes.
     */
    public function countValues(string $type, int $seconds): int
    {
        return $this->connection()->table(self::VALUES)
            ->where('type', $type)
            ->where('timestamp', '>=', $this->now() - $seconds)
            ->count();
    }

    protected function window(string $type, int $window, ?int $since = null): Builder
    {
        $period = Period::forWindow($window);

        return $this->connection()->table(self::AGGREGATES)
            ->where('period', $period)
            ->where('type', $type)
            ->where('bucket', '>=', Period::bucket($since ?? $this->now() - $window, $period));
    }

    /**
     * @return Collection<int, stdClass> Rows with aggregate (h12) and value.
     */
    protected function bins(Builder $query): Collection
    {
        return $query->where('aggregate', 'like', 'h%')
            ->selectRaw('sum('.$this->connection()->getQueryGrammar()->wrap('value').') as value')
            ->when($query->groups === null, fn ($query) => $query->addSelect('aggregate')->groupBy('aggregate'))
            ->get();
    }

    /**
     * @param  array<int, mixed>  $hashes
     * @return array<string, array<int, float>>
     */
    protected function binsByKey(string $type, int $window, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        return $this->bins($this->window($type, $window)->whereIn('key_hash', $hashes)->select('key_hash', 'aggregate')->groupBy('key_hash', 'aggregate'))
            ->groupBy('key_hash')
            ->map(fn (Collection $bins) => $this->binCounts($bins->pluck('value', 'aggregate')->all()))
            ->all();
    }

    protected function aggregateSql(string $aggregate): string
    {
        $grammar = $this->connection()->getQueryGrammar();
        $pick = fn (string $name) => 'case when '.$grammar->wrap('aggregate')." = '{$name}' then ".$grammar->wrap('value').' end';

        return match ($aggregate) {
            'count', 'sum' => "sum({$pick($aggregate)})",
            'min' => "min({$pick('min')})",
            'max' => "max({$pick('max')})",
            'avg' => "sum({$pick('sum')}) / nullif(sum({$pick('count')}), 0)",
            default => throw new InvalidArgumentException("Unknown aggregate [{$aggregate}]."),
        };
    }

    protected function slotSql(int $step): string
    {
        $bucket = $this->connection()->getQueryGrammar()->wrap('bucket');

        return "{$bucket} - ({$bucket} % {$step})";
    }

    /**
     * Concurrent flushes can deadlock on the same rows, and on SQL Server two
     * can race to insert the same new row. The database rolls the failed
     * statement back whole, so running it again never counts anything twice.
     *
     * @param  callable(): mixed  $statement
     */
    protected function retrying(callable $statement): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $statement();

                return;
            } catch (Throwable $e) {
                if ($attempt >= self::ATTEMPTS || ! Contention::causedBy($e)) {
                    throw $e;
                }

                Sleep::usleep(random_int(5_000, 50_000));
            }
        }
    }
}
