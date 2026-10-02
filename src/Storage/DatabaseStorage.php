<?php

namespace MohammedMojaly\Laralyze\Storage;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use MohammedMojaly\Laralyze\Metrics\Histogram;
use MohammedMojaly\Laralyze\Metrics\Period;
use stdClass;

class DatabaseStorage
{
    public const AGGREGATES = 'laralyze_aggregates';

    public const VALUES = 'laralyze_values';

    protected const UNIQUE_AGGREGATE = ['bucket', 'period', 'type', 'aggregate', 'key_hash'];

    protected const READABLE = ['count', 'sum', 'min', 'max', 'avg'];

    /**
     * Values only needed for a day, like when each visitor was last seen.
     */
    protected const SHORT_LIVED_VALUES = ['visitor_seen'];

    public function __construct(protected DatabaseManager $db, protected Repository $config) {}

    public function connection(): Connection
    {
        return $this->db->connection($this->config->get('laralyze.storage.connection'));
    }

    public function inTransaction(): bool
    {
        return $this->connection()->transactionLevel() > 0;
    }

    /**
     * @param  list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>  $rows
     * @param  list<array{timestamp: int, type: string, key: string, value: string}>  $values
     */
    public function store(array $rows, array $values): void
    {
        if ($rows === [] && $values === []) {
            return;
        }

        $connection = $this->connection();
        $merge = new MergeExpressions($connection, self::AGGREGATES);

        $this->retryingUniqueRaces(fn () => $connection->transaction(function () use ($connection, $merge, $rows, $values) {
            foreach ($this->groupByMerge($rows) as $kind => $group) {
                foreach ($this->chunk($connection, $this->prepareAggregates($group), 7) as $chunk) {
                    $connection->table(self::AGGREGATES)->upsert($chunk, self::UNIQUE_AGGREGATE, [
                        'value' => $merge->{$kind}('value'),
                    ]);
                }
            }

            foreach ($this->chunk($connection, $this->prepareValues($values), 5) as $chunk) {
                $connection->table(self::VALUES)->upsert($chunk, ['type', 'key_hash'], ['timestamp', 'value']);
            }
        }, attempts: 3));
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
        return array_map(fn (array $value) => [
            'timestamp' => $value['timestamp'],
            'type' => $value['type'],
            'key' => $value['key'],
            'key_hash' => hash('xxh128', $value['key']),
            'value' => $value['value'],
        ], $values);
    }

    /**
     * Split rows so no statement goes past the driver's placeholder limit.
     *
     * @param  list<array<string, int|string>>  $rows
     * @return list<list<array<string, int|string>>>
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
    public function trim(int $retentionDays): void
    {
        $now = $this->now();
        $connection = $this->connection();

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
     * About 60 slots over the window, never finer than the stored buckets.
     *
     * @return array{0: int, 1: int, 2: int} [step, first slot, last slot]
     */
    protected function timeline(int $window): array
    {
        $period = Period::forWindow($window);
        $step = (int) (ceil($window / 60 / $period) * $period);
        $now = $this->now();

        return [$step, $now - $window - (($now - $window) % $step), $now - ($now % $step)];
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

    /**
     * @param  array<string, mixed>  $bins  Aggregate name (h12) => count.
     * @return array<int, float>
     */
    protected function binCounts(array $bins): array
    {
        $counts = [];

        foreach ($bins as $aggregate => $count) {
            $counts[(int) substr((string) $aggregate, 1)] = (float) $count;
        }

        return $counts;
    }

    /**
     * A percentile is read off its histogram bin, which can overshoot the
     * slowest value actually seen.
     */
    protected function capped(?float $percentile, stdClass $row): ?float
    {
        $max = $row->max ?? null;

        return $percentile === null || $max === null ? $percentile : min($percentile, (float) $max);
    }

    /**
     * Split requested names into plain aggregates and percentiles (p95 => 0.95).
     *
     * @param  list<string>  $aggregates
     * @return array{0: list<string>, 1: array<string, float>}
     */
    protected function parseAggregates(array $aggregates): array
    {
        $plain = [];
        $percentiles = [];

        foreach ($aggregates as $aggregate) {
            if (preg_match('/^p(\d{1,2})$/', $aggregate, $matches)) {
                $percentiles[$aggregate] = ((int) $matches[1]) / 100;
            } else {
                $plain[] = $this->assertKnown($aggregate, self::READABLE);
            }
        }

        return [$plain, $percentiles];
    }

    /**
     * @param  list<string>  $allowed
     */
    protected function assertKnown(string $aggregate, array $allowed): string
    {
        if (! in_array($aggregate, $allowed, true)) {
            throw new InvalidArgumentException("Unknown aggregate [{$aggregate}].");
        }

        return $aggregate;
    }

    /**
     * @param  list<string>  $aggregates
     * @return list<string>
     */
    protected function storedAggregatesFor(array $aggregates): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (string $aggregate) => $aggregate === 'avg' ? ['sum', 'count'] : [$aggregate],
            $aggregates,
        ))));
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
     * @param  list<string>  $aggregates
     */
    protected function castRow(stdClass $row, array $aggregates): stdClass
    {
        foreach ($aggregates as $aggregate) {
            $value = $row->{$aggregate} ?? null;

            $row->{$aggregate} = $value === null ? null : (float) $value;
        }

        return $row;
    }

    protected function now(): int
    {
        return Date::now()->getTimestamp();
    }

    /**
     * Two flushes can race to insert the same new row. On SQL Server that
     * surfaces as a unique violation instead of an update, so try again.
     *
     * @param  callable(): void  $callback
     */
    protected function retryingUniqueRaces(callable $callback): void
    {
        try {
            $callback();
        } catch (UniqueConstraintViolationException) {
            $callback();
        }
    }
}
