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
 * the lock, transaction and deadlock checks are left out.
 */
class MySql extends Reader
{
    /**
     * Access denied, and table or column access denied.
     */
    protected const DENIED = [1044, 1142, 1143, 1227];

    /**
     * The server's own threads, never the app's.
     */
    protected const SYSTEM = ['Daemon', 'Binlog Dump', 'Binlog Dump GTID', 'Killed'];

    /**
     * Checks this look couldn't run => the privilege they need.
     *
     * @var array<string, string>
     */
    protected array $denied = [];

    public function engine(): string
    {
        return ($this->maria() ? 'MariaDB ' : 'MySQL ').Str::before(Str::after($this->db->getServerVersion(), '5.5.5-'), '-');
    }

    public function look(): array
    {
        $own = (int) $this->db->scalar('select connection_id()');
        $processes = [];
        $this->denied = [];

        foreach ($this->db->select('show full processlist') as $process) {
            if ((int) $process->Id !== $own && ! in_array($process->Command, self::SYSTEM, true)) {
                $processes[(int) $process->Id] = $process;
            }
        }

        $waits = $this->waits();
        $transactions = $this->transactions();
        $deadlocks = $this->deadlocks();
        $deadlock = $this->latestDeadlock();

        return [
            'checks' => array_values(array_filter([
                $this->connections(),
                $this->longRunning($processes),
                $this->blocking($processes, $waits),
                $this->openTransactions($processes, $transactions),
                // Not a line of its own: only counted when it can't be read.
                $this->needs('deadlocks'),
            ])),
            'sessions' => $this->sessions($processes, $waits ?? [], $transactions ?? []),
            'deadlocks' => $deadlocks,
            'deadlock' => $deadlock,
        ];
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
     * Every session doing something, or holding a transaction open, longest first:
     * what it runs, for how long, and who it waits for.
     *
     * @param  array<int, stdClass>  $processes
     * @param  array<int, int>  $waits  waiting session => blocking session
     * @param  array<int, int>  $transactions  session => rows locked
     * @return list<array<string, mixed>>
     */
    protected function sessions(array $processes, array $waits, array $transactions): array
    {
        $sessions = [];

        foreach ($processes as $id => $process) {
            $idle = $process->Command === 'Sleep';

            if ($idle && ! isset($transactions[$id])) {
                continue;
            }

            $sessions[] = [
                'id' => $id,
                'user' => (string) $process->User,
                'host' => Str::before((string) $process->Host, ':'),
                'database' => (string) ($process->db ?? ''),
                'seconds' => (int) $process->Time,
                'state' => $idle ? 'idle, transaction open' : (string) ($process->State ?: $process->Command),
                'blocked_by' => $waits[$id] ?? null,
                'rows_locked' => $transactions[$id] ?? null,
                'query' => $idle ? '' : self::text($process->Info),
            ];
        }

        usort($sessions, fn (array $a, array $b) => $b['seconds'] <=> $a['seconds']);

        return array_slice($sessions, 0, 25);
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
            ->filter(fn (stdClass $process) => $process->Command !== 'Sleep' && $process->Info !== null && (int) $process->Time >= $this->longRunning)
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
     * Who waits for whose lock: waiting session => blocking session.
     *
     * @return array<int, int>|null
     */
    protected function waits(): ?array
    {
        try {
            $rows = $this->maria()
                ? $this->db->select('select r.trx_mysql_thread_id as waiting, b.trx_mysql_thread_id as blocking
                    from information_schema.innodb_lock_waits w
                    join information_schema.innodb_trx r on r.trx_id = w.requesting_trx_id
                    join information_schema.innodb_trx b on b.trx_id = w.blocking_trx_id')
                : $this->db->select('select distinct r.processlist_id as waiting, b.processlist_id as blocking
                    from performance_schema.data_lock_waits w
                    join performance_schema.threads r on r.thread_id = w.requesting_thread_id
                    join performance_schema.threads b on b.thread_id = w.blocking_thread_id');
        } catch (QueryException $e) {
            return $this->deny($e, 'blocking', $this->maria() ? 'PROCESS' : 'SELECT on performance_schema');
        }

        $waits = [];

        foreach ($rows as $row) {
            $waits[(int) $row->waiting] = (int) $row->blocking;
        }

        return $waits;
    }

    /**
     * Sessions with a transaction open => rows they lock.
     *
     * @return array<int, int>|null
     */
    protected function transactions(): ?array
    {
        try {
            $rows = $this->db->select('select trx_mysql_thread_id as id, trx_rows_locked as rows_locked from information_schema.innodb_trx');
        } catch (QueryException $e) {
            return $this->deny($e, 'open_transactions', 'PROCESS');
        }

        $transactions = [];

        foreach ($rows as $row) {
            $transactions[(int) $row->id] = (int) $row->rows_locked;
        }

        return $transactions;
    }

    /**
     * @param  array<int, stdClass>  $processes
     * @param  array<int, int>|null  $waits
     * @return array<string, mixed>|null
     */
    protected function blocking(array $processes, ?array $waits): ?array
    {
        if ($waits === null) {
            return $this->needs('blocking');
        }

        $rows = [];

        foreach (array_slice($waits, 0, 10, true) as $waiting => $blocking) {
            $process = $processes[$waiting] ?? null;
            $rows[] = ['waiting' => $waiting, 'blocking' => $blocking, 'blocker' => (string) ($processes[$blocking]->User ?? ''), 'seconds' => (int) ($process->Time ?? 0), 'query' => self::text($process->Info ?? null)];
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
     * @param  array<int, int>|null  $transactions
     * @return array<string, mixed>|null
     */
    protected function openTransactions(array $processes, ?array $transactions): ?array
    {
        if ($transactions === null) {
            return $this->needs('open_transactions');
        }

        $rows = [];

        // How long it has been idle comes from the process list, which needs no clock.
        foreach ($transactions as $id => $locked) {
            $process = $processes[$id] ?? null;

            if ($process !== null && $process->Command === 'Sleep' && (int) $process->Time >= $this->longRunning) {
                $rows[] = ['id' => $id, 'user' => (string) $process->User, 'seconds' => (int) $process->Time, 'rows_locked' => $locked];
            }
        }

        usort($rows, fn (array $a, array $b) => $b['seconds'] <=> $a['seconds']);
        $rows = array_slice($rows, 0, 10);

        return Check::make(
            'open_transactions', 'Open transactions', $rows === [] ? Check::OK : Check::WARNING,
            $rows === [] ? 'none' : count($rows).' idle over '.$this->longRunning.' s',
            'A transaction is open with nothing running, and holds its locks: usually a missing commit, or slow work inside DB::transaction().',
            rows: $rows,
        );
    }

    /**
     * Every deadlock since the server started, including the ones Laravel
     * retried and the ones between other apps.
     */
    protected function deadlocks(): ?int
    {
        if ($this->maria()) {
            $count = $this->status('Innodb_deadlocks');

            return $count === null ? null : (int) $count;
        }

        try {
            $count = $this->db->scalar("select `count` from information_schema.innodb_metrics where name = 'lock_deadlocks'");
        } catch (QueryException $e) {
            return $this->deny($e, 'deadlocks', 'PROCESS');
        }

        return $count === null ? null : (int) $count;
    }

    /**
     * The last deadlock the server noted, from SHOW ENGINE INNODB STATUS.
     *
     * @return array<string, mixed>|null
     */
    protected function latestDeadlock(): ?array
    {
        try {
            $status = (string) ($this->db->selectOne('show engine innodb status')->Status ?? '');
        } catch (QueryException $e) {
            return $this->deny($e, 'deadlocks', 'PROCESS');
        }

        return self::deadlock($status);
    }

    /**
     * The deadlock section of SHOW ENGINE INNODB STATUS, read: when, and
     * each transaction's query, the lock it held and the one it waited for.
     * Row data in the section is left out.
     *
     * @return array{at: string, transactions: list<array<string, mixed>>}|null
     */
    public static function deadlock(string $status): ?array
    {
        if (! preg_match('/LATEST DETECTED DEADLOCK\s*\n-+\n(.*?)\n-{4,}\nTRANSACTIONS/s', $status, $section)) {
            return null;
        }

        $text = $section[1];
        $at = (string) preg_replace('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d).*/s', '$1', trim($text));
        preg_match('/WE ROLL BACK TRANSACTION \((\d+)\)/', $text, $rolledBack);

        // [before, number, text, number, text…]
        $parts = preg_split('/\*\*\* \((\d+)\) TRANSACTION:\n/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $transactions = [];

        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            [$number, $part] = [(int) $parts[$i], $parts[$i + 1]];
            preg_match('/MySQL thread id (\d+)[^\n]*\n(.*?)\n\*\*\*/s', $part, $query);
            preg_match('/HOLDS THE LOCK\(S\):\n([^\n]*)/', $part, $holds);
            preg_match('/WAITING FOR THIS LOCK TO BE GRANTED:\n([^\n]*)/', $part, $waits);

            $transactions[] = [
                'number' => $number,
                'session' => isset($query[1]) ? (int) $query[1] : null,
                'query' => self::text($query[2] ?? null),
                'holds' => self::lock($holds[1] ?? ''),
                'waits' => self::lock($waits[1] ?? ''),
                'rolled_back' => (int) ($rolledBack[1] ?? 0) === $number,
            ];
        }

        return $transactions === [] ? null : ['at' => $at, 'transactions' => $transactions];
    }

    /**
     * "RECORD LOCKS space id 5 page no 4 n bits 72 index PRIMARY of table `shop`.`orders` trx id 5678 lock_mode X locks rec but not gap"
     * becomes "orders (PRIMARY): X locks rec but not gap".
     */
    protected static function lock(string $line): string
    {
        if (! preg_match('/table `[^`]+`\.`([^`]+)`/', $line, $table)) {
            return '';
        }

        preg_match('/index `?(\w+)`? of table/', $line, $index);
        preg_match('/lock[_ ]mode (.+)$/', $line, $mode);

        return $table[1].(isset($index[1]) ? " ({$index[1]})" : '').(isset($mode[1]) ? ': '.trim($mode[1]) : '');
    }

    /**
     * A check the user may not run is counted with the privilege it needs;
     * one the engine has no table for is left out quietly. Either way it
     * has nothing to read.
     */
    protected function deny(QueryException $e, string $key, string $privilege): null
    {
        if (in_array((int) ($e->errorInfo[1] ?? 0), self::DENIED, true)) {
            $this->denied[$key] = $privilege;
        }

        return null;
    }

    /**
     * @return array{key: string, needs: string}|null
     */
    protected function needs(string $key): ?array
    {
        return isset($this->denied[$key]) ? Check::needs($key, $this->denied[$key]) : null;
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
