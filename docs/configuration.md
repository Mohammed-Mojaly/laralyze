# Configuration

Everything lives in `config/laralyze.php`, published by `php artisan laralyze:install`. Most settings also have an environment variable.

## General

| Key | Env | Default | What it does |
|---|---|---|---|
| `enabled` | `LARALYZE_ENABLED` | `true` | Turns Laralyze off completely: no listeners, no routes, no work. |
| `storage.connection` | `LARALYZE_DB_CONNECTION` | default connection | Keeps Laralyze's two tables on another database connection. |
| `retention` | `LARALYZE_RETENTION_DAYS` | `30` | Days of data to keep. Minute-level detail is kept for a day. |
| `path` | `LARALYZE_PATH` | `laralyze` | Where the dashboard lives. |
| `domain` | `LARALYZE_DOMAIN` | none | Serve the dashboard on its own domain. |
| `middleware` | | `['web', Authorize::class]` | Middleware for the dashboard and its card updates. |
| `pages` | | `[]` | Add or hide sidebar pages. See [customization](customization.md). |
| `cards` | | `[]` | Swap built-in cards for your own subclasses. |

## Options every recorder understands

| Option | Meaning |
|---|---|
| `enabled` | `false` turns the recorder off. It then registers no listeners at all. |
| `sample_rate` | Record only a share of events, e.g. `0.1`. Counts are scaled back up. Requests only for now. |
| `threshold` | Milliseconds after which something counts as slow. A number, or a map of regular expressions to numbers with a `default` key: `['#^GET /reports#' => 3000, 'default' => 1000]`. |
| `ignore` | Regular expressions. Matching keys are not recorded. |
| `groups` | Regular expression => replacement, to fold similar keys into one row. The first matching rule wins. |

## Recorders

| Recorder | Env switch | Records | Notes |
|---|---|---|---|
| `Requests` | `LARALYZE_REQUESTS_ENABLED` | Every request by route, status class, duration, slow requests | `threshold` via `LARALYZE_SLOW_REQUESTS_THRESHOLD`. Livewire updates are grouped by component and method. Developer tools (`_boost`, `_debugbar`, `telescope`, `horizon`, `pulse`…) are ignored by default. |
| `Queries` | `LARALYZE_QUERIES_ENABLED` | Every query by SQL, read/write, connection, slow queries with the line that ran them | `threshold` via `LARALYZE_SLOW_QUERIES_THRESHOLD`. Lists like `IN (1, 2, 3)` and literals are folded. `location => false` skips finding the line. |
| `Exceptions` | `LARALYZE_EXCEPTIONS_ENABLED` | Reported exceptions by class and line, handled or unhandled, latest message | Handled means reported with `report()` or `rescue()`. `ignore` matches the class name. |
| `Jobs` | `LARALYZE_JOBS_ENABLED` | Queued, processed, released and failed per queue; wait time; duration and failures per job | `threshold` via `LARALYZE_SLOW_JOBS_THRESHOLD`. |
| `ScheduledTasks` | `LARALYZE_SCHEDULED_TASKS_ENABLED` | Runs, failures and skips per task; last run, its status and the next run | |
| `Commands` | `LARALYZE_COMMANDS_ENABLED` | Runs, duration and failures per Artisan command | Workers and scheduler plumbing are ignored by default. |
| `Cache` | `LARALYZE_CACHE_ENABLED` | Hits, misses, writes, deletes and failures per key group | Numbers and UUIDs in keys are grouped by default (`user:42` → `user:*`). |
| `OutgoingRequests` | `LARALYZE_OUTGOING_REQUESTS_ENABLED` | Calls made with Laravel's HTTP client: status, duration, connections that failed | Ids in paths are grouped. |
| `Mail` | `LARALYZE_MAIL_ENABLED` | Mail sent per mailable, duration, failures | A message that started sending but never finished counts as failed. |
| `Notifications` | `LARALYZE_NOTIFICATIONS_ENABLED` | Per notification and channel: sent, failed, duration | |
| `Logs` | `LARALYZE_LOGS_ENABLED` | Messages per level | `ignore` matches the level, e.g. `'/^debug$/'`. |
| `Users` | `LARALYZE_USERS_ENABLED` | Signed-in users over time, their share of requests, and per user: requests by status, timings, slow requests, queued jobs, exceptions and last seen | Only users the app already loaded are counted, so it never adds a query. See `Laralyze::user()`. |
| `Servers` | `LARALYZE_SERVERS_ENABLED` | CPU, memory and disks, every minute | Runs from your scheduler. `server_name` (`LARALYZE_SERVER_NAME`), `directories` (`LARALYZE_SERVER_DIRECTORIES`, comma separated). |
| `Visits` | `LARALYZE_VISITS_ENABLED` | Page views, unique visitors, visitors right now, devices, systems, browsers, top pages, bots | See below. |

### Visits

```php
Recorders\Visits::class => [
    'enabled' => env('LARALYZE_VISITS_ENABLED', true),
    'except' => ['api/*', 'horizon*', 'telescope*', 'pulse*', '_boost*', '_debugbar*'],
    'groups' => [],
],
```

- Pages are counted by route, e.g. `/posts/{post}`, so tokens in URLs are never stored and the table stays small however many URLs your app has.
- Only successful `GET` requests for pages count: HTML responses and Inertia visits. JSON, prefetches and other methods don't.
- `except` takes path patterns like `$request->is()`.
- Unique visitors are counted once per visitor per day. Signed-in users are counted by id; guests by a hash of the day, your app key, their IP and user agent. The IP address and user agent themselves are never stored.
- "Visitors in the last 5 minutes" keeps one small row per visitor, removed after a day.
- Uniqueness uses your default cache store. With the `array` store it only holds within one process.
