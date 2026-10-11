<?php

use Illuminate\Database\QueryException;
use MohammedMojaly\Laralyze\Database\Errors;

function queryError(string $connection, string $state, int $code, string $message = 'failed'): QueryException
{
    $pdo = new PDOException($message);
    $pdo->errorInfo = [$state, $code, $message];

    return new QueryException($connection, 'select 1', [], $pdo);
}

it('tells database errors apart by kind', function (string $driver, string $state, int $code, ?string $kind, string $message = 'failed') {
    config(['database.connections.db.driver' => $driver]);

    expect(Errors::kind(queryError('db', $state, $code, $message)))->toBe($kind);
})->with([
    'MySQL deadlock' => ['mysql', '40001', 1213, 'deadlock'],
    'PostgreSQL deadlock' => ['pgsql', '40P01', 7, 'deadlock'],
    'SQL Server deadlock' => ['sqlsrv', '40001', 1205, 'deadlock'],
    'MySQL lock wait timeout' => ['mysql', 'HY000', 1205, 'lock_timeout'],
    'PostgreSQL lock not available' => ['pgsql', '55P03', 7, 'lock_timeout'],
    'SQLite busy' => ['sqlite', 'HY000', 5, 'lock_timeout', 'SQLSTATE[HY000]: General error: 5 database is locked'],
    'MySQL too many connections' => ['mysql', '08004', 1040, 'too_many_connections'],
    'PostgreSQL too many connections' => ['pgsql', '53300', 7, 'too_many_connections'],
    'lost connection' => ['mysql', 'HY000', 2006, 'connection_lost', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
    'a duplicate key is not one of them' => ['mysql', '23000', 1062, null],
]);

it('leaves other exceptions alone', function () {
    expect(Errors::kind(new RuntimeException('database is locked')))->toBeNull();
});
