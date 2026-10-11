# Database health

The Database health page shows how the database itself is doing. A task Laralyze adds to your schedule looks at it once a minute, from one server, and measures its size once an hour. It needs your cron entry, like the Servers page.

It only reads. It never changes a setting, ends a query or cleans a table; when something needs doing, the page says what, and you decide.

## What it shows

- **Right now**: connections in use against the limit, queries running longer than 30 seconds, queries waiting for another one's lock, transactions left open with nothing running, and a restart in the last day. Below them, the sessions at work, longest first: what each runs, for how long, and who it waits for.
- **Incidents**: every problem a look found, kept after it's over. Blocking, long-running queries, transactions left open, connections near the limit, deadlocks and restarts. One that lasts several minutes is one incident, kept at its worst moment with the sessions at work then. On MySQL and MariaDB, a deadlock keeps both transactions: their queries, the lock each held and the one it waited for, and which was rolled back. Deadlocks are counted as the database counts them, so the ones Laravel retried, and the ones between other apps, show too.
- **Slowing down**: connections in use over the period, and database errors by kind: deadlocks, lock timeouts, refused connections and lost ones.
- **Later**: the size and how fast it grows, the largest and fastest growing tables (Laralyze's own are marked), and auto-increment ids getting close to the largest value their column holds.

Errors, size and growth work on every database. The live checks, sessions, incidents and ids work on MySQL, MariaDB and PostgreSQL; SQL Server comes next. PostgreSQL writes a deadlock's details only to its log, so there they are counted without details.

Queries running in the database are shown without their values, like on the Queries page.

## Setup

Nothing to install on the database: no extension, no setting, no restart. Laralyze uses what the database ships with and what your app's own user may read. A check that user may not run is left out, and the page says which privilege it needs.

```php
// config/laralyze.php
Recorders\Database::class => [
    'enabled' => env('LARALYZE_DATABASE_ENABLED', true),
    'connections' => [null],   // null is the default connection; add others by name
    'long_running' => (int) env('LARALYZE_DATABASE_LONG_RUNNING', 30),   // seconds
],
```

When the cache can lock (Redis, the database, Memcached…), only one server takes the look, even when several run the scheduler.

## Privileges

Most apps' users can already see enough. To see more, give the user one of these. Each is read-only.

| Database | Privilege | What it adds |
|---|---|---|
| MySQL | `PROCESS` | Other users' sessions, open transactions, and deadlocks: how many, and the last one's details |
| MySQL | `SELECT` on `performance_schema` | Who blocks whom |
| MariaDB | `PROCESS` | Other users' sessions, open transactions, who blocks whom, and the last deadlock's details |
| PostgreSQL | `pg_read_all_stats` | Other users' sessions and their queries |

```sql
GRANT PROCESS ON *.* TO 'app'@'%';
GRANT SELECT ON performance_schema.* TO 'app'@'%';

-- PostgreSQL
GRANT pg_read_all_stats TO app;
```

## Limits

- It looks once a minute, so a lock that comes and goes between two looks isn't seen. Errors are counted every time, and so are deadlocks; only the last one's details are kept, so of several in one minute, one has its details.
- Incidents are kept for the retention period, like the rest of Laralyze's data. The first 50 each day keep their details; past that they're only counted.
- Incidents need a cache that keeps its data between runs of the scheduler. With the `array` or `null` cache store there are none, though the checks still show. With several servers running the scheduler, use a shared cache (Redis, the database, Memcached), so one server looks and the incidents aren't counted twice.
- Growth needs a day of measuring before it shows.
- MySQL keeps table statistics for up to a day (`information_schema_stats_expiry`), so sizes and ids can be that old.
- On PostgreSQL, ids are read from the sequences of serial and identity columns in the schemas on the search path.
- On PostgreSQL, connections are measured against the ones the app may open: `max_connections` less the slots kept for superusers.
- On PostgreSQL, a crash that only restarts the server's processes, like the out-of-memory killer ending one of them, isn't a restart; it shows as lost connections in the errors.
- On SQLite there are no connections or locks to look at; the page shows errors and size.
