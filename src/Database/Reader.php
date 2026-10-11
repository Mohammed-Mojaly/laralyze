<?php

namespace MohammedMojaly\Laralyze\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Recorders\Queries;
use MohammedMojaly\Laralyze\Support\Secrets;

/**
 * Reads what one database says about itself, using only what it ships
 * with and what an ordinary user may read. It never changes anything.
 *
 * Engines without a reader of their own get the parts every engine
 * shares: errors and size, which come from Laralyze and from Laravel.
 */
class Reader
{
    public function __construct(protected Connection $db, protected int $longRunning = 30) {}

    public static function for(Connection $db, int $longRunning = 30): self
    {
        return match ($db->getDriverName()) {
            'mysql', 'mariadb' => new Readers\MySql($db, $longRunning),
            default => new self($db, $longRunning),
        };
    }

    /**
     * Its name and version, like "MySQL 8.4.2".
     */
    public function engine(): string
    {
        $names = ['sqlite' => 'SQLite', 'pgsql' => 'PostgreSQL', 'sqlsrv' => 'SQL Server'];

        return ($names[$this->db->getDriverName()] ?? $this->db->getDriverName()).' '.$this->db->getServerVersion();
    }

    /**
     * One look: the checks (connections, long-running queries, locks, open
     * transactions), the sessions at work, deadlocks since the server
     * started, and the last one's details.
     *
     * @return array{checks: list<array<string, mixed>>, sessions: list<array<string, mixed>>, deadlocks: int|null, deadlock: array<string, mixed>|null}
     */
    public function look(): array
    {
        return ['checks' => [], 'sessions' => [], 'deadlocks' => null, 'deadlock' => null];
    }

    /**
     * When the database server last started, as a Unix time.
     */
    public function startedAt(): ?int
    {
        return null;
    }

    /**
     * Auto-increment ids against the largest value their column holds;
     * null when there's no such check for the engine.
     *
     * @return list<array{table: string, column: string, type: string, used: float, max: float}>|null
     */
    public function ids(): ?array
    {
        return null;
    }

    /**
     * SQL running in the database holds the values it was given: they are
     * taken out, and anything that looks like a secret is hidden.
     */
    public static function text(?string $sql): string
    {
        return Str::limit(Secrets::mask(Queries::withoutValues((string) $sql)), 300);
    }

    /**
     * The largest id a column of this type holds.
     */
    public static function maxId(string $type, bool $unsigned): ?float
    {
        $bits = ['tinyint' => 8, 'smallint' => 16, 'mediumint' => 24, 'int' => 32, 'integer' => 32, 'bigint' => 64][strtolower($type)] ?? null;

        return $bits === null ? null : 2 ** ($unsigned ? $bits : $bits - 1) - 1;
    }
}
