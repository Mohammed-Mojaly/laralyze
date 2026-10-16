# Changelog

Laralyze is in `0.x`. Minor releases (`0.1` → `0.2`) may change things; patch releases (`0.1.1`) only fix them. Each breaking change is listed here with what to do.

## v0.8.0 (unreleased)

- **Alert rules on the dashboard.** A new Alerts page, under Issues, makes rules from what Laralyze already measures: exceptions, requests, jobs, queues, scheduled tasks, logs, AI, database health and servers. Each rule has one condition and at most one filter, all together or each item separately, and picks its channels. It's told once when it starts and once when it's back to normal, after two clean checks, with an optional reminder. The form shows the current value as you type. Rules can be turned off, muted and test-sent. Eight default rules come ready.
- **History and a bell.** Every message is kept with how each channel took it. A failed channel is tried three times, then shows in the History tab and in the bell in the top bar, which also counts what's new since you last opened it.
- **Telegram and webhook channels.** Telegram chats and a JSON webhook join mail, Slack and Discord. The webhook can be signed. Errors never show a channel's URL or token.
- **A heartbeat.** `LARALYZE_HEARTBEAT_URL` is pinged after each check that runs, so a service like healthchecks.io can tell you when the scheduler stops, or when a check is skipped because storage can't be reached.
- **Health:** the dashboard warns when several servers run the scheduler without a shared cache.
- **Upgrade:** nothing to run, and no migration. On the first check after the upgrade, `alerts.rules` in your config becomes three of the default rules, once; after that, rules live on the page. `alerts.every` is no longer used: set a reminder on the rule instead. Making and changing rules needs the new `manageLaralyzeAlerts` gate, which follows `viewLaralyze` unless you define it. The alerts check now runs every minute even without a channel. The new alert keys in `.env` work with a config published before 0.8. `Alerts::enabled()`, `Alerts::check()`, `Alerts::lastFailure()` and the `Alerts\Alert` class are removed, and so is the "Alerts couldn't be delivered" Health warning: the bell shows failed deliveries now.

## v0.7.7 - 2026-10-13

- **Fixed:** the User filter on the Logs page is a menu like the Level one, in the dashboard's colors, instead of the browser's own list, which looked out of place in dark mode. With more than six users it has a search box.

## v0.7.6 - 2026-10-13

- **An easier Logs page.** Warnings and worse have a bar in their color, and each level is a badge. The message gets the room, in a plain font, with where it was written under it. Entries with context or an exception say so in their row. The Level menu picks "Warning and above" or "Error and above" in one click. An open entry shows its context as rows, nested values as colored JSON, and the whole message only when its row cuts it. The message and context can be copied.

## v0.7.5 - 2026-10-13

- **Timelines on more pages.** A query's page now lists the requests, jobs and commands that ran it, slowest first, each opening its timeline. An outgoing request's page lists the ones that called it.
- **Scheduled tasks have a page each.** Click a task to see its runs, failures, how long it takes, its schedule and next run, and its timelines. A task that runs an artisan command lists that command's runs. Closures and jobs the scheduler runs are now kept as timelines of their own, with their queries, outgoing requests, exceptions and the rest; a failed one keeps its exception.
- **Fixed:** Ask AI no longer shortens PHP's time limit. In a command or test with no limit, one answer set it to five minutes, which could end a long run.
- **Upgrade:** run `php artisan migrate` (it adds one column). Until you do, timelines are still kept, without the new lists. On ClickHouse the column is added by itself. Only timelines kept after the upgrade appear in the new lists.

## v0.7.4 - 2026-10-12

- **Ask AI knows the database's health.** Ask AI on the Database health page reads what the page shows: the last look at each connection, the incidents with the sessions at their worst and both sides of each deadlock, deadlocks the database counted, errors by kind, size, growth and ids running out. It starts with questions like "Why did the last deadlock happen, and how do I stop it?". From any other page it reads the same when a question is about the database. Deadlocks and locks on Laralyze's own tables are told apart, so it doesn't send you to change your app for them.

## v0.7.3 - 2026-10-12

- **Upgrades need only `php artisan migrate`.** Laralyze's migrations now run from the package, so there's nothing to copy into your app, and `laralyze:install` is only for the first install. On ClickHouse, `php artisan migrate` creates or updates Laralyze's tables there, and adds none to your app's database. Migrations an earlier version copied into your app keep running from there, never twice. To keep them with your own instead, publish them with `php artisan vendor:publish --tag=laralyze-migrations`.
- **Upgrade:** nothing to do. Your next `php artisan migrate` is all upgrades need from now on.

## v0.7.2 - 2026-10-12

- **Database health on SQL Server.** The live checks, the sessions at work, incidents and ids running out now work on SQL Server too: connections, queries running long, who waits for whose lock, transactions left open, restarts, and every deadlock with both sides, their queries and locks, and which was rolled back, read from the `system_health` session SQL Server keeps by default. SQL Server writes a deadlock's details a minute or two after counting it, so its deadlocks show two to three minutes late. The live checks need `VIEW SERVER STATE` (or `VIEW SERVER PERFORMANCE STATE` on 2022); without it the page says so. On Azure SQL Database the page shows errors, size and ids. Nothing to install. See [Database health](docs/database.md).
- **Fixed:** SQL Server's Unicode literals (`N'…'`) are taken out of queries like any other value, instead of showing as `N?`.

## v0.7.1 - 2026-10-11

