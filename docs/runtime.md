# Running Laralyze in production

## How it adds so little

- During a request, Laralyze only adds numbers to an in-memory buffer. Queries and cache calls are summed per SQL string or key; fingerprinting and grouping wait.
- Everything is written once, **after the response has been sent**, on Laralyze's own connection, and never inside a transaction your app has open.
- Jobs, commands and scheduled tasks are written when each one finishes.
- If Laralyze's storage fails, your app never sees the error. Laralyze stops writing for a minute, so a database that is down doesn't make every request wait for its connection timeout, then tries again. The pause is shared through APCu when it's installed.

Measured on a reference app (PHP 8.4, SQLite), before the response:

| Request | Added time |
|---|---|
| Trivial route | ~0.03–0.08 ms |
| 50 queries | ~0.2–0.3 ms |
| 1,000 queries | ~2 ms |
| 200 cache calls | ~0.5 ms |

Each query or cache call goes through Laravel's event dispatcher, about 1–2 µs, like any tool that listens to them (one listener serves every recorder); a request with 1,000 queries gets about 4–5% slower. If that matters for a hot path, turn the recorder off or wrap the code in `Laralyze::ignore(fn () => ...)`.

## Timelines

The `Traces` recorder keeps single requests, jobs and commands with what happened inside them. Every execution collects its events in memory (up to 500); at the end, a slow, failed or throwing one is written as one row, and the rest only when sampled (10% by default). Lower `LARALYZE_TRACES_SAMPLE_RATE` on busy apps, or set it to 1 while debugging.

## When something is wrong

The dashboard shows a warning above the cards, and `php artisan about` shows it under *Health*, when:

- Laralyze's tables are missing, or its database can't be reached;
- a write failed in the last hour, with the reason;
- the scheduler hasn't run Laralyze's hourly cleanup for over two hours;
- jobs were queued in the last hour but no worker recorded running one (restart your workers);
- a web request recorded more distinct metrics than the buffer holds (`LARALYZE_BUFFER`, 5,000 by default) and some were dropped.

## The scheduler

Add Laravel's usual cron entry:

```
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

Laralyze adds two tasks to your schedule:

- `laralyze:trim`, hourly: removes data past the retention period. Without cron, Laralyze still cleans up now and then after a request.
- `laralyze:servers`, every minute: CPU, memory and disk. Each server that runs the scheduler reports itself.

## PHP-FPM, LiteSpeed, Apache

Nothing to do. Data is written after the response is sent (`fastcgi_finish_request`), so users don't wait for it.

## Octane

Supported, and tested on Swoole and FrankenPHP. Laralyze clears its buffer at the start of every request, so nothing leaks between requests. Dashboard cards check the `viewLaralyze` gate themselves on every update, so permission is never cached by a long-running worker.

## Queue workers

Each job's numbers are written when the job finishes, and again on every worker loop. Long-running workers don't build up memory.

A worker loads Laralyze when it starts. After installing or upgrading Laralyze, restart your workers with `php artisan queue:restart` (or `php artisan horizon:terminate`), as after any deploy.

## Several servers

Point every server at the same database (or the same `LARALYZE_DB_CONNECTION`). Their numbers add up in the same buckets. Set `LARALYZE_SERVER_NAME` if hostnames aren't meaningful.

## A separate database

```env
LARALYZE_DB_CONNECTION=monitoring
```

Laralyze stores pre-aggregated numbers, not raw events, so its tables grow with the number of distinct routes, queries and keys, not with traffic.

## ClickHouse in production

With `LARALYZE_STORAGE=clickhouse`, every request, job and command still writes once, after the response is sent. Each write is an asynchronous insert: ClickHouse collects the small inserts from all your processes and saves them as one batch, every 50 to 200 milliseconds (up to a second on ClickHouse Cloud). This is what ClickHouse recommends for many small clients, and it keeps the number of parts low.

- **Waiting.** By default each write waits until its batch is saved, so failures surface on the dashboard. On PHP-FPM that wait happens after the response is sent, so users don't notice it, though the worker stays busy for it.
- **Octane and very high traffic.** On Octane the wait holds the worker before its next request. Set `LARALYZE_CLICKHOUSE_WAIT=false` to stop waiting: writes return at once, but a write that fails to save is no longer reported. Keep `LARALYZE_CLICKHOUSE_TIMEOUT` low (1 to 2 seconds) either way.
- **Distance.** Run ClickHouse in the same data center or private network as your app. Every write pays the round trip.
- **Access.** Give Laralyze its own user with rights on its database only (`GRANT ALL ON laralyze.* TO laralyze`). Use HTTPS (port 8443) or keep ports 8123 and 9000 behind a firewall: never expose them to the internet over plain HTTP.
- **Retention.** Old data goes by whole days, dropped at once, so ClickHouse never rewrites data to delete it. A day can stay up to a day past `retention`.
- **Several servers.** Point them all at the same ClickHouse database.

## Turning it off

`LARALYZE_ENABLED=false` stops everything at once, without a deploy of code changes.
