<?php

namespace MohammedMojaly\Laralyze\Support;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Deadlocks, lock waits and racing inserts: the database is busy with
 * concurrent writes, not down. Trying again soon works.
 */
final class Contention
{
    use DetectsConcurrencyErrors;

    public static function causedBy(Throwable $e): bool
    {
        // SQL Server's MERGE lets two writers insert the same new row; the loser fails on the unique key.
        return $e instanceof UniqueConstraintViolationException || (new self)->causedByConcurrencyError($e);
    }
}
