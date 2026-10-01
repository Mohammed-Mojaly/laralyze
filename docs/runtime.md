# Running Laralyze in production

## How it adds so little

- During a request, Laralyze only adds numbers to an in-memory buffer. Queries and cache calls are summed per SQL string or key; fingerprinting and grouping wait.
- Everything is written once, **after the response has been sent**, on Laralyze's own connection, and never inside a transaction your app has open.
- Jobs, commands and scheduled tasks are written when each one finishes.
- If Laralyze's storage fails, your app never sees the error.

Measured on a reference app (PHP 8.4, SQLite), before the response:

| Request | Added time |
|---|---|
| Trivial route | ~0.03–0.08 ms |
| 50 queries | ~0.2–0.3 ms |
| 1,000 queries | ~1.5–2 ms |
| 200 cache calls | ~0.5 ms |

Each query or cache call goes through Laravel's event dispatcher, about 1–2 µs, like any tool that listens to them; a request with 1,000 queries gets about 4–5% slower. If that matters for a hot path, turn the recorder off or wrap the code in `Laralyze::ignore(fn () => ...)`.

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

## Several servers

Point every server at the same database (or the same `LARALYZE_DB_CONNECTION`). Their numbers add up in the same buckets. Set `LARALYZE_SERVER_NAME` if hostnames aren't meaningful.

## A separate database

```env
LARALYZE_DB_CONNECTION=monitoring
```

Laralyze stores pre-aggregated numbers, not raw events, so its tables grow with the number of distinct routes, queries and keys, not with traffic.

## Turning it off

`LARALYZE_ENABLED=false` stops everything at once, without a deploy of code changes.
