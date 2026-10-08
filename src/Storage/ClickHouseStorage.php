<?php

namespace MohammedMojaly\Laralyze\Storage;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use MohammedMojaly\Laralyze\Assistant\Chats;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Metrics\Histogram;
use MohammedMojaly\Laralyze\Metrics\Period;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Client;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Schema;
use MohammedMojaly\Laralyze\Storage\Concerns\ReadsMetrics;
use stdClass;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * Laralyze's data in ClickHouse. Writes are async inserts that the server
 * batches; metrics merge in the background; nothing is updated in place.
 */
class ClickHouseStorage implements Storage
{
    use ReadsMetrics;

    protected const EXECUTION_COLUMNS = 'uuid, trace, type, name, status, failed, duration, user_id, server, started_at, counts, meta, job_uuid';

    protected const LOG_COLUMNS = 'uuid, logged_at, level, message, context, exception, execution, type, name, user_id, server';

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

    public function store(array $rows, array $values, array $executions = [], array $logs = []): void
    {
        $logRows = array_map(fn (array $log) => $this->logRow($log), $logs);

        $failures = $this->client->tryInsertMany([
            DatabaseStorage::AGGREGATES => array_map(fn (array $row) => $this->aggregateRow($row), $rows),
            DatabaseStorage::VALUES => array_map(fn (array $value) => $this->valueRow($value['type'], $value['key'], $value['value'], $value['timestamp']), $values),
            DatabaseStorage::EXECUTIONS => array_map(fn (array $execution) => $this->executionRow($execution), $executions),
            Schema::LOGS => $logRows,
        ]);

        // Upgraded from a version without logs: create their table and try them again.
        if (isset($failures[Schema::LOGS]) && $this->missingTable($failures[Schema::LOGS])) {
            Schema::createLogs($this->client);
            unset($failures[Schema::LOGS]);
            $failures = [...$failures, ...$this->client->tryInsertMany([Schema::LOGS => $logRows])];
        }

        if ($failures !== []) {
            throw reset($failures);
        }
    }

