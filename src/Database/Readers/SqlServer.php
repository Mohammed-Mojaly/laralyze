<?php

namespace MohammedMojaly\Laralyze\Database\Readers;

use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Database\Check;
use MohammedMojaly\Laralyze\Database\Reader;
use SimpleXMLElement;
use stdClass;

/**
 * SQL Server. Without VIEW SERVER STATE a user sees only its own session,
 * so the live checks need it, and the page says so. Restarts and ids need
 * nothing more.
 */
class SqlServer extends Reader
{
    public const PERMISSION = 'VIEW SERVER STATE';

    /**
     * Waits a query asks for itself: not a sign of trouble.
     */
    protected const ASKED = ['WAITFOR', 'BROKER_RECEIVE_WAITFOR', 'WAITFOR_TASKSHUTDOWN'];

    /**
     * Major versions and the year they came out.
     */
    protected const YEARS = [17 => 2025, 16 => 2022, 15 => 2019, 14 => 2017, 13 => 2016, 12 => 2014];

    public function engine(): string
    {
        $version = $this->db->getServerVersion();
        $year = self::YEARS[(int) $version] ?? null;

        return 'SQL Server '.($year === null ? $version : "{$year} ({$version})");
    }

    public function look(): array
    {
        if (! $this->allowed()) {
            return [
                'checks' => array_map(fn (string $key) => Check::needs($key, self::PERMISSION), ['connections', 'long_running', 'blocking', 'open_transactions', 'deadlocks']),
                'sessions' => [],
                'deadlocks' => null,
                'deadlock' => null,
            ];
        }

        $processes = $this->processes();

        return [
            'checks' => array_values(array_filter([
                $this->connections(),
                $this->longRunning($processes),
                $this->blocking($processes),
                $this->openTransactions($processes),
            ])),
            'sessions' => $this->sessions($processes),
            'deadlocks' => $this->deadlocks(),
            // Read only when the count goes up: see latestDeadlock().
            'deadlock' => null,
        ];
    }

    public function startedAt(): ?int
    {
        // tempdb is made anew each time the server starts, and anyone may read when.
        $uptime = $this->db->scalar("select datediff(second, create_date, getdate()) from sys.databases where name = 'tempdb'");

        return $uptime === null ? null : time() - (int) $uptime;
    }

    public function ids(): ?array
    {
        $columns = $this->db->select(
            "select object_name(c.object_id) as [table], c.name as [column], type_name(c.system_type_id) as [type], convert(decimal(38, 0), c.last_value) as [used]
            from sys.identity_columns c
            where objectproperty(c.object_id, 'IsUserTable') = 1",
        );

        $ids = [];

        foreach ($columns as $column) {
            // tinyint is the only unsigned one.
            $max = self::maxId((string) $column->type, $column->type === 'tinyint');

            if ($max !== null) {
                $ids[] = ['table' => (string) $column->table, 'column' => (string) $column->column, 'type' => (string) $column->type, 'used' => (float) $column->used, 'max' => $max];
            }
        }

        return $ids;
    }

