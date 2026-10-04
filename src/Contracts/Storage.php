<?php

namespace MohammedMojaly\Laralyze\Contracts;

use Illuminate\Support\Collection;
use stdClass;

/**
 * Where Laralyze keeps its data: a database connection, or ClickHouse.
 */
interface Storage
{
    /**
     * Whether Laralyze's tables exist.
     */
    public function installed(): bool;

    /**
     * Whether writing now would land inside a transaction the app still has open.
     */
    public function inTransaction(): bool;

    /**
     * @param  list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>  $rows
     * @param  list<array{timestamp: int, type: string, key: string, value: string}>  $values
     * @param  list<array<string, mixed>>  $executions
     */
    public function store(array $rows, array $values, array $executions = []): void;

    /**
     * Single requests, jobs or commands, newest or slowest first.
     *
     * @param  array{type?: string, name?: string, user?: string, exception?: string, failed?: bool, slower?: float}  $filters
     * @return Collection<int, stdClass>
     */
    public function executions(array $filters, int $window, string $order = 'recent', int $limit = 50, int $offset = 0): Collection;

    /**
     * One execution with its events, or null when it's gone.
     */
    public function execution(string $uuid): ?stdClass;

    /**
     * Everything else in the same trace.
     *
     * @return Collection<int, stdClass>
     */
    public function related(string $trace, string $except): Collection;

    /**
     * Every attempt of one queued job, first to last.
     *
     * @return Collection<int, stdClass>
     */
    public function attempts(string $jobUuid): Collection;

    /**
     * Remove data past the retention periods.
     */
    public function trim(int $retentionDays, int $traceDays = 7): void;

    public function lastTrimmedAt(): ?int;

    /**
     * When the oldest data still kept starts.
     */
    public function oldestBucket(): ?int;

    /**
     * Per-key totals over a window.
     *
     * @param  list<string>  $aggregates  Any of count, sum, min, max, avg, and percentiles like p95.
     * @return Collection<int, stdClass>
     */
    public function aggregate(string $type, array $aggregates, int $window, ?string $orderBy = null, int $limit = 100): Collection;

    /**
     * One set of totals across every key of a type, or for one key.
     *
     * @param  list<string>  $aggregates
     */
    public function total(string $type, array $aggregates, int $window, ?string $key = null): stdClass;

    /**
     * The key behind a hash, from any of the given types.
     *
     * @param  list<string>  $types
     */
    public function keyFor(array $types, string $hash): ?string;

    /**
     * Points over time for one aggregate, oldest first.
     *
     * @return Collection<int, float|null>
     */
    public function graph(string $type, string $aggregate, int $window, ?string $key = null): Collection;

    /**
     * How many different keys had data in each slot.
     *
     * @return Collection<int, float|null>
     */
    public function graphKeys(string $type, int $window): Collection;

    /**
     * How many different keys had data over the window.
     */
    public function countKeys(string $type, int $window): int;

    /**
     * @param  list<string>|null  $keys
     * @return Collection<int, stdClass> Rows with key, value and timestamp.
     */
    public function values(string $type, ?array $keys = null): Collection;

    /**
     * Write one value straight away, e.g. a choice made on the dashboard.
     */
    public function put(string $type, string $key, string $value): void;

    public function forget(string $type, string $key): void;

    /**
     * How many keys of a type were set within the last few seconds.
     */
    public function countValues(string $type, int $seconds): int;
}
