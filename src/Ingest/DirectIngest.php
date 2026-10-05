<?php

namespace MohammedMojaly\Laralyze\Ingest;

use MohammedMojaly\Laralyze\Contracts\Ingest;
use MohammedMojaly\Laralyze\Contracts\Storage;

/**
 * Every flush writes to storage itself. Right for SQLite, ClickHouse and
 * quiet apps; on a busy MySQL, PostgreSQL or SQL Server, concurrent
 * flushes fight over the same rows.
 */
class DirectIngest implements Ingest
{
    public function __construct(protected Storage $storage) {}

    public function write(array $rows, array $values, array $executions): void
    {
        $this->storage->store($rows, $values, $executions);
    }

    public function digest(int $seconds = 50): int
    {
        return 0;
    }

    public function digestedAt(): ?int
    {
        return null;
    }

    public function trim(int $retentionDays): void
    {
        //
    }
}
