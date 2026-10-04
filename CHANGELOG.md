# Changelog

Laralyze is in `0.x`. Minor releases (`0.1` → `0.2`) may change things; patch releases (`0.1.1`) only fix them. Each breaking change is listed here with what to do.

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
