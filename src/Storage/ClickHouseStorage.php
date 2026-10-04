<?php

namespace MohammedMojaly\Laralyze\Storage;

use Illuminate\Support\Collection;
use LogicException;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Client;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Schema;
use MohammedMojaly\Laralyze\Storage\Concerns\ReadsMetrics;
use stdClass;

/**
 * Laralyze's data in ClickHouse. Writes are async inserts that the server
 * batches; metrics merge in the background; nothing is updated in place.
 */
class ClickHouseStorage implements Storage
{
    use ReadsMetrics;

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
        throw new LogicException('Not implemented yet.');
    }

    public function execution(string $uuid): ?stdClass
    {
        throw new LogicException('Not implemented yet.');
    }

    public function related(string $trace, string $except): Collection
    {
        throw new LogicException('Not implemented yet.');
    }

    public function attempts(string $jobUuid): Collection
    {
        throw new LogicException('Not implemented yet.');
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
        throw new LogicException('Not implemented yet.');
    }

    public function aggregate(string $type, array $aggregates, int $window, ?string $orderBy = null, int $limit = 100): Collection
    {
        throw new LogicException('Not implemented yet.');
    }

    public function total(string $type, array $aggregates, int $window, ?string $key = null): stdClass
    {
        throw new LogicException('Not implemented yet.');
    }

    public function keyFor(array $types, string $hash): ?string
    {
        throw new LogicException('Not implemented yet.');
    }

    public function graph(string $type, string $aggregate, int $window, ?string $key = null): Collection
    {
        throw new LogicException('Not implemented yet.');
    }

    public function graphKeys(string $type, int $window): Collection
    {
        throw new LogicException('Not implemented yet.');
    }

    public function countKeys(string $type, int $window): int
    {
        throw new LogicException('Not implemented yet.');
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
        throw new LogicException('Not implemented yet.');
    }
}