    protected function missingTable(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'UNKNOWN_TABLE');
    }

    /**
     * Created by the first write that needs it.
     */
    public function logsInstalled(): bool
    {
        return true;
    }

    public function logs(array $filters, int $window, int $limit = 50, int $offset = 0): Collection
    {
        $where = ['logged_at >= {since:Int64}'];
        $params = ['since' => $this->now() - $window, 'limit' => $limit, 'offset' => $offset];

        if (($filters['levels'] ?? []) !== []) {
            $where[] = 'level IN {levels:Array(String)}';
            $params['levels'] = $filters['levels'];
        }

        if (trim($filters['search'] ?? '') !== '') {
            $where[] = 'positionCaseInsensitiveUTF8(message, {search:String}) > 0';
            $params['search'] = trim($filters['search']);
        }

        if (($filters['user'] ?? '') !== '') {
            $where[] = 'user_id = {user:String}';
            $params['user'] = $filters['user'];
        }

        return collect($this->selectLogs(
            'SELECT '.self::LOG_COLUMNS.' FROM laralyze_logs WHERE '.implode(' AND ', $where).' ORDER BY logged_at DESC, uuid DESC LIMIT {limit:UInt32} OFFSET {offset:UInt32}',
            $params,
        ))->map(fn (array $row) => $this->castLog($this->object($row)));
    }

    public function logUsers(int $window, int $limit = 100): array
    {
        $rows = $this->selectLogs(
            "SELECT user_id FROM laralyze_logs WHERE logged_at >= {since:Int64} AND user_id != '' GROUP BY user_id ORDER BY max(logged_at) DESC LIMIT {limit:UInt32}",
            ['since' => $this->now() - $window, 'limit' => $limit],
        );

        return array_map(fn (array $row) => (string) $row['user_id'], $rows);
    }

    /**
     * Nothing to read before the first entry creates the table.
     *
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    protected function selectLogs(string $sql, array $params): array
    {
        try {
            return $this->client->select($sql, $params);
        } catch (Throwable $e) {
            if ($this->missingTable($e)) {
                return [];
            }

            throw $e;
        }
    }

    public function keptExecutions(array $uuids): array
    {
        $uuids = array_values(array_filter($uuids, fn (string $uuid) => Ulid::isValid($uuid)));

        if ($uuids === []) {
            return [];
        }

        // ULIDs carry when they were made: look only around those days.
        $times = array_map(fn (string $uuid) => Ulid::fromString($uuid)->getDateTime()->getTimestamp(), $uuids);

        $rows = $this->client->select(
            'SELECT uuid FROM laralyze_executions WHERE uuid IN {uuids:Array(String)} AND started_at BETWEEN {from:Int64} AND {to:Int64}',
            ['uuids' => $uuids, 'from' => min($times) - 86_400, 'to' => max($times) + 86_400],
        );

        return array_map(fn (array $row) => (string) $row['uuid'], $rows);
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
        $now = $this->now();

        // Whole days past their cutoff go at once: no row-by-row deletes.
        $this->dropDaysBefore(DatabaseStorage::AGGREGATES, $now - Period::MINUTE_RETENTION, Period::MINUTE);
        $this->dropDaysBefore(DatabaseStorage::AGGREGATES, $now - $retentionDays * 86_400, Period::HOUR);
        $this->dropDaysBefore(DatabaseStorage::EXECUTIONS, $now - min($traceDays, $retentionDays) * 86_400);
        $this->dropDaysBefore(Schema::LOGS, $now - $retentionDays * 86_400);

        // Values are few: one lightweight delete costs less than partitioning them.
        $this->client->statement(
            'DELETE FROM laralyze_values WHERE timestamp < {retention:Int64} OR (type = {chats:String} AND timestamp < {chats_cutoff:Int64}) OR (type IN {short:Array(String)} AND timestamp < {short_cutoff:Int64})',
            [
                'retention' => $now - $retentionDays * 86_400,
                'chats' => Chats::TYPE,
                'chats_cutoff' => $now - Chats::DAYS * 86_400,
                'short' => DatabaseStorage::SHORT_LIVED_VALUES,
                'short_cutoff' => $now - Period::MINUTE_RETENTION,
            ],
        );

        $this->store([], [['timestamp' => $now, 'type' => 'laralyze', 'key' => 'trimmed_at', 'value' => (string) $now]]);
    }

    public function lastTrimmedAt(): ?int
    {
        // Checked now and then after a write: as quick as a write.
        $value = $this->client->select(
            'SELECT value FROM laralyze_values FINAL WHERE type = {type:String} AND key_hash = {hash:String} AND deleted = 0',
            ['type' => 'laralyze', 'hash' => hash('xxh128', 'trimmed_at')],
            $this->client->timeout(),
        )[0]['value'] ?? null;

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
     * Drop the day partitions that ended before the cutoff. Aggregates are
     * partitioned by (period, day), executions by day.
     */
    protected function dropDaysBefore(string $table, int $cutoff, ?int $period = null): void
    {
        $parts = $this->client->select(
            'SELECT DISTINCT partition_id, partition FROM system.parts WHERE database = {database:String} AND table = {table:String} AND active',
            ['database' => $this->client->database(), 'table' => $table],
        );

        foreach ($parts as $part) {
            preg_match_all('/\d+/', (string) $part['partition'], $numbers);
            $numbers = array_map('intval', $numbers[0]);
            [$partPeriod, $day] = count($numbers) === 2 ? $numbers : [null, $numbers[0] ?? PHP_INT_MAX];

            if ($partPeriod === $period && ($day + 1) * 86_400 <= $cutoff) {
                $this->client->statement("ALTER TABLE {$table} DROP PARTITION ID {id:String}", ['id' => (string) $part['partition_id']]);
            }
        }
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
            'avg' => "if(sumIf(total, aggregate = 'count') = 0 OR countIf(aggregate = 'sum') = 0, NULL, toFloat64(sumIf(total, aggregate = 'sum')) / toFloat64(sumIf(total, aggregate = 'count')))",
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
     * @param  array<string, mixed>  $log
     * @return array<string, mixed>
     */
    protected function logRow(array $log): array
    {
        return [
            'uuid' => (string) $log['uuid'],
            'logged_at' => (int) $log['logged_at'],
            'level' => (string) $log['level'],
            'message' => (string) $log['message'],
            // No NULL columns: empty means none, and reads turn it back into null.
            'context' => (string) ($log['context'] ?? ''),
            'exception' => (string) ($log['exception'] ?? ''),
            'execution' => (string) ($log['execution'] ?? ''),
            'type' => (string) ($log['type'] ?? ''),
            'name' => (string) ($log['name'] ?? ''),
            'user_id' => (string) ($log['user_id'] ?? ''),
            'server' => (string) $log['server'],
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