- **Database health on PostgreSQL.** The live checks, the sessions at work, incidents and ids running out now work on PostgreSQL too: connections against the ones the app may open, queries running long, who waits for whose lock, transactions left idle and open, restarts, and deadlocks as PostgreSQL counts them. PostgreSQL writes a deadlock's details only to its log, so deadlocks come without details. Nothing to install; with `pg_read_all_stats` the page also sees other users' sessions. See [Database health](docs/database.md).

## v0.7.0 - 2026-10-11

- **Database health page.** How the database itself is doing: connections in use against the limit, queries running over 30 seconds, who waits for whose lock, transactions left open, restarts, errors by kind (deadlocks, lock timeouts, refused and lost connections), size and growth, the largest tables, and auto-increment ids running out. The sessions at work are listed live, longest first, with who each one waits for. Every problem is kept as an incident after it's over, with the sessions at its worst moment; a deadlock keeps both transactions' queries and locks. Deadlocks are counted as the database counts them, including the ones Laravel retried. It only reads, and needs nothing installed on the database; a check your app's user may not run is left out, and the page says which privilege it needs. Errors, size and growth work on every database; the live checks on MySQL and MariaDB for now. Problems show in Needs attention. It sits in a new Database section of the sidebar, with Queries. See [Database health](docs/database.md).
- **Queries by connection.** Each database connection now keeps its own rows: the same SQL on two connections is two rows, not one. When your app uses more than one connection, the Queries page has a Connection menu at the top that filters the queries and slow queries, and each row shows its connection. A query's page shows its connection too. Queries recorded before the update have no connection; they show under All until they age out.
- **Fixed:** Ask AI on a page that isn't about one issue opens a new conversation, instead of the last one about the whole app, which could be about anything. On an exception, query or route it still picks up that one's conversation. Older conversations stay on the Assistant page.
- **Changed:** `Storage::aggregate()` takes an optional sixth argument, `$keyPrefix`, which keeps only the keys that start with it. Only code that implements Laralyze's storage needs to add it. Query keys are now `["connection","sql"]` and slow query keys `["connection","sql","file:line"]`; `Queries::parts()` and `Queries::slowParts()` read both, old and new.

## v0.6.2 - 2026-10-08

- **Security:** Ask AI no longer follows instructions hidden in what your app recorded. Anyone who could make the app throw or log a message could write text for the assistant, and it would obey it, for example greeting with given words or showing its own instructions. What the app recorded and what the tools return now reach the model as data, apart from its rules; text that talks to an AI assistant is pointed out as a possible prompt injection; and the assistant answers only questions about your app, in the language you write in. Answers no longer show images, which load by themselves and could carry data to another server, and their links go only to laravel.com, php.net or your app; others show as plain text. Update soon if you use Ask AI.

## v0.6.1 - 2026-10-08

- **Fixed:** the dashboard's own requests no longer count toward your app's numbers. Livewire's checks on each card update ran before Laralyze knew the request was its own, so the Cache page showed a `livewire-checksum-failures:*` key that came from the dashboard polling.
- The Cache keys table sorts by any column, and has a Total column, which it sorts by at first.

## v0.6.0 - 2026-10-08

- **Upgrade:** run `php artisan laralyze:install`. It adds one migration, for the new `laralyze_logs` table, and runs it. Until you do, logs are only counted, and the dashboard says so. ClickHouse creates the table by itself.
- **Logs you can read.** The Logs page now lists entries at `info` and above: search their messages, pick levels and a user, and open one in place for its context, the request or job that wrote it, and its exception. Nothing to set up: Laralyze reads every log Laravel writes, whatever the channel. `LARALYZE_LOGS_LEVEL` sets the lowest level kept (`debug` keeps everything). Context keys like `password` or `token` are masked. At most 200 entries per web request; commands and jobs write early instead of dropping any. See [Known limits](docs/limits.md).
- **Changed:** `Storage::store()` and `Ingest::write()` take the log entries as a fourth argument, and `Storage` has four new methods for them. Only code that implements or extends Laralyze's storage needs to change.

## v0.5.3 - 2026-10-07

- **Fixed:** a middleware alias in `laralyze.middleware` (like `'admin'`) now also runs on the cards' Livewire updates, not only on page loads. Livewire compares class names, so the alias never matched. Middleware groups like `web` are still left out.
- Docs: how much the database digest can take each minute, and when to switch to ClickHouse.

## v0.5.2 - 2026-10-07

- **Fixed:** a Livewire update is now named after the component Livewire actually loaded, never after the request body. A forged update with a huge component name could stop the digest on MySQL and MariaDB.
- **Fixed:** keys and run names are cut to 4 KB, types to 64 bytes, servers to 128 and user ids to 64, so every database column takes them.
- **Fixed:** batches the digest couldn't store are dropped after a day instead of after the retention period.
- **Fixed:** a scheduler stopped during a digest no longer blocks the next one for a day; the lock expires after five minutes.
- Docs: how to keep Laralyze out of your app's tests.

## v0.5.1 - 2026-10-07

- A separate permission for Ask AI: `Gate::define('useLaralyzeAssistant', ...)` decides who may make the assistant read your code and send it to your AI provider. By default it's whoever may view the dashboard, so nothing changes unless you define it. Denied, the Ask AI button and the Assistant page are hidden, and the assistant refuses every action.
- SECURITY.md describes what the assistant reads, masks and sends.
- **Faster:** the last 24 hours now read hour buckets instead of minute buckets, 24 rows per key instead of 1,440. On MySQL with a day of busy data, the routes card went from about 1.2 s to the 50 ms the 7-day range takes. Its charts show one bar per hour, and the range starts at the top of the hour, like the longer ones.
- **Fixed:** the dashboard's Needs attention and AI cards showed up empty when they had nothing to show, instead of staying hidden.

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
