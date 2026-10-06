# Running in production

## Overhead

During a request, Laralyze adds numbers to an in-memory buffer. It writes once, after the response is sent, on its own connection, never inside a transaction your app has open. If that write fails, your app doesn't see the error; Laralyze pauses for a minute and tries again.

Measured on a reference app (PHP 8.4, SQLite), before the response:

| Request | Added time |
|---|---|
| Simple route | ~0.03–0.08 ms |
| 50 queries | ~0.2–0.3 ms |
| 1,000 queries | ~2 ms |
| 200 cache calls | ~0.5 ms |

Each query and cache call passes through Laravel's event dispatcher (about 1–2 µs). For a hot path, turn the recorder off or wrap the code in `Laralyze::ignore(fn () => ...)`.

## How writes reach the database

Every request adds to the same rows (the count for `GET /`, a query's total time), and many processes updating the same rows can deadlock. `LARALYZE_INGEST` picks how writes get in:

- **`database`**, the default on MySQL, MariaDB, PostgreSQL and SQL Server: each request, job and command inserts one row into `laralyze_ingest`, and the scheduler's `laralyze:digest` merges them every minute. The dashboard is up to a minute behind.
- **`direct`**, the default on SQLite: each writes to Laralyze's tables itself and retries a statement that deadlocks.

ClickHouse always writes directly.

## The scheduler

```
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

Laralyze schedules `laralyze:trim` (hourly, removes old data), `laralyze:digest` (every minute, with database ingest), `laralyze:servers` (every minute) and alert checks. Without the scheduler, a request now and then runs the digest and the cleanup itself.

## Several servers

Point every server at the same database or ClickHouse. A cache lock keeps one digest at a time, and alerts go out from one server when the cache store supports locks. Set `LARALYZE_SERVER_NAME` if hostnames aren't meaningful.

## PHP-FPM, Octane and queue workers

- **PHP-FPM and LiteSpeed** (also behind Apache or Nginx): data is written after the response is sent.
- **Octane** (Swoole, FrankenPHP): the buffer is cleared at the start of every request. Cards check the `viewLaralyze` gate on every update.
- **Queue workers**: each job is written when it finishes. A worker loads Laralyze when it starts, so restart workers after installing or upgrading (`php artisan queue:restart` or `horizon:terminate`).

## When something is wrong

The dashboard shows a warning above the cards, and `php artisan about` lists it under *Health*, when:

- Laralyze's tables are missing, or its database can't be reached;
- a write failed in the last hour (recording pauses for a minute after a failure);
- the scheduler hasn't run the hourly cleanup for two hours;
- jobs were queued but no worker recorded running one (restart your workers);
- a web request recorded more distinct metrics than `LARALYZE_BUFFER` and some were dropped;
- writes failed because of lock contention, even after retries (use database ingest or ClickHouse);
- batches have waited more than five minutes for the digest (the scheduler isn't running);
- no alert channel took an alert in the last hour.

## ClickHouse in production

- Each write is an asynchronous insert: ClickHouse collects small inserts from all processes and saves them as one batch, every 50–200 ms.
- By default each write waits until its batch is saved, so failures show on the dashboard. On Octane that wait holds the worker; `LARALYZE_CLICKHOUSE_WAIT=false` stops waiting, but failed writes are no longer reported.
- Run ClickHouse in the same network as your app: every write and dashboard query pays the round trip.
- Give Laralyze its own user with rights on its database only. Use HTTPS (8443), or keep ports 8123 and 9000 behind a firewall.
- Old data is dropped by whole days, so a day can stay up to a day past `retention`.

## Turning it off

`LARALYZE_ENABLED=false` stops everything.
