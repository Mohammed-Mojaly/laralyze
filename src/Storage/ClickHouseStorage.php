<?php

namespace MohammedMojaly\Laralyze\Storage;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Metrics\Histogram;
use MohammedMojaly\Laralyze\Metrics\Period;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Client;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Schema;
use MohammedMojaly\Laralyze\Storage\Concerns\ReadsMetrics;
use stdClass;
use Symfony\Component\Uid\Ulid;

/**
 * Laralyze's data in ClickHouse. Writes are async inserts that the server
 * batches; metrics merge in the background; nothing is updated in place.
 */
class ClickHouseStorage implements Storage
{
    use ReadsMetrics;

    protected const EXECUTION_COLUMNS = 'uuid, trace, type, name, status, failed, duration, user_id, server, started_at, counts, meta, job_uuid';

    public function __construct(protected Client $client) {}

    public function client(): Client
    {
        return $this->client;
    }

    /**
     * Create the tables. Returns the server's version.
     */
    public function install(): string
    {
        Schema::create($this->client);

        return Schema::version($this->client);
    }

    public function installed(): bool
    {
        return Schema::exists($this->client);
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function store(array $rows, array $values, array $executions = []): void
    {
        $this->client->insertMany([
            DatabaseStorage::AGGREGATES => array_map(fn (array $row) => $this->aggregateRow($row), $rows),
            DatabaseStorage::VALUES => array_map(fn (array $value) => $this->valueRow($value['type'], $value['key'], $value['value'], $value['timestamp']), $values),
            DatabaseStorage::EXECUTIONS => array_map(fn (array $execution) => $this->executionRow($execution), $executions),
        ]);
    }

    public function executions(array $filters, int $window, string $order = 'recent', int $limit = 50, int $offset = 0): Collection
    {
        $where = ['started_at >= {since:Int64}'];
        $params = ['since' => $this->now() - $window, 'limit' => $limit, 'offset' => $offset];

        $filterable = [
            'type' => ['type = {type:String}', fn ($value) => (string) $value],
            'name' => ['name_hash = {name:String}', fn ($value) => hash('xxh128', (string) $value)],
            'user' => ['user_id = {user:String}', fn ($value) => (string) $value],
            'exception' => ['has(exceptions, {exception:String})', fn ($value) => (string) $value],
            'slower' => ['duration >= {slower:Float64}', fn ($value) => (float) $value],
        ];

        foreach ($filterable as $filter => [$condition, $cast]) {
            if (! empty($filters[$filter])) {
                $where[] = $condition;
                $params[$filter] = $cast($filters[$filter]);
            }
        }

        if (isset($filters['failed'])) {
            $where[] = 'failed = {failed:UInt8}';
            $params['failed'] = $filters['failed'] ? 1 : 0;
        }

        $by = $order === 'slowest' ? 'duration' : 'started_at';

        return $this->executionRows(
            'SELECT '.self::EXECUTION_COLUMNS.' FROM laralyze_executions WHERE '.implode(' AND ', $where)." ORDER BY {$by} DESC, uuid DESC LIMIT {limit:UInt32} OFFSET {offset:UInt32}",
            $params,
        );
    }

    public function execution(string $uuid): ?stdClass
    {
        if (! Ulid::isValid($uuid)) {
            return null;
        }

        // A ULID carries when it was made: look only around that day.
        $at = Ulid::fromString($uuid)->getDateTime()->getTimestamp();

        return $this->executionRows(
            'SELECT '.self::EXECUTION_COLUMNS.', events FROM laralyze_executions WHERE uuid = {uuid:String} AND started_at BETWEEN {from:Int64} AND {to:Int64} LIMIT 1',
            ['uuid' => $uuid, 'from' => $at - 86_400, 'to' => $at + 86_400],
        )->first();
    }

    public function related(string $trace, string $except): Collection
    {
        return $this->executionRows(
            'SELECT '.self::EXECUTION_COLUMNS.' FROM laralyze_executions WHERE trace = {trace:String} AND uuid != {except:String} ORDER BY started_at, uuid LIMIT 100',
            ['trace' => $trace, 'except' => $except],
        );
    }

    public function attempts(string $jobUuid): Collection
    {
        return $this->executionRows(
            'SELECT '.self::EXECUTION_COLUMNS.' FROM laralyze_executions WHERE job_uuid = {job:String} ORDER BY started_at, uuid LIMIT 50',
            ['job' => $jobUuid],
        );
    }

    public function trim(int $retentionDays, int $traceDays = 7): void
    {
        throw new LogicException('Not implemented yet.');
    }

    public function lastTrimmedAt(): ?int
    {
        $value = $this->values('laralyze', ['trimmed_at'])->first()?->value;

        return $value === null ? null : (int) $value;
    }

    public function oldestBucket(): ?int
    {
        $bucket = $this->client->select(
            'SELECT minOrNull(bucket) AS bucket FROM laralyze_aggregates WHERE period = {period:UInt32}',
            ['period' => Period::HOUR],
        )[0]['bucket'] ?? null;

        return $bucket === null ? null : (int) $bucket;
    }

    public function aggregate(string $type, array $aggregates, int $window, ?string $orderBy = null, int $limit = 100): Collection
    {
        [$plain, $percentiles] = $this->parseAggregates($aggregates);

        // Keys come from the count rows when only percentiles were asked for.
        $selected = $plain === [] ? ['count'] : $plain;
        $order = $orderBy === null ? '' : ' ORDER BY '.$this->assertKnown($orderBy, $selected).' DESC';
        [$where, $params] = $this->window($type, $window);

        $rows = collect($this->client->select(
            "SELECT key_hash, any(key) AS key, {$this->columns($selected)} FROM laralyze_aggregates WHERE {$where} AND aggregate IN {stored:Array(String)} GROUP BY key_hash{$order} LIMIT {limit:UInt32}",
            [...$params, 'stored' => $this->storedAggregatesFor($selected), 'limit' => $limit],
        ))->map(fn (array $row) => $this->castRow($this->object($row), $plain));

        if ($percentiles !== []) {
            $bins = $this->binsByKey($type, $window, $rows->pluck('key_hash')->map(fn ($hash) => (string) $hash)->all());

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

    public function total(string $type, array $aggregates, int $window, ?string $key = null): stdClass
    {
        [$plain, $percentiles] = $this->parseAggregates($aggregates);
        [$where, $params] = $this->window($type, $window);

        if ($key !== null) {
            $where .= ' AND key_hash = {key_hash:String}';
            $params['key_hash'] = hash('xxh128', $key);
        }

        $total = new stdClass;

        if ($plain !== []) {
            $row = $this->client->select(
                "SELECT {$this->columns($plain)} FROM laralyze_aggregates WHERE {$where} AND aggregate IN {stored:Array(String)}",
                [...$params, 'stored' => $this->storedAggregatesFor($plain)],
            )[0] ?? [];

            $total = $this->castRow($this->object($row), $plain);
        }

        if ($percentiles !== []) {
            $bins = $this->client->select(
                "SELECT aggregate, toFloat64(sum(total)) AS value FROM laralyze_aggregates WHERE {$where} AND startsWith(aggregate, 'h') GROUP BY aggregate",
                $params,
            );
            $counts = $this->binCounts(array_column($bins, 'value', 'aggregate'));

            foreach ($percentiles as $name => $percentile) {
                $total->{$name} = $this->capped(Histogram::percentile($counts, $percentile), $total);
            }
        }

        return $total;
    }

    public function keyFor(array $types, string $hash): ?string
    {
        $row = $this->client->select(
            'SELECT key FROM laralyze_aggregates WHERE type IN {types:Array(String)} AND key_hash = {hash:String} LIMIT 1',
            ['types' => $types, 'hash' => $hash],
        )[0] ?? null;

        return $row === null ? null : (string) $row['key'];
    }

    public function graph(string $type, string $aggregate, int $window, ?string $key = null): Collection
    {
        [$plain, $percentiles] = $this->parseAggregates([$aggregate]);
        [$step, $first, $last] = $this->timeline($window);
        [$where, $params] = $this->window($type, $window, $first);
        $params['step'] = $step;

        if ($key !== null) {
            $where .= ' AND key_hash = {key_hash:String}';
            $params['key_hash'] = hash('xxh128', $key);
        }

        $slot = 'bucket - (bucket % {step:Int64})';

        if ($plain !== []) {
            $values = collect($this->client->select(
                "SELECT {$slot} AS slot, {$this->aggregateSql($plain[0])} AS value FROM laralyze_aggregates WHERE {$where} AND aggregate IN {stored:Array(String)} GROUP BY slot",
                [...$params, 'stored' => $this->storedAggregatesFor($plain)],
            ))->mapWithKeys(fn (array $row) => [(int) $row['slot'] => $row['value'] === null ? null : (float) $row['value']]);
        } else {
            $percentile = $percentiles[array_key_first($percentiles)];
            $maxima = collect($this->client->select(
                "SELECT {$slot} AS slot, {$this->aggregateSql('max')} AS value FROM laralyze_aggregates WHERE {$where} AND aggregate = 'max' GROUP BY slot",
                $params,
            ))->mapWithKeys(fn (array $row) => [(int) $row['slot'] => $row['value']]);

            $values = collect($this->client->select(
                "SELECT {$slot} AS slot, aggregate, toFloat64(sum(total)) AS value FROM laralyze_aggregates WHERE {$where} AND startsWith(aggregate, 'h') GROUP BY slot, aggregate",
                $params,
            ))->groupBy('slot')->map(fn (Collection $bins, int|string $slot) => $this->capped(
                Histogram::percentile($this->binCounts($bins->pluck('value', 'aggregate')->all()), $percentile),
                $this->object(['max' => $maxima[(int) $slot] ?? null]),
            ));
        }

        return collect(range($first, $last, $step))
            ->mapWithKeys(fn (int $slot) => [$slot => $values[$slot] ?? null]);
    }

    public function graphKeys(string $type, int $window): Collection
    {
        [$step, $first, $last] = $this->timeline($window);
        [$where, $params] = $this->window($type, $window, $first);

        $values = collect($this->client->select(
            "SELECT bucket - (bucket % {step:Int64}) AS slot, uniqExact(key_hash) AS value FROM laralyze_aggregates WHERE {$where} AND aggregate = 'count' GROUP BY slot",
            [...$params, 'step' => $step],
        ))->mapWithKeys(fn (array $row) => [(int) $row['slot'] => (float) $row['value']]);

        return collect(range($first, $last, $step))
            ->mapWithKeys(fn (int $slot) => [$slot => $values[$slot] ?? null]);
    }

    public function countKeys(string $type, int $window): int
    {
        [$where, $params] = $this->window($type, $window);

        return (int) ($this->client->select(
            "SELECT uniqExact(key_hash) AS found FROM laralyze_aggregates WHERE {$where} AND aggregate = 'count'",
            $params,
        )[0]['found'] ?? 0);
    }

    public function values(string $type, ?array $keys = null): Collection
    {
        $sql = 'SELECT key, value, timestamp FROM laralyze_values FINAL WHERE type = {type:String} AND deleted = 0';
        $params = ['type' => $type];

        if ($keys !== null) {
            $sql .= ' AND key_hash IN {hashes:Array(String)}';
            $params['hashes'] = array_map(fn (string $key) => hash('xxh128', $key), $keys);
        }

        return collect($this->client->select($sql.' ORDER BY key', $params))->map(function (array $row): stdClass {
            $value = new stdClass;
            $value->key = (string) $row['key'];
            $value->value = (string) $row['value'];
            $value->timestamp = (int) $row['timestamp'];

            return $value;
        });
    }

    public function put(string $type, string $key, string $value): void
    {
        // Dashboard actions: written at once so the next render shows them.
        $this->client->insert(DatabaseStorage::VALUES, [$this->valueRow($type, $key, $value, $this->now())], async: false);
    }

    public function forget(string $type, string $key): void
    {
        // A tombstone: reads with FINAL drop the key, and later puts bring it back.
        $this->client->insert(DatabaseStorage::VALUES, [[...$this->valueRow($type, $key, '', $this->now()), 'deleted' => 1]], async: false);
    }

    public function countValues(string $type, int $seconds): int
    {
        $found = $this->client->select(
            'SELECT count() AS found FROM laralyze_values FINAL WHERE type = {type:String} AND deleted = 0 AND timestamp >= {since:Int64}',
            ['type' => $type, 'since' => $this->now() - $seconds],
        );

        return (int) ($found[0]['found'] ?? 0);
    }

    /**
     * The rows of one type within a window, as a WHERE clause and its parameters.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function window(string $type, int $window, ?int $since = null): array
    {
        $period = Period::forWindow($window);

        return [
            'period = {period:UInt32} AND type = {type:String} AND bucket >= {since:Int64}',
            ['period' => $period, 'type' => $type, 'since' => Period::bucket($since ?? $this->now() - $window, $period)],
        ];
    }

    /**
     * @param  list<string>  $aggregates  Known names only (see parseAggregates()).
     */
    protected function columns(array $aggregates): string
    {
        return implode(', ', array_map(fn (string $aggregate) => "{$this->aggregateSql($aggregate)} AS {$aggregate}", $aggregates));
    }

    /**
     * Like SQL: NULL when no row holds that aggregate, so a missing max never reads as 0.
     */
    protected function aggregateSql(string $aggregate): string
    {
        $present = fn (string $name, string $expression) => "if(countIf(aggregate = '{$name}') = 0, NULL, toFloat64({$expression}))";

        return match ($aggregate) {
            'count', 'sum' => $present($aggregate, "sumIf(total, aggregate = '{$aggregate}')"),
            'min' => $present('min', "minIf(lowest, aggregate = 'min')"),
            'max' => $present('max', "maxIf(highest, aggregate = 'max')"),
            'avg' => "if(sumIf(total, aggregate = 'count') = 0, NULL, toFloat64(sumIf(total, aggregate = 'sum')) / toFloat64(sumIf(total, aggregate = 'count')))",
            default => throw new InvalidArgumentException("Unknown aggregate [{$aggregate}]."),
        };
    }

    /**
     * @param  array<int, string>  $hashes
     * @return array<string, array<int, float>>
     */
    protected function binsByKey(string $type, int $window, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        [$where, $params] = $this->window($type, $window);

        return collect($this->client->select(
            "SELECT key_hash, aggregate, toFloat64(sum(total)) AS value FROM laralyze_aggregates WHERE {$where} AND key_hash IN {hashes:Array(String)} AND startsWith(aggregate, 'h') GROUP BY key_hash, aggregate",
            [...$params, 'hashes' => $hashes],
        ))->groupBy('key_hash')->map(fn (Collection $bins) => $this->binCounts($bins->pluck('value', 'aggregate')->all()))->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function object(array $row): stdClass
    {
        $object = new stdClass;

        foreach ($row as $name => $value) {
            $object->{$name} = $value;
        }

        return $object;
    }

    /**
     * @param  array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}  $row
     * @return array<string, int|float|string>
     */
    protected function aggregateRow(array $row): array
    {
        $value = round($row['value'], 4);

        return [
            'bucket' => $row['bucket'],
            'period' => $row['period'],
            'type' => $row['type'],
            'aggregate' => $row['aggregate'],
            'key_hash' => hash('xxh128', $row['key']),
            'key' => $row['key'],
            // One value, three merge rules: sums add up, min and max keep the extremes.
            'total' => $value,
            'lowest' => $value,
            'highest' => $value,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    protected function valueRow(string $type, string $key, string $value, int $timestamp): array
    {
        return [
            'type' => $type,
            'key_hash' => hash('xxh128', $key),
            'key' => $key,
            'value' => $value,
            'timestamp' => $timestamp,
            // The newest write wins, also between two in the same second.
            'version' => (int) (microtime(true) * 1_000_000),
            'deleted' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed>
     */
    protected function executionRow(array $execution): array
    {
        return [
            'uuid' => (string) $execution['uuid'],
            'trace' => (string) $execution['trace'],
            'type' => (string) $execution['type'],
            'name' => (string) $execution['name'],
            'name_hash' => hash('xxh128', (string) $execution['name']),
            'status' => (string) $execution['status'],
            'failed' => $execution['failed'] ? 1 : 0,
            'duration' => round((float) $execution['duration'], 2),
            // No NULL columns: empty means none, and reads turn it back into null.
            'user_id' => $execution['user_id'] === null ? '' : (string) $execution['user_id'],
            'server' => (string) $execution['server'],
            'started_at' => (int) $execution['started_at'],
            'exceptions' => array_values(array_map(fn ($hash) => (string) $hash, (array) $execution['exceptions'])),
            'counts' => (string) json_encode($execution['counts']),
            'meta' => (string) json_encode($execution['meta'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE),
            'job_uuid' => isset($execution['job_uuid']) ? (string) $execution['job_uuid'] : '',
            'events' => (string) json_encode($execution['events'], JSON_INVALID_UTF8_SUBSTITUTE),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, stdClass>
     */
    protected function executionRows(string $sql, array $params): Collection
    {
        return collect($this->client->select($sql, $params))->map(fn (array $row) => $this->castExecution($this->object($row)));
    }
}
