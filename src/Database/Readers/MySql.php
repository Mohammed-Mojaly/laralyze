<?php

namespace MohammedMojaly\Laralyze\Database\Readers;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Database\Check;
use MohammedMojaly\Laralyze\Database\Reader;
use stdClass;

/**
 * MySQL and MariaDB. Without the PROCESS privilege the process list holds
 * only the app's own connections, which is still what matters most, and
 * the lock and transaction checks are left out.
 */
class MySql extends Reader
{
    /**
     * Access denied, and table or column access denied.
     */
    protected const DENIED = [1044, 1142, 1143, 1227];

    protected const IDLE = ['Sleep', 'Daemon', 'Binlog Dump', 'Binlog Dump GTID', 'Killed'];

    public function engine(): string
    {
        return ($this->maria() ? 'MariaDB ' : 'MySQL ').Str::before(Str::after($this->db->getServerVersion(), '5.5.5-'), '-');
    }

    public function now(): array
    {
        $own = (int) $this->db->scalar('select connection_id()');
        $processes = [];

        foreach ($this->db->select('show full processlist') as $process) {
            if ((int) $process->Id !== $own) {
                $processes[(int) $process->Id] = $process;
            }
        }

        return array_values(array_filter([
            $this->connections(),
            $this->longRunning($processes),
            $this->blocking($processes),
            $this->openTransactions($processes),
        ]));
    }

    public function startedAt(): ?int
    {
        $uptime = $this->status('Uptime');

        return $uptime === null ? null : time() - (int) $uptime;
    }

