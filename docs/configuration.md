# Configuration

Settings live in `config/laralyze.php`, published by `php artisan laralyze:install`. Most have an environment variable.

## General

| Key | Env | Default | What it does |
|---|---|---|---|
| `enabled` | `LARALYZE_ENABLED` | `true` | Turns Laralyze off: no listeners, no routes. |
| `storage.driver` | `LARALYZE_STORAGE` | `database` | `database`, or [`clickhouse`](#clickhouse). |
| `storage.connection` | `LARALYZE_DB_CONNECTION` | default | Keeps Laralyze's tables on another connection. |
| `ingest.driver` | `LARALYZE_INGEST` | `database`; `direct` on SQLite | How writes reach the database. See [runtime](runtime.md#how-writes-reach-the-database). |
| `ingest.lottery` | | `[1, 500]` | Odds that a request runs the digest itself when the scheduler hasn't for three minutes. |
| `retention` | `LARALYZE_RETENTION_DAYS` | `30` | Days of data to keep. Minute detail is kept for a day. |
| `buffer` | `LARALYZE_BUFFER` | `5000` | Distinct metrics one request, job or command holds before writing. |
| `alerts.*` | see [Alerts](#alerts) | off | Mail, Slack or Discord. |
| `path` / `domain` | `LARALYZE_PATH` / `LARALYZE_DOMAIN` | `laralyze` / none | Where the dashboard lives. |
| `middleware` | | `['web', Authorize::class]` | Middleware for the dashboard and its card updates. |
| `pages` / `cards` | | `[]` | Add, hide or replace pages and cards. See [customization](customization.md). |

## Options every recorder understands

| Option | Meaning |
|---|---|
| `enabled` | `false` turns the recorder off; it registers no listeners. |
| `sample_rate` | Record a share of events, e.g. `0.1`. Counts are scaled back up. Requests and Traces. |
| `threshold` | Milliseconds after which something is slow. A number, or patterns with a `default`: `['#^GET /reports#' => 3000, 'default' => 1000]`. |
| `ignore` | Regular expressions; matching keys aren't recorded. |
| `groups` | Pattern => replacement, to fold similar keys into one row. |

## Recorders

| Recorder | Records | Notes |
|---|---|---|
| `Requests` | Requests by route and status class, duration, slow requests | Livewire updates are grouped by component and method. Developer tools (`telescope`, `horizon`, `pulse`, `_boost`…) are ignored. |
| `Queries` | Queries by SQL, connection, slow queries with the line that ran them | Lists and literals are folded. `location => false` skips finding the line. |
| `Exceptions` | Reported exceptions by class and line, users affected, the latest stack trace with code around app frames, and context | Handled means reported with `report()` or `rescue()`. |
| `Jobs` | Queued, processed, released and failed per queue; wait time; duration per job | |
| `ScheduledTasks` | Runs, failures and skips per task; last and next run | |
| `Commands` | Runs, duration and failures per command | Workers and scheduler commands are ignored. |
| `Cache` | Hits, misses, writes, deletes per key group | `user:42` is grouped as `user:*`. |
| `OutgoingRequests` | Calls made with Laravel's HTTP client: status, duration, failed connections | Ids in paths are grouped. |
| `Ai` | Calls made with `laravel/ai`: tokens, estimated cost, duration, failures | See [AI](#ai). |
| `Mail`, `Notifications` | Sent and failed, duration | |
| `Logs` | Messages per level | |
| `Users` | Signed-in users and their requests, jobs and exceptions | Only users the app already loaded, so no extra query. |
| `Servers` | CPU, memory and disks every minute | From the scheduler. `LARALYZE_SERVER_NAME`, `LARALYZE_SERVER_DIRECTORIES`. |
| `Traces` | Single requests, jobs and commands with what happened inside, in order; possible N+1 and duplicate queries | `sample_rate` (`LARALYZE_TRACES_SAMPLE_RATE`, 0.1) decides which are kept; slow, failed and throwing ones always are. `keep_days` (7), `max_events` (500). |
| `Visits` | Page views, visitors, devices, browsers, top pages, bots | Counted by route. IPs and user agents are never stored. `except` takes paths like `$request->is()`. |

Each recorder has an `LARALYZE_<NAME>_ENABLED` switch, e.g. `LARALYZE_QUERIES_ENABLED`.

## AI

If your app uses [`laravel/ai`](https://github.com/laravel/ai) 0.6 or later, the AI page shows each agent, embedding, image, audio and transcription call, per agent, model and user. Prompts and responses are not recorded.

```php
Recorders\Ai::class => [
    'prices' => [
        // 'my-fine-tuned-model' => ['input' => 0.30, 'output' => 1.20],
    ],
],
```

- Cost is an estimate: tokens times the model's price per million tokens, from [OpenRouter's list](https://openrouter.ai/api/v1/models). Things priced per image, per second or per search aren't counted.
- Prices ship with each release. `php artisan laralyze:ai-prices` fetches today's; schedule it weekly to keep them current.
- Your own `prices` win over the rest, keyed by model or `provider/model`. A model with no known price shows "price unknown".
- A price applies to calls made after it was set.

## Ask AI

With `laravel/ai` 1.0 and a provider key, every page has an **Ask AI** button, and the **Assistant** page answers questions about the whole app with charts drawn from your data.

```php
'assistant' => [
    'enabled' => env('LARALYZE_ASSISTANT_ENABLED', true),
    'provider' => env('LARALYZE_ASSISTANT_PROVIDER'),
    'model' => env('LARALYZE_ASSISTANT_MODEL'),
    'stream' => env('LARALYZE_ASSISTANT_STREAM', true),
    'paths' => ['app', 'routes', 'config', 'database', 'resources', 'tests', 'composer.json'],
],
```

- **What is sent**, only when someone asks: the question, the conversation, what Laralyze recorded about the subject, and the code the assistant reads. Nothing is sent otherwise.
- **Code it reads**: files inside `paths`, read-only. Never `.env`, keys, credentials, `storage`, `vendor` or `.git`. Values assigned to names like `password`, `secret`, `key` or `token` show as `***`.
- **Model**: empty means your app's default provider and model. It must support tool calling.
- **Conversations** are kept per person for 7 days, in Laralyze's tables. The assistant's own calls don't appear on the AI page.
- If a proxy holds responses until they're complete, set `LARALYZE_ASSISTANT_STREAM=false`.

## ClickHouse

Laralyze can keep its data in [ClickHouse](https://clickhouse.com) 24.8+ instead of your app's database. With steady traffic, this keeps Laralyze's writes off the database your app depends on.

```env
LARALYZE_STORAGE=clickhouse
LARALYZE_CLICKHOUSE_URL=http://127.0.0.1:8123
LARALYZE_CLICKHOUSE_DATABASE=laralyze
LARALYZE_CLICKHOUSE_USERNAME=laralyze
LARALYZE_CLICKHOUSE_PASSWORD=secret
```

- Create the database (`CREATE DATABASE laralyze`), then run `php artisan laralyze:install`. Run it again after upgrading Laralyze.
- Every page works the same. Switching starts with empty tables; existing data isn't copied.
- Credentials go in request headers, never in the URL.
- `LARALYZE_CLICKHOUSE_TIMEOUT` (3 s) and `LARALYZE_CLICKHOUSE_CONNECT_TIMEOUT` (1 s) limit each write. `LARALYZE_CLICKHOUSE_WAIT` (on) waits until each write is saved, so failures show on the dashboard.
- A `config/laralyze.php` published before 0.3 has no `storage.clickhouse` block: copy it from the package. `laralyze:install` tells you.

See [ClickHouse in production](runtime.md#clickhouse-in-production).

## Alerts

Set at least one channel, and Laralyze checks every minute from the scheduler:

```env
LARALYZE_ALERTS_MAIL=ops@example.com,cto@example.com
LARALYZE_ALERTS_SLACK_WEBHOOK=https://hooks.slack.com/services/...
LARALYZE_ALERTS_DISCORD_WEBHOOK=https://discord.com/api/webhooks/...
```

- `alerts.rules`: `exceptions` (new, or resolved and back), `error_rate` (5% of requests answered 5xx over 5 minutes, at least 20 requests), `failed_jobs` (10 in 5 minutes). `null` or `false` turns one off.
- Each exception is told once. Other alerts repeat at most every `LARALYZE_ALERTS_EVERY` minutes (60).
- An alert no channel took is tried again a minute later, and the dashboard shows the error.