    /**
     * The last deadlock the system_health session kept: SQL Server writes
     * each one there, with both sides. Reading it costs more than a look,
     * so it's read only when the deadlock count goes up.
     */
    public function latestDeadlock(): ?array
    {
        $report = $this->db->scalar("select top 1 cast(event_data as nvarchar(max))
            from sys.fn_xe_file_target_read_file('system_health*.xel', null, null, null)
            where object_name = 'xml_deadlock_report'
            order by file_name desc, file_offset desc");

        return $report === null ? null : self::deadlock((string) $report);
    }

    /**
     * An xml_deadlock_report event, read: when, and each process's query,
     * the lock it held and the one it waited for.
     *
     * @return array{at: string, transactions: list<array<string, mixed>>}|null
     */
    public static function deadlock(string $report): ?array
    {
        $event = $report === '' ? false : @simplexml_load_string($report);
        $deadlock = $event === false ? null : (($event->xpath('//deadlock') ?: [])[0] ?? null);

        if ($deadlock === null) {
            return null;
        }

        $victims = array_map(fn (SimpleXMLElement $victim) => (string) $victim['id'], $deadlock->xpath('victim-list/victimProcess') ?: []);
        $resources = $deadlock->xpath('resource-list/*') ?: [];
        $transactions = [];

        foreach ($deadlock->xpath('process-list/process') ?: [] as $number => $process) {
            $id = (string) $process['id'];

            $transactions[] = [
                'number' => $number + 1,
                'session' => isset($process['spid']) ? (int) $process['spid'] : null,
                'query' => self::text(self::statement(trim((string) $process->inputbuf))),
                'holds' => self::lock($resources, 'owner-list/owner', $id),
                'waits' => self::lock($resources, 'waiter-list/waiter', $id),
                'rolled_back' => in_array($id, $victims, true),
            ];
        }

        return $transactions === [] ? null : [
            'at' => preg_replace('/^(\d{4}-\d\d-\d\d)T(\d\d:\d\d:\d\d).*$/', '$1 $2', (string) $event['timestamp']).' UTC',
            'transactions' => $transactions,
        ];
    }

    /**
     * Every user session, and the request each one runs: seconds since it
     * started, and since the last one ended, by the server's own clock.
     *
     * @return list<stdClass>
     */
    protected function processes(): array
    {
        return array_values($this->db->select('select s.session_id as id, s.login_name as [user], s.host_name as host, db_name(s.database_id) as [database],
            r.status, r.wait_type, coalesce(r.blocking_session_id, 0) as blocking, s.open_transaction_count as open_transactions,
            coalesce(datediff(second, r.start_time, getdate()), 0) as running,
            coalesce(datediff(second, s.last_request_end_time, getdate()), 0) as idle,
            coalesce(substring(rt.text, r.statement_start_offset / 2 + 1, (case when r.statement_end_offset = -1 then datalength(rt.text) else r.statement_end_offset end - r.statement_start_offset) / 2 + 1), ct.text) as query
            from sys.dm_exec_sessions s
            outer apply (select top 1 * from sys.dm_exec_requests q where q.session_id = s.session_id order by q.request_id) r
            left join sys.dm_exec_connections c on c.session_id = s.session_id and c.parent_connection_id is null
            outer apply sys.dm_exec_sql_text(r.sql_handle) rt
            outer apply sys.dm_exec_sql_text(c.most_recent_sql_handle) ct
            where s.is_user_process = 1 and s.session_id <> @@spid'));
    }

    /**
     * Every session running something, or holding a transaction open,
     * longest first: what it runs, or ran last, for how long, and who it
     * waits for.
     *
     * @param  list<stdClass>  $processes
     * @return list<array<string, mixed>>
     */
    protected function sessions(array $processes): array
    {
        $sessions = [];

        foreach ($processes as $process) {
            if ($process->status === null && (int) $process->open_transactions === 0) {
                continue;
            }

            $sessions[] = [
                'id' => (int) $process->id,
                'user' => (string) $process->user,
                'host' => (string) ($process->host ?? ''),
                'database' => (string) ($process->database ?? ''),
                'seconds' => (int) ($process->status === null ? $process->idle : $process->running),
                'state' => $this->state($process),
                'blocked_by' => (int) $process->blocking > 0 ? (int) $process->blocking : null,
                'query' => self::text(self::statement($process->query)),
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
        $used = (int) $this->db->scalar('select count(*) from sys.dm_exec_sessions where is_user_process = 1');
        // 0 means as many as the server allows.
        $max = (int) $this->db->scalar("select coalesce(nullif(cast(value_in_use as int), 0), @@max_connections) from sys.configurations where name = 'user connections'");

        if ($max <= 0) {
            return null;
        }

        $percent = $used / $max * 100;

        return Check::make(
            'connections', 'Connections', Check::status($percent), "{$used} / {$max}",
            $percent >= 90
                ? 'Nearly every connection is in use, and new ones will be refused. Look for connections left open, or raise user connections.'
                : 'Close to the user connections setting. Look for connections left open, or raise the limit.',
            $percent,
        );
    }

    /**
     * @param  list<stdClass>  $processes
     * @return array<string, mixed>
     */
    protected function longRunning(array $processes): array
    {
        $rows = array_values(collect($processes)
            ->filter(fn (stdClass $process) => $process->status !== null && ! in_array($process->wait_type, self::ASKED, true) && (int) $process->running >= $this->longRunning)
            ->sortByDesc(fn (stdClass $process) => (int) $process->running)
            ->take(10)
            ->map(fn (stdClass $process) => ['id' => (int) $process->id, 'user' => (string) $process->user, 'seconds' => (int) $process->running, 'query' => self::text(self::statement($process->query))])
            ->all());

        return Check::make(
            'long_running', 'Long-running queries', $rows === [] ? Check::OK : Check::WARNING,
            $rows === [] ? 'none' : count($rows).' over '.$this->longRunning.' s',
            'Check what they wait for. A missing index, or a lock another session holds, is the usual cause.',
            rows: $rows,
        );
    }

    /**
     * @param  list<stdClass>  $processes
     * @return array<string, mixed>
     */
    protected function blocking(array $processes): array
    {
        $users = collect($processes)->mapWithKeys(fn (stdClass $process) => [(int) $process->id => (string) $process->user]);
        $rows = [];

        foreach ($processes as $process) {
            // Below zero are the server's own: an orphaned distributed transaction, a latch.
            if ((int) $process->blocking > 0 && count($rows) < 10) {
                $rows[] = ['waiting' => (int) $process->id, 'blocking' => (int) $process->blocking, 'blocker' => $users[(int) $process->blocking] ?? '', 'seconds' => (int) $process->running, 'query' => self::text(self::statement($process->query))];
            }
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
     * @param  list<stdClass>  $processes
     * @return array<string, mixed>
     */
    protected function openTransactions(array $processes): array
    {
        $rows = array_values(collect($processes)
            ->filter(fn (stdClass $process) => $process->status === null && (int) $process->open_transactions > 0 && (int) $process->idle >= $this->longRunning)
            ->sortByDesc(fn (stdClass $process) => (int) $process->idle)
            ->take(10)
            ->map(fn (stdClass $process) => ['id' => (int) $process->id, 'user' => (string) $process->user, 'seconds' => (int) $process->idle])
            ->all());

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
        $count = $this->db->scalar("select cntr_value from sys.dm_os_performance_counters where counter_name = 'Number of Deadlocks/sec' and instance_name = '_Total'");

        return $count === null ? null : (int) $count;
    }

    /**
     * Whether this user may see every session. SQL Server 2022 also has a
     * narrower permission for the same views.
     */
    protected function allowed(): bool
    {
        return (bool) $this->db->scalar("select coalesce(has_perms_by_name(null, null, 'VIEW SERVER STATE'), 0) | coalesce(has_perms_by_name(null, null, 'VIEW SERVER PERFORMANCE STATE'), 0)");
    }

    protected function state(stdClass $process): string
    {
        return match (true) {
            $process->status === null => 'idle, transaction open',
            (int) $process->blocking > 0 || str_starts_with((string) $process->wait_type, 'LCK_') => 'waiting for a lock',
            default => (string) $process->status,
        };
    }

    /**
     * The statement without the parameters it declares first, as in
     * "(@P1 nvarchar(4000),@P2 int)select …", and with ? for them: @P1 is
     * the app's, @1 one SQL Server put in place of a value itself.
     */
    protected static function statement(?string $sql): string
    {
        $sql = (string) $sql;

        for ($i = 0, $depth = 0; str_starts_with($sql, '(@') && $i < strlen($sql); $i++) {
            $depth += match ($sql[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                $sql = ltrim(substr($sql, $i + 1));

                break;
            }
        }

        return (string) preg_replace('/@P?\d+\b/', '?', $sql);
    }

    /**
     * The first resource this process holds or waits for, like
     * "orders (PK_orders): X".
     *
     * @param  array<SimpleXMLElement>  $resources
     */
    protected static function lock(array $resources, string $path, string $process): string
    {
        foreach ($resources as $resource) {
            foreach ($resource->xpath($path) ?: [] as $party) {
                if ((string) $party['id'] === $process) {
                    $table = (string) ($resource['objectname'] ?? '');
                    $table = $table === '' ? $resource->getName() : Str::afterLast($table, '.');
                    $index = (string) ($resource['indexname'] ?? '');

                    return $table.($index === '' ? '' : " ({$index})").': '.(string) $party['mode'];
                }
            }
        }

        return '';
    }
}
