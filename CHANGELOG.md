# Changelog

Laralyze is in `0.x`. Minor releases (`0.1` → `0.2`) may change things; patch releases (`0.1.1`) only fix them. Each breaking change is listed here with what to do.

## Unreleased

- A separate permission for Ask AI: `Gate::define('useLaralyzeAssistant', ...)` decides who may make the assistant read your code and send it to your AI provider. By default it's whoever may view the dashboard, so nothing changes unless you define it. Denied, the Ask AI button and the Assistant page are hidden, and the assistant refuses every action.
- SECURITY.md describes what the assistant reads, masks and sends.

## v0.5.0 - 2026-10-06

- **Changed:** `Laralyze::filter()` also decides which timelines are kept. A request, job or command whose route, class or name it rejects is no longer stored with its timeline, as its metrics already weren't. Check your filters if you relied on the old behaviour.
- **Changed:** values in an exception's context are masked when their key is a name like `password`, `token`, `secret`, `api_key`, `authorization`, `cookie`, `card_number`, `cvv` or `ssn`, at any depth, and strings longer than 500 characters are shortened.
- Secrets in the code stored around a stack frame (a literal assigned to a name like `key`, `secret` or `token`) are masked, as they already were when the assistant reads files.
- Docs: a [Known limits](docs/limits.md) page, and a shorter README and docs.

## v0.4.2 - 2026-10-06

- **Fixed:** the dashboard on phones. The sidebar is now a bar with the page you're on and a menu button that opens every page; card headers wrap their search and filters; long messages wrap inside tables, which scroll sideways. On the Assistant page the conversation list starts closed and closes again when you pick one.
- The sidebar shows where the data lives: the kind of database and its name (e.g. `MySQL · shop`, `ClickHouse · laralyze`), with the connection and how writes reach it on hover. Never credentials.
- The dashboard puts what matters first. A **Needs attention** card, only there when something needs it, lists up to five things worst first, each linking to its page: a server that stopped reporting or has CPU, memory or a disk at 90% or more, the most frequent unhandled open exception, failed jobs, a failed scheduled task, a service that failed 5 times or more, the slowest route over its threshold, and the worst possible N+1.
- The dashboard's Exceptions card shows the open exceptions seen most, without the search and filters, with a link to all of them; its Queues card keeps four columns, with a link to Jobs. Slow jobs sit next to slow requests. The AI card only shows once there are AI calls.
- When no channel takes an alert, the dashboard warns for an hour, with each channel's error, until one does.
- Findings say why each one was flagged ("the same read ran up to 27× in one execution, with different values"), and an N+1 is labelled **Possible N+1**.
- A single request, job or command says why it was kept: it failed, reported an exception, was slow, or was picked by the sample (with its rate). Only for ones recorded from now on.
- The executions list says what the sample rate is, and that the numbers on the other pages count every request, job and command.

## v0.4.1 - 2026-10-06

- **Fixed:** an alert that no channel took (Slack down, a mail error) is no longer marked as sent: it goes out on the next check, a minute later. One channel taking it is enough.
- **Fixed:** a new exception written late, by a long job or through the one-minute ingest queue, could miss its alert. Exceptions first seen in the last hour that haven't been told yet are now alerted, whenever they arrive.
- An exception that comes back after you resolve it is alerted once each time, instead of every hour while it keeps happening.
- **Fixed:** a metric that didn't fit in a full buffer could be counted twice in its minute in commands and workers. A metric now goes into its minute and its hour together, or into neither.
- **Fixed:** `Laralyze::set()` ignored the buffer limit. A full web request now drops a new value and counts it in the dropped-metrics warning; a command writes early to make room.
- **Fixed:** a recorder that filled the buffer while Laralyze was writing could start a write inside the write, and in the worst case recurse until PHP ran out of memory. The buffer is now written early without running the recorders again.
- **Fixed:** with database ingest, a slow request's batch merged after a newer one could overwrite a newer value. The newer one wins.
- **Fixed:** with several servers running the scheduler, each sent the same alert. Alerts now go out from one server when the cache can lock (Redis, Memcached, database, file, DynamoDB).
- **Fixed:** N+1 and duplicate queries written by hand were missed when they started with spaces, a comment, `Select` in another case, or a `WITH` clause.
- A duplicate key in the digest is reported as a failure instead of as lock contention: the digest is the only writer, so it's never a race.
- Docs: the measured overhead instead of "near-zero", "possible" N+1 queries, estimated percentiles, and what the Traces sample rate decides.

## v0.4.0 - 2026-10-06

- **Upgrade:** run `php artisan laralyze:install`. It adds one migration, for the new `laralyze_ingest` table, and runs it. No other step. Until you do, Laralyze keeps writing directly, and the dashboard asks you to.
- **Database ingest, now the default on MySQL, MariaDB, PostgreSQL and SQL Server.** Each request, job and command adds one row to `laralyze_ingest`, a plain insert that never waits on a lock, and the scheduler's new `laralyze:digest` merges them into Laralyze's tables every minute, as their only writer. Busy apps no longer deadlock on Laralyze's tables, and no longer lose or double count what they record. The dashboard is up to a minute behind. `LARALYZE_INGEST=direct` keeps the previous behaviour; SQLite keeps it by default, and ClickHouse is unchanged. See [how writes reach the database](docs/runtime.md#how-writes-reach-the-database).
- Without a scheduler, now and then a request runs the digest itself after its response. The dashboard warns when batches have waited more than five minutes.
- `laralyze:install` now publishes only the migrations an app doesn't have yet.