    public function ids(): ?array
    {
        $columns = $this->db->select(
            'select c.table_name as `table`, c.column_name as `column`, c.data_type as `type`, c.column_type as `column_type`, t.auto_increment as `next`
            from information_schema.columns c
            join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name
            where c.table_schema = database() and c.extra like ? and t.auto_increment is not null',
            ['%auto_increment%'],
        );

        $ids = [];

        foreach ($columns as $column) {
            $max = self::maxId((string) $column->type, str_contains(strtolower((string) $column->column_type), 'unsigned'));

            if ($max !== null) {
                $ids[] = ['table' => (string) $column->table, 'column' => (string) $column->column, 'type' => (string) $column->column_type, 'used' => max(0, (float) $column->next - 1), 'max' => $max];
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function connections(): ?array
    {
        $used = $this->status('Threads_connected');
        $max = (int) $this->db->scalar('select @@max_connections');

        if ($used === null || $max <= 0) {
            return null;
        }

        $percent = (int) $used / $max * 100;

        return Check::make(
            'connections', 'Connections', Check::status($percent), "{$used} / {$max}",
            $percent >= 90
                ? 'Nearly every connection is in use, and new ones will be refused. Look for connections left open, or raise max_connections.'
                : 'Close to max_connections. Look for connections left open, or raise the limit.',
            $percent,
        );
    }

    /**
     * @param  array<int, stdClass>  $processes
     * @return array<string, mixed>
     */
    protected function longRunning(array $processes): array
    {
        $rows = array_values(collect($processes)
            ->filter(fn (stdClass $process) => ! in_array($process->Command, self::IDLE, true) && $process->Info !== null && (int) $process->Time >= $this->longRunning)
            ->sortByDesc(fn (stdClass $process) => (int) $process->Time)
            ->take(10)
            ->map(fn (stdClass $process) => ['id' => (int) $process->Id, 'user' => (string) $process->User, 'seconds' => (int) $process->Time, 'query' => self::text($process->Info)])
            ->all());

        return Check::make(
            'long_running', 'Long-running queries', $rows === [] ? Check::OK : Check::WARNING,
            $rows === [] ? 'none' : count($rows).' over '.$this->longRunning.' s',
            'Check what they wait for. A missing index, or a lock another session holds, is the usual cause.',
            rows: $rows,
        );
    }

    /**
     * Who waits for whose lock.
     *
     * @param  array<int, stdClass>  $processes
     * @return array<string, mixed>|null
     */
    protected function blocking(array $processes): ?array
    {
        try {
            $waits = $this->maria()
                ? $this->db->select('select r.trx_mysql_thread_id as waiting, b.trx_mysql_thread_id as blocking
                    from information_schema.innodb_lock_waits w
                    join information_schema.innodb_trx r on r.trx_id = w.requesting_trx_id
                    join information_schema.innodb_trx b on b.trx_id = w.blocking_trx_id')
                : $this->db->select('select distinct r.processlist_id as waiting, b.processlist_id as blocking
                    from performance_schema.data_lock_waits w
                    join performance_schema.threads r on r.thread_id = w.requesting_thread_id
                    join performance_schema.threads b on b.thread_id = w.blocking_thread_id');
        } catch (QueryException $e) {
            return $this->denied($e, 'blocking', $this->maria() ? 'PROCESS' : 'SELECT on performance_schema');
        }

        $rows = [];

        foreach (array_slice($waits, 0, 10) as $wait) {
            $waiting = $processes[(int) $wait->waiting] ?? null;
            $rows[] = ['waiting' => (int) $wait->waiting, 'blocking' => (int) $wait->blocking, 'seconds' => (int) ($waiting->Time ?? 0), 'query' => self::text($waiting->Info ?? null)];
        }

        return Check::make(
            'blocking', 'Blocking', $rows === [] ? Check::OK : Check::PROBLEM,
            $rows === [] ? 'none' : count($rows).' waiting for a lock',
            'A query waits for a lock another session holds. That session usually has a transaction open.',
            rows: $rows,
        );
    }

    /**
     * Transactions left open with nothing running: they hold their locks.
     *
     * @param  array<int, stdClass>  $processes
     * @return array<string, mixed>|null
     */
    protected function openTransactions(array $processes): ?array
    {
        try {
            $open = $this->db->select('select trx_mysql_thread_id as id, trx_rows_locked as rows_locked from information_schema.innodb_trx where trx_query is null');
        } catch (QueryException $e) {
            return $this->denied($e, 'open_transactions', 'PROCESS');
        }

        $rows = [];

        // How long it has been idle comes from the process list, which needs no clock.
        foreach ($open as $transaction) {
            $process = $processes[(int) $transaction->id] ?? null;

            if ($process !== null && $process->Command === 'Sleep' && (int) $process->Time >= $this->longRunning) {
                $rows[] = ['id' => (int) $transaction->id, 'user' => (string) $process->User, 'seconds' => (int) $process->Time, 'rows_locked' => (int) $transaction->rows_locked];
            }
        }

        $rows = array_slice(collect($rows)->sortByDesc('seconds')->values()->all(), 0, 10);

        return Check::make(
            'open_transactions', 'Open transactions', $rows === [] ? Check::OK : Check::WARNING,
            $rows === [] ? 'none' : count($rows).' idle over '.$this->longRunning.' s',
            'A transaction is open with nothing running, and holds its locks: usually a missing commit, or slow work inside DB::transaction().',
            rows: $rows,
        );
    }

    /**
     * Left out quietly when the engine has no such table; counted when the user lacks a privilege.
     *
     * @return array{key: string, needs: string}|null
     */
    protected function denied(QueryException $e, string $key, string $privilege): ?array
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), self::DENIED, true) ? Check::needs($key, $privilege) : null;
    }

    protected function status(string $name): ?string
    {
        // SHOW takes no bound values; the name is always one of ours.
        $row = $this->db->selectOne("show global status like '".preg_replace('/\W/', '', $name)."'");

        return isset($row->Value) ? (string) $row->Value : null;
    }

    protected function maria(): bool
    {
        return $this->db->getDriverName() === 'mariadb' || str_contains($this->db->getServerVersion(), 'MariaDB');
    }
}
