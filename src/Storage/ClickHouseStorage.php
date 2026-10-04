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
        throw new LogicException('Not implemented yet.');
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
        throw new LogicException('Not implemented yet.');
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
        throw new LogicException('Not implemented yet.');
    }

    public function put(string $type, string $key, string $value): void
    {
        throw new LogicException('Not implemented yet.');
    }

    public function forget(string $type, string $key): void
    {
        throw new LogicException('Not implemented yet.');
    }

    public function countValues(string $type, int $seconds): int
    {
        throw new LogicException('Not implemented yet.');
    }
}