## v0.3.1 - 2026-10-05

- **Fixed:** deadlocks no longer pause recording or lose data on busy MySQL apps. Under steady traffic, concurrent writes deadlocked often, and each deadlock was treated as a database outage: recording paused for a minute in every process, dropping metrics and queued jobs' runs (about 9% of requests in one test). Each statement is now written on its own, outside a transaction, and retried up to five times on a deadlock, lock wait or racing insert, without ever counting anything twice. A write whose retries all fail is counted in a softer dashboard warning, and recording carries on. Applies to MySQL, MariaDB, PostgreSQL, SQLite and SQL Server; no upgrade step.

## v0.3.0 - 2026-10-05

- **ClickHouse storage**: set `LARALYZE_STORAGE=clickhouse` and the ClickHouse connection variables, then run `php artisan laralyze:install`. Every page works the same. Writes are asynchronous inserts that ClickHouse batches; metrics merge in the background; old data goes by whole days. ClickHouse 24.8 or later. See [ClickHouse in production](docs/runtime.md#clickhouse-in-production).
- Nothing changes for apps on MySQL, MariaDB, PostgreSQL, SQLite or SQL Server, and no step is needed when upgrading. Custom code that resolved `MohammedMojaly\Laralyze\Storage\DatabaseStorage` keeps working; new code should use `MohammedMojaly\Laralyze\Contracts\Storage`.

## v0.2.0 - 2026-10-04

- **AI page**: calls made with `laravel/ai` 0.6 or later (agents, embeddings, images, audio, transcriptions, reranking) with tokens, estimated cost, duration, p95 and failures, per agent, per model and per user. Each agent and model opens a page of its own. Prompts and responses are never recorded. The page appears only when `laravel/ai` is installed.
- AI calls and the tools they used show in timelines, where they started.
- Estimated costs from each model's price per token, from OpenRouter's public model list. Prices ship with Laralyze; `php artisan laralyze:ai-prices` fetches today's, and `prices` in the AI recorder's config sets your own.
- **Ask AI** (with `laravel/ai` 1.0+): a side chat on every page. On an exception, an N+1 or duplicate query, a query, a route or one request, job or command, it already knows what Laralyze recorded and suggests questions to start with (nothing is sent until you pick one or write your own); answers explain the cause and the fix, ending with a prompt for your coding agent when code needs to change. Ask about the whole app too. The model needs tool calling (function calling). It reads your code read-only, never `.env`, keys, storage or vendor, with secrets masked. Answers stream in, and can include charts drawn from your data. An **Assistant** page holds the same chat full width with your conversations, each with a link of its own. It uses your app's default AI provider and model, or `LARALYZE_ASSISTANT_PROVIDER` and `LARALYZE_ASSISTANT_MODEL`. Conversations stay in Laralyze's tables for 7 days, never in laravel/ai's, and aren't counted as your app's AI calls.
- Recorders added in a release are turned on even when `config/laralyze.php` was published before them. Turn one off with `'enabled' => false`.

## v0.1.1 - 2026-10-04

- A warning on the dashboard and in `php artisan about` when jobs are queued but no worker records running them. Workers load Laralyze when they start, so one already running before the install records nothing until it restarts.
- `laralyze:install` and the README remind you to restart queue workers (`php artisan queue:restart` or `horizon:terminate`).
- Findings also catch N+1 and duplicate queries that run while the app boots, before the request, command or job starts (e.g. settings read from the cache again and again).

## v0.1.0 - 2026-10-04

First release.

- Dashboard inside your app at `/laralyze`, one Gate (`viewLaralyze`), local-only by default. Pages are Blade and Livewire you can publish, rearrange and extend.
- Requests, jobs, commands, scheduled tasks, queries, cache, outgoing requests, mail, notifications, logs, servers, users and visits, with avg/p95/p99 and slow lists.
- Exceptions grouped by class and line, handled vs unhandled, users affected, open/resolved/ignored (resolved ones reopen when they happen again). A page per exception with the full message, previous exceptions, context, the stack trace with code around your lines, and Copy as Markdown.
- Timelines of single requests, jobs and commands: every query, cache call, outgoing request, mail, notification, queued job, log and exception in order, split into stages. Jobs link to the request that queued them and to their other attempts. Slow, failed and throwing ones are always kept; the rest sampled.
- Findings: N+1 and duplicate queries with the line in your code and a fix hint.
- Alerts by mail, Slack or Discord for new exceptions, error rate and failed jobs.
- Your own metrics with `Laralyze::record()`, your own cards with `laralyze:make-card`, your own recorders.
- Safe by default: no IP addresses, user agents, request bodies or query bindings stored; writes pause after a failure; health warnings on the dashboard and in `php artisan about`.
- MySQL, MariaDB, PostgreSQL, SQLite and SQL Server. Laravel 12 and 13, Livewire 3 and 4, PHP 8.3+.
