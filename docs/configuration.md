# Configuration

Everything lives in `config/laralyze.php`, published by `php artisan laralyze:install`. Most settings also have an environment variable.

## General

| Key | Env | Default | What it does |
|---|---|---|---|
| `enabled` | `LARALYZE_ENABLED` | `true` | Turns Laralyze off completely: no listeners, no routes, no work. |
| `storage.driver` | `LARALYZE_STORAGE` | `database` | `database`, or `clickhouse` to keep the data in [ClickHouse](#clickhouse). |
| `storage.connection` | `LARALYZE_DB_CONNECTION` | default connection | Keeps Laralyze's tables on another database connection. |
| `ingest.driver` | `LARALYZE_INGEST` | `database` on MySQL, MariaDB, PostgreSQL and SQL Server; `direct` on SQLite | `database`: each request, job and command adds one row to `laralyze_ingest`, and the scheduler merges them every minute, so concurrent writes never lock each other; the dashboard is up to a minute behind. `direct`: each writes to Laralyze's tables itself. ClickHouse always writes directly. See [how writes reach the database](runtime.md#how-writes-reach-the-database). |
| `ingest.lottery` | | `[1, 500]` | Odds that a write runs the digest itself when it hasn't run for three minutes (no scheduler). |
| `retention` | `LARALYZE_RETENTION_DAYS` | `30` | Days of data to keep. Minute-level detail is kept for a day. |
| `buffer` | `LARALYZE_BUFFER` | `5000` | Distinct metrics one request, job or command holds before writing. A web request that fills it drops the rest, and the dashboard warns. |
| `alerts.mail` / `.slack` / `.discord` | `LARALYZE_ALERTS_MAIL`, `LARALYZE_ALERTS_SLACK_WEBHOOK`, `LARALYZE_ALERTS_DISCORD_WEBHOOK` | none | Where alerts go. Mail takes comma-separated addresses. Alerts are off until one is set. |
| `alerts.every` | `LARALYZE_ALERTS_EVERY` | `60` | Minutes before the same alert is sent again. |
| `alerts.rules` | | exceptions, 5% errors, 10 failed jobs | `exceptions` (new or reopened), `error_rate` (percent of 5xx over 5 minutes, at least 20 requests), `failed_jobs` (count over 5 minutes). `null` or `false` turns one off. |
| `path` | `LARALYZE_PATH` | `laralyze` | Where the dashboard lives. |
| `domain` | `LARALYZE_DOMAIN` | none | Serve the dashboard on its own domain. |
| `middleware` | | `['web', Authorize::class]` | Middleware for the dashboard and its card updates. |
| `pages` | | `[]` | Add or hide sidebar pages. See [customization](customization.md). |
| `cards` | | `[]` | Swap built-in cards for your own subclasses. |

### ClickHouse

Recommended for medium and large apps. Your app's database is fine while traffic is small; with steady traffic, ClickHouse keeps Laralyze's writes off your database and its pages fast. See [ClickHouse](../README.md#clickhouse).

```php
'storage' => [
    'driver' => env('LARALYZE_STORAGE', 'database'),
    'clickhouse' => [
        'url' => env('LARALYZE_CLICKHOUSE_URL', 'http://127.0.0.1:8123'),
        'database' => env('LARALYZE_CLICKHOUSE_DATABASE', 'default'),
        'username' => env('LARALYZE_CLICKHOUSE_USERNAME', 'default'),
        'password' => env('LARALYZE_CLICKHOUSE_PASSWORD', ''),
        'timeout' => (float) env('LARALYZE_CLICKHOUSE_TIMEOUT', 3),
        'connect_timeout' => (float) env('LARALYZE_CLICKHOUSE_CONNECT_TIMEOUT', 1),
        'wait' => (bool) env('LARALYZE_CLICKHOUSE_WAIT', true),
    ],
],
```

- `url`: ClickHouse's HTTP interface, `http://…:8123` or `https://…:8443`. Laralyze talks to it over HTTP; no PHP extension needed.
- `database`, `username`, `password`: the credentials go in request headers, never in the URL, so they stay out of access logs.
- `timeout`: seconds a write may take. When ClickHouse doesn't answer in time, recording pauses for a minute and the dashboard says why.
- `wait`: wait until ClickHouse has saved each write. On by default, so failed writes are noticed. See [Octane and high traffic](runtime.md#clickhouse-in-production) before turning it off.
- `connect_timeout`: seconds to connect, TLS handshake included. 1 by default; raise it only for a distant server.
- The database must exist: `CREATE DATABASE laralyze`. Then `php artisan laralyze:install` creates the tables, and is safe to run again; run it after upgrading Laralyze.
- A `config/laralyze.php` published before ClickHouse support has no `driver` or `clickhouse` keys, so the `.env` variables aren't read: copy the `storage` block from the package's config file into yours. `laralyze:install` stops and says so.

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
| `Exceptions` | `LARALYZE_EXCEPTIONS_ENABLED` | Reported exceptions by class and line, handled or unhandled, users affected, and the latest occurrence: stack trace, code around app frames, source, server and versions | Handled means reported with `report()` or `rescue()`. `ignore` matches the class name. |
| `Jobs` | `LARALYZE_JOBS_ENABLED` | Queued, processed, released and failed per queue; wait time; duration and failures per job | `threshold` via `LARALYZE_SLOW_JOBS_THRESHOLD`. |
| `ScheduledTasks` | `LARALYZE_SCHEDULED_TASKS_ENABLED` | Runs, failures and skips per task; last run, its status and the next run | |
| `Commands` | `LARALYZE_COMMANDS_ENABLED` | Runs, duration and failures per Artisan command | Workers and scheduler plumbing are ignored by default. |
| `Cache` | `LARALYZE_CACHE_ENABLED` | Hits, misses, writes, deletes and failures per key group | Numbers and UUIDs in keys are grouped by default (`user:42` → `user:*`). |
| `OutgoingRequests` | `LARALYZE_OUTGOING_REQUESTS_ENABLED` | Calls made with Laravel's HTTP client: status, duration, connections that failed | Ids in paths are grouped. |
| `Ai` | `LARALYZE_AI_ENABLED` | Calls made with [`laravel/ai`](https://github.com/laravel/ai) 0.6 or later: agents, embeddings, images, audio, transcriptions and reranking, with tokens, estimated cost, duration and failures, per agent, model and user | Only when `laravel/ai` is installed. Prompts and responses are never recorded. `ignore` matches the agent class. See below. |
| `Mail` | `LARALYZE_MAIL_ENABLED` | Mail sent per mailable, duration, failures | A message that started sending but never finished counts as failed. |
| `Notifications` | `LARALYZE_NOTIFICATIONS_ENABLED` | Per notification and channel: sent, failed, duration | |
| `Logs` | `LARALYZE_LOGS_ENABLED` | Messages per level | `ignore` matches the level, e.g. `'/^debug$/'`. |
| `Users` | `LARALYZE_USERS_ENABLED` | Signed-in users over time, their share of requests, and per user: requests by status, timings, slow requests, queued jobs, exceptions and last seen | Only users the app already loaded are counted, so it never adds a query. See `Laralyze::user()`. |
| `Servers` | `LARALYZE_SERVERS_ENABLED` | CPU, memory and disks, every minute | Runs from your scheduler. `server_name` (`LARALYZE_SERVER_NAME`), `directories` (`LARALYZE_SERVER_DIRECTORIES`, comma separated). |
| `Traces` | `LARALYZE_TRACES_ENABLED` | Single requests, jobs and commands with their queries, cache calls, outgoing requests, mail, notifications, queued jobs, logs, exceptions and AI calls with the tools they used, in order | Every execution is collected in memory while it runs; `sample_rate` (`LARALYZE_TRACES_SAMPLE_RATE`, 0.1) decides which are kept, not which are collected. Slow (`threshold`), failed and throwing ones are always kept. Kept `keep_days` (`LARALYZE_TRACES_DAYS`, 7). Up to `max_events` (500) events each. Long-running commands like `queue:work` aren't traced themselves; their jobs are. Also flags possible N+1 queries (the same read 5+ times with other values) and duplicate queries in every execution, sampled or not, for the Findings page. |
| `Visits` | `LARALYZE_VISITS_ENABLED` | Page views, unique visitors, visitors right now, devices, systems, browsers, top pages, bots | See below. |

A recorder added in a newer release is turned on even if you published `config/laralyze.php` before it existed, just like in a fresh install. Turn it off with `'enabled' => false`.

### AI

```php
Recorders\Ai::class => [
    'enabled' => env('LARALYZE_AI_ENABLED', true),
    'prices' => [
        // 'my-fine-tuned-model' => ['input' => 0.30, 'output' => 1.20],
    ],
    'ignore' => [],
],
```

- Each call is grouped by its agent class. Calls without an agent are grouped by what they do: Embeddings, Images, Audio, Transcription, Reranking.
- A call that throws counts as failed, for its agent and for its model.
- **Cost is an estimate:** the tokens of each call times its model's price. Prices come from [OpenRouter's model list](https://openrouter.ai/api/v1/models), which shows what each provider charges, with nothing added. Cached input is charged at the cache price when the model has one. Things priced per image, per second of audio or per search are not counted.
- **Prices ship with Laralyze** and are updated with each release. To get today's prices without upgrading, run:

  ```bash
  php artisan laralyze:ai-prices
  ```

  It saves them in Laralyze's tables, so every server and worker uses them within an hour. They are removed with other old data after the retention period, and the shipped prices apply again. To keep them fresh, schedule it:

  ```php
  Schedule::command('laralyze:ai-prices')->weekly();
  ```

- **Your own prices** go under `prices`, in USD per million tokens, keyed by model (`gpt-4o-mini`) or provider and model (`azure/gpt-4o-mini`). They win over everything else. `cache_read` and `cache_write` are optional. Use them for fine-tuned or self-hosted models, or a discount you negotiated. Models with no known price show "price unknown" and are left out of the cost.
- Models running on Ollama are free. Calls through OpenRouter are priced at OpenRouter's rates; OpenRouter adds its own fee when you buy credits, which isn't included.
- A model's price is found under the provider's own name for it: `claude-haiku-4-5-20251001` from Anthropic and `anthropic/claude-haiku-4.5` from OpenRouter are the same model. Models from Groq and other hosts are matched by name, when only one vendor has a model of that name. Azure deployments are matched as OpenAI models when named after one.
- Costs are recorded when the call happens. A price you add later applies to new calls only.

### Ask AI

```php
'assistant' => [
    'enabled' => env('LARALYZE_ASSISTANT_ENABLED', true),
    'provider' => env('LARALYZE_ASSISTANT_PROVIDER'),
    'model' => env('LARALYZE_ASSISTANT_MODEL'),
    'stream' => env('LARALYZE_ASSISTANT_STREAM', true),
    'paths' => ['app', 'routes', 'config', 'database', 'resources', 'tests', 'composer.json'],
],
```

- Needs `laravel/ai` 1.0 or later, and a provider with an API key in `config/ai.php` (or Ollama as your default provider). Without one, the chat explains how to add it.
- **What is sent**, only when someone asks: the question, the conversation so far, what Laralyze recorded about the subject (the exception and its stack trace, the query and its timings, the request's timeline…), and the code the assistant reads.
- **Reading code**: only files inside `paths`, from your project's root, up to 300 lines at a time. Never `.env` files, keys and certificates, credentials, `auth.json`, `storage`, `vendor`, `node_modules` or `.git`, whatever `paths` says. Values assigned to names like `password`, `secret`, `key` or `token` are shown as `***`, in reads and in searches. It also reads Laralyze's own data: routes, exceptions, findings, queries, jobs, outgoing requests, cache and AI calls.
- **Where**: the Ask AI button in every page's top bar opens a side chat about what the page shows (an exception, a query, a route, one request…) or about the whole app; Findings have their own button. The **Assistant** page has the same chat full width, with your conversations from the last 7 days next to it.
- **Provider and model**: a provider from `config/ai.php` and its model. Empty means your app's default provider (`ai.default`) and that provider's default model. The chat shows which it uses. **The model must support tool calling** (function calling): the assistant reads Laralyze's data and your code through tools, and a model without them can only guess. Current OpenAI, Anthropic and Gemini models support it; with Ollama or OpenRouter, check the model's page (Ollama marks them "tools").
- **Charts**: answers can include charts. The model only names what to draw (a metric, an aggregate like p95, a key, a period); the chart is drawn from what Laralyze recorded, like the dashboard's, so its numbers are never made up. Comparisons the model already has, like cost per model, show as a ranking.
- **Streaming**: answers appear as they're written, and the chat says what the assistant is reading meanwhile. If your server holds responses back until they're complete (some proxies or compression settings do), the answer simply appears at the end; set `LARALYZE_ASSISTANT_STREAM=false` to always wait for it.
- **Conversations** are kept per person in `laralyze_values` for 7 days. Asking again about the same exception opens its latest conversation, from the side chat or the Assistant page; nothing is asked until you pick a suggested question or write one. "New conversation" starts another and keeps the old one. Each conversation has its own link (`/laralyze/assistant?chat=…`), which opens it only for the person it belongs to. They never go into laravel/ai's `agent_conversations` tables, so your app's own chat can't show them.
- **Cost**: each answer shows its tokens and estimated cost, and the chat shows the conversation's total. The assistant's calls are not recorded on the AI page or in timelines. They do fire laravel/ai's events; listeners of your own see them with the agent `MohammedMojaly\Laralyze\Assistant\Assistant`.
- Turn it off with `LARALYZE_ASSISTANT_ENABLED=false`.

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
