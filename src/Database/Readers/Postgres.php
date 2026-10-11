<?php

namespace MohammedMojaly\Laralyze\Database\Readers;

use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Database\Check;
use MohammedMojaly\Laralyze\Database\Reader;
use stdClass;

/**
 * PostgreSQL. Everything here is open to any user, though without
 * pg_read_all_stats the sessions of other users show no query or state,
 * so only the app's own are looked at. PostgreSQL counts deadlocks, but
 * writes their details only to its log, so they come without details.
 */
class Postgres extends Reader
{
    public function engine(): string
    {
        return 'PostgreSQL '.Str::before($this->db->getServerVersion(), ' ');
    }

    public function look(): array
    {
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
            'deadlock' => null,
        ];
    }

    public function startedAt(): ?int
    {
        $started = $this->db->scalar('select extract(epoch from pg_postmaster_start_time())');

        return $started === null ? null : (int) $started;
    }

    public function ids(): ?array
    {
        // A sequence owned by a column: serial and identity columns.
        $columns = $this->db->select(
            'select t.relname as "table", a.attname as "column", format_type(a.atttypid, null) as "type", coalesce(s.last_value, 0) as "used", s.max_value as "limit"
            from pg_sequences s
            join pg_namespace n on n.nspname = s.schemaname
            join pg_class seq on seq.relname = s.sequencename and seq.relnamespace = n.oid
            join pg_depend d on d.objid = seq.oid and d.classid = \'pg_class\'::regclass and d.refclassid = \'pg_class\'::regclass and d.deptype in (\'a\', \'i\')
            join pg_class t on t.oid = d.refobjid
            join pg_attribute a on a.attrelid = t.oid and a.attnum = d.refobjsubid
            where s.schemaname = any(current_schemas(false))',
        );

        $ids = [];

        foreach ($columns as $column) {
            $max = self::maxId((string) $column->type, false);

            if ($max !== null) {
                $ids[] = ['table' => (string) $column->table, 'column' => (string) $column->column, 'type' => (string) $column->type, 'used' => (float) $column->used, 'max' => min($max, (float) $column->limit)];
            }
        }

        return $ids;
    }

    /**
     * The app's sessions, and the others it may see: seconds since the
     * query started and since the state last changed, measured by the
     * database's own clock.
     *
     * @return list<stdClass>
     */
    protected function processes(): array
    {
        return array_values($this->db->select("select pid, usename, host(client_addr) as host, datname, state, wait_event_type, query,
            greatest(0, extract(epoch from clock_timestamp() - query_start))::int as running,
            greatest(0, extract(epoch from clock_timestamp() - state_change))::int as idle,
            pg_blocking_pids(pid)::text as blocking
            from pg_stat_activity
            where backend_type = 'client backend' and pid <> pg_backend_pid()"));
    }

    /**
     * Every session doing something, or holding a transaction open, longest
     * first: what it runs, or ran last, for how long, and who it waits for.
     *
     * @param  list<stdClass>  $processes
     * @return list<array<string, mixed>>
     */
    protected function sessions(array $processes): array
    {
        $sessions = [];

        foreach ($processes as $process) {
            // Idle, or another user's session it may not see.
            if ($process->state === null || $process->state === 'idle') {
                continue;
            }

            $sessions[] = [
                'id' => (int) $process->pid,
                'user' => (string) $process->usename,
                'host' => (string) ($process->host ?? ''),
                'database' => (string) ($process->datname ?? ''),
                'seconds' => $this->seconds($process),
                'state' => $this->state($process),
                'blocked_by' => $this->blockers($process)[0] ?? null,
                'query' => self::text($process->query),
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
        $used = (int) $this->db->scalar("select count(*) from pg_stat_activity where backend_type = 'client backend'");
        $max = (int) $this->db->scalar("select current_setting('max_connections')::int");

        if ($max <= 0) {
            return null;
        }

        $percent = $used / $max * 100;

        return Check::make(
            'connections', 'Connections', Check::status($percent), "{$used} / {$max}",
            $percent >= 90
                ? 'Nearly every connection is in use, and new ones will be refused. Look for connections left open, or raise max_connections.'
                : 'Close to max_connections. Look for connections left open, or raise the limit.',
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
            ->filter(fn (stdClass $process) => $process->state === 'active' && (int) $process->running >= $this->longRunning)
            ->sortByDesc(fn (stdClass $process) => (int) $process->running)
            ->take(10)
            ->map(fn (stdClass $process) => ['id' => (int) $process->pid, 'user' => (string) $process->usename, 'seconds' => (int) $process->running, 'query' => self::text($process->query)])
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
        $users = collect($processes)->mapWithKeys(fn (stdClass $process) => [(int) $process->pid => (string) $process->usename]);
        $rows = [];

        foreach ($processes as $process) {
            $blocking = $this->blockers($process)[0] ?? null;

            if ($blocking !== null && count($rows) < 10) {
                $rows[] = ['waiting' => (int) $process->pid, 'blocking' => $blocking, 'blocker' => $users[$blocking] ?? '', 'seconds' => (int) $process->running, 'query' => self::text($process->query)];
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
            ->filter(fn (stdClass $process) => str_starts_with((string) $process->state, 'idle in transaction') && (int) $process->idle >= $this->longRunning)
            ->sortByDesc(fn (stdClass $process) => (int) $process->idle)
            ->take(10)
            ->map(fn (stdClass $process) => ['id' => (int) $process->pid, 'user' => (string) $process->usename, 'seconds' => (int) $process->idle])
            ->all());

        return Check::make(
            'open_transactions', 'Open transactions', $rows === [] ? Check::OK : Check::WARNING,
            $rows === [] ? 'none' : count($rows).' idle over '.$this->longRunning.' s',
            'A transaction is open with nothing running, and holds its locks: usually a missing commit, or slow work inside DB::transaction().',
            rows: $rows,
        );
    }

    /**
     * Deadlocks in this database since its statistics were last reset,
     * including the ones Laravel retried.
     */
    protected function deadlocks(): ?int
    {
        $count = $this->db->scalar('select deadlocks from pg_stat_database where datname = current_database()');

        return $count === null ? null : (int) $count;
    }

    /**
     * The sessions holding the lock it waits for, from "{12,15}".
     *
     * @return list<int>
     */
    protected function blockers(stdClass $process): array
    {
        $pids = trim((string) ($process->blocking ?? ''), '{}');

        return $pids === '' ? [] : array_map(intval(...), explode(',', $pids));
    }

    protected function seconds(stdClass $process): int
    {
        return (int) ($process->state === 'active' ? $process->running : $process->idle);
    }

    protected function state(stdClass $process): string
    {
        return match (true) {
            $process->state === 'idle in transaction' => 'idle, transaction open',
            $process->state === 'idle in transaction (aborted)' => 'idle, transaction failed',
            $process->state === 'active' && $process->wait_event_type === 'Lock' => 'waiting for a lock',
            default => (string) $process->state,
        };
    }
}
