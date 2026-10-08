<?php

namespace MohammedMojaly\Laralyze\Storage\ClickHouse;

/**
 * Laralyze's tables in ClickHouse. Safe to run again: later releases add
 * their changes here as IF NOT EXISTS statements.
 */
class Schema
{
    public const TABLES = ['laralyze_aggregates', 'laralyze_values', 'laralyze_executions'];

    /**
     * Added later: created on the first write that needs it, so an upgrade needs no step.
     */
    public const LOGS = 'laralyze_logs';

    /**
     * The oldest ClickHouse Laralyze is tested with (an LTS release).
     */
    public const MINIMUM_VERSION = '24.8';

    public static function create(Client $client): void
    {
        // Metrics merge in the background: sums add up, min and max keep the
        // extremes. Reads still aggregate, so unmerged rows count correctly.
        $client->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS laralyze_aggregates (
                bucket Int64,
                period UInt32,
                type LowCardinality(String),
                aggregate LowCardinality(String),
                key_hash String,
                key SimpleAggregateFunction(any, String),
                total SimpleAggregateFunction(sum, Decimal128(4)),
                lowest SimpleAggregateFunction(min, Decimal128(4)),
                highest SimpleAggregateFunction(max, Decimal128(4)),
                INDEX key_hash_idx key_hash TYPE bloom_filter GRANULARITY 4
            ) ENGINE = AggregatingMergeTree
            PARTITION BY (period, intDiv(bucket, 86400))
            ORDER BY (period, type, bucket, aggregate, key_hash)
            SQL);

        // The newest version of a key wins; forgetting writes a tombstone.
        $client->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS laralyze_values (
                type LowCardinality(String),
                key_hash String,
                key String,
                value String CODEC(ZSTD(3)),
                timestamp Int64,
                version UInt64,
                deleted UInt8
            ) ENGINE = ReplacingMergeTree(version, deleted)
            ORDER BY (type, key_hash)
            SQL);

        // Single requests, jobs and commands. Empty strings stand for "none":
        // ClickHouse works best without NULL columns.
        $client->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS laralyze_executions (
                uuid String,
                trace String,
                type LowCardinality(String),
                name String,
                name_hash String,
                status LowCardinality(String),
                failed UInt8,
                duration Float64,
                user_id String,
                server LowCardinality(String),
                started_at Int64,
                exceptions Array(String),
                counts String,
                meta String CODEC(ZSTD(3)),
                job_uuid String,
                events String CODEC(ZSTD(3)),
                INDEX uuid_idx uuid TYPE bloom_filter GRANULARITY 4,
                INDEX trace_idx trace TYPE bloom_filter GRANULARITY 4,
                INDEX job_idx job_uuid TYPE bloom_filter GRANULARITY 4,
                INDEX user_idx user_id TYPE bloom_filter GRANULARITY 4,
                INDEX exceptions_idx exceptions TYPE bloom_filter GRANULARITY 4
            ) ENGINE = MergeTree
            PARTITION BY intDiv(started_at, 86400)
            ORDER BY (type, name_hash, started_at)
            SQL);

        self::createLogs($client);
    }

    /**
     * Log entries, read newest first; old days are dropped whole.
     */
    public static function createLogs(Client $client): void
    {
        $client->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS laralyze_logs (
                uuid String,
                logged_at Int64,
                level LowCardinality(String),
                message String CODEC(ZSTD(3)),
                context String CODEC(ZSTD(3)),
                exception String,
                execution String,
                type LowCardinality(String),
                name String,
                user_id String,
                server LowCardinality(String),
                INDEX user_idx user_id TYPE bloom_filter GRANULARITY 4
            ) ENGINE = MergeTree
            PARTITION BY intDiv(logged_at, 86400)
            ORDER BY (logged_at, uuid)
            SQL);
    }

    public static function exists(Client $client): bool
    {
        // Asked on the write path after a failure: as quick as a write.
        $found = $client->select(
            'SELECT count() AS found FROM system.tables WHERE database = {database:String} AND name IN {tables:Array(String)}',
            ['database' => $client->database(), 'tables' => self::TABLES],
            $client->timeout(),
        );

        return (int) ($found[0]['found'] ?? 0) === count(self::TABLES);
    }

    public static function version(Client $client): string
    {
        return (string) ($client->select('SELECT version() AS version')[0]['version'] ?? '0');
    }
}
