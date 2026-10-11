<?php

namespace MohammedMojaly\Laralyze\Database;

use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * The database errors worth counting apart from the rest: they say more
 * about the database than about the code that ran into them.
 */
final class Errors
{
    use DetectsLostConnections;

    public const KINDS = [
        'deadlock' => 'Deadlocks',
        'lock_timeout' => 'Lock timeouts',
        'too_many_connections' => 'Too many connections',
        'connection_lost' => 'Connections lost',
    ];

    /**
     * Its key: ["connection","kind"].
     */
    public static function key(string $connection, string $kind): string
    {
        return (string) json_encode([$connection, $kind], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function kind(Throwable $e): ?string
    {
        if (! $e instanceof PDOException) {
            return null;
        }

        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $code = (int) ($e->errorInfo[1] ?? 0);
        $driver = $e instanceof QueryException ? config("database.connections.{$e->getConnectionName()}.driver") : null;

        return match (true) {
            // 1205 is a deadlock on SQL Server and a lock wait timeout on MySQL.
            in_array($state, ['40001', '40P01'], true), $code === 1213, $driver === 'sqlsrv' && $code === 1205 => 'deadlock',
            $state === '55P03', $code === 1205, str_contains($e->getMessage(), 'database is locked') => 'lock_timeout',
            $state === '53300', $code === 1040 => 'too_many_connections',
            (new self)->causedByLostConnection($e) => 'connection_lost',
            default => null,
        };
    }
}
