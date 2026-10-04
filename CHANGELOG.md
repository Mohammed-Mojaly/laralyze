# Changelog

Laralyze is in `0.x`. Minor releases (`0.1` → `0.2`) may change things; patch releases (`0.1.1`) only fix them. Each breaking change is listed here with what to do.

## Unreleased

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
