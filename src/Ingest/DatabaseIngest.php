<?php

namespace MohammedMojaly\Laralyze\Ingest;

use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Date;
use MohammedMojaly\Laralyze\Contracts\Ingest;
use MohammedMojaly\Laralyze\Recorders\Servers;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;
use Throwable;

/**
 * Each flush adds one row to laralyze_ingest, a plain insert that never
 * waits on a lock. Once a minute the digest, the only writer, merges
 * those rows into Laralyze's tables, so concurrent flushes can't deadlock.
 */
class DatabaseIngest implements Ingest
{
    public const TABLE = 'laralyze_ingest';

    public const DIGESTED_AT_CACHE_KEY = 'laralyze:digested_at';

    /**
     * Rows merged per write. Each holds one flush, a few kilobytes.
     */
    protected const CHUNK = 250;

    public function __construct(protected DatabaseStorage $storage, protected Cache $cache, protected Repository $config) {}

    public function connection(): Connection
    {
        return $this->storage->connection();
    }

    public function write(array $rows, array $values, array $executions): void
    {
        if ($rows === [] && $values === [] && $executions === []) {
            return;
        }

        try {
            $this->connection()->table(self::TABLE)->insert([
                'created_at' => Date::now()->getTimestamp(),
                'server' => mb_substr((string) ($this->config->get('laralyze.recorders.'.Servers::class.'.server_name') ?? gethostname()), 0, 128),
                'payload' => Batch::encode($rows, $values, $executions),
            ]);
        } catch (Throwable $e) {
            // Upgraded without `laralyze:install`: write straight in until the table exists.
            if ($this->installed()) {
                throw $e;
            }

            $this->storage->store($rows, $values, $executions);
        }
    }

    public function digest(int $seconds = 50): int
    {
        $store = $this->cache->store();
        // APCu can't lock; the scheduler's withoutOverlapping() still keeps one digest per server.
        $lock = $store->getStore() instanceof LockProvider ? $store->getStore()->lock('laralyze:digest', 300) : null;

        // Another server or scheduler is on it.
        if ($lock !== null && ! $lock->get()) {
            return 0;
        }

        try {
            $deadline = Date::now()->getTimestamp() + $seconds;
            $digested = 0;

            do {
                $merged = $this->digestChunk();
                $digested += $merged;
            } while ($merged === self::CHUNK && Date::now()->getTimestamp() < $deadline);

            $this->cache->store()->forever(self::DIGESTED_AT_CACHE_KEY, Date::now()->getTimestamp());

            return $digested;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Merge the oldest rows into storage and delete them, in one transaction:
     * a digest that dies half way leaves them all for the next one.
     */
    protected function digestChunk(): int
    {
        $connection = $this->connection();
        $chunk = $connection->table(self::TABLE)->orderBy('id')->limit(self::CHUNK)->get(['id', 'payload']);

        if ($chunk->isEmpty()) {
            return 0;
        }

        $batch = new Batch;

        foreach ($chunk as $row) {
            $batch->add(Batch::decode((string) $row->payload));
        }

        $connection->transaction(function () use ($connection, $batch, $chunk) {
            $this->storage->store($batch->rows(), $batch->values(), $batch->executions());

            // By id, not by range: an insert still in flight may hold a lower id.
            foreach ($chunk->pluck('id')->chunk(500) as $ids) {
                $connection->table(self::TABLE)->whereIn('id', $ids->all())->delete();
            }
        }, attempts: 3);

        return $chunk->count();
    }

    public function digestedAt(): ?int
    {
        $at = $this->cache->store()->get(self::DIGESTED_AT_CACHE_KEY);

        return $at === null ? null : (int) $at;
    }

    public function trim(int $retentionDays): void
    {
        // The digest empties the table; this only catches what it never could,
        // after a day, so a batch it can't store doesn't pile up for the retention period.
        $this->connection()->table(self::TABLE)
            ->where('created_at', '<', Date::now()->getTimestamp() - min($retentionDays, 1) * 86_400)
            ->delete();
    }

    public function installed(): bool
    {
        return $this->connection()->getSchemaBuilder()->hasTable(self::TABLE);
    }

    /**
     * Batches waiting, and when the oldest was written.
     *
     * @return array{count: int, oldest: int|null}
     */
    public function backlog(): array
    {
        $row = $this->connection()->table(self::TABLE)->selectRaw('count(*) as aggregate, min(created_at) as oldest')->first();

        return ['count' => (int) ($row->aggregate ?? 0), 'oldest' => isset($row->oldest) ? (int) $row->oldest : null];
    }
}
