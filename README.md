# Laralyze

Self-hosted production monitoring for Laravel. A dashboard inside your app shows requests, jobs, queries, exceptions, cache, outgoing HTTP, mail, notifications, logs, users, servers and visits; timelines of single requests and jobs; N+1 and duplicate queries; and alerts by mail, Slack or Discord. Near-zero overhead, your data stays in your database.

> Laralyze is in `0.x`: things may change between minor releases (`0.1` → `0.2`) until `1.0`. Patch releases (`0.1.1`) never break anything. See the [changelog](CHANGELOG.md) before you upgrade.

## Requirements

- PHP 8.3+
- Laravel 12.69.2+ or 13.32+
- Livewire 3.8.3+ or 4.3.4+ (installed for you; your app doesn't need to use it). Earlier releases have a known XSS issue.
- MySQL, MariaDB, PostgreSQL, SQLite or SQL Server

## Installation

```bash
composer require mohammed-mojaly/laralyze
php artisan laralyze:install
```

Then open `/laralyze`. Make sure Laravel's scheduler runs (`* * * * * php artisan schedule:run`): Laralyze uses it for cleanup, server stats and alerts.

## What you get

| Page | Shows |
|---|---|
| Dashboard | Requests, timings, exceptions, queues and slow requests at a glance |
| Requests | Throughput by status class, avg/p95/p99, every route, slow requests. Livewire updates by component |
| Jobs | Queued, processed, released, failed per queue; wait times; every job class; slow jobs |
| Commands, Scheduled Tasks | Runs, failures, durations; last and next run of each task |
| Exceptions | By class and line, handled vs unhandled, users affected, open/resolved/ignored (resolved ones reopen when they happen again); a page per exception with the latest stack trace, the code around your lines, where it happened, and Copy as Markdown |
| Queries | Time spent per query (lists folded), reads vs writes, connections, slow queries with the line that ran them |
| Cache | Hit ratio, hits/misses/writes/deletes/failures per key group |
| Outgoing Requests | Calls to other services, errors and connections that never got a response |
| Mail, Notifications | Sent and failed, per mailable, notification and channel |
| Visits | Visitors right now, page views, unique visitors, devices, systems, browsers, top pages, bots |
| Users | Signed-in users over time, signed-in vs guest requests, and per user: statuses, timings, jobs, exceptions, last seen |
| Logs, Servers | Log levels, CPU/memory/disk |
| Findings | N+1 queries and duplicate queries, found in every request, job and command: the SQL, the line in your code, how often, an example timeline, and the fix (e.g. `->with('author')`) |
| Timelines | Single requests, jobs and commands with everything inside them in order: queries, cache, outgoing requests, mail, notifications, queued jobs, logs and exceptions. Split into stages (bootstrap, middleware, handle, terminating), with time per kind. Jobs link to the request or command that queued them and to their other attempts; commands keep their full command line (secrets hidden). Lists filter by status and speed. Slow, failed and throwing ones are always kept, the rest sampled |

Each page shows the last 15 minutes, hour, 24 hours, 7, 14 or 30 days. Lists can be searched and sorted by any column, and every route, job, command, query and outgoing URL opens a page of its own: calls and outcomes over time, duration (avg and p95), totals, and the SQL formatted and highlighted. Routes, jobs, commands, users and exceptions also list their slowest and latest runs, each opening its timeline. Every recorder can be turned off; a page disappears with its recorder.

## Alerts

Set at least one channel and Laralyze checks every minute from your scheduler:

```env
LARALYZE_ALERTS_MAIL=ops@example.com,cto@example.com
LARALYZE_ALERTS_SLACK_WEBHOOK=https://hooks.slack.com/services/...
LARALYZE_ALERTS_DISCORD_WEBHOOK=https://discord.com/api/webhooks/...
```

You hear about new exceptions, resolved ones that come back, more than 5% of requests failing, and 10 or more failed jobs in 5 minutes. The same alert is sent at most once an hour (`LARALYZE_ALERTS_EVERY`, in minutes). Change the rules in `alerts.rules`.

## Privacy

Laralyze stores counts and timings, not payloads. Timelines keep the SQL (with `?` placeholders), cache keys, outgoing URLs without their query string, and log messages, for 7 days by default. Query bindings, request bodies and cache values are never recorded; SQL is stored with its values replaced by `?`. For each exception, the latest message and stack trace are kept (file, line and function names only, no arguments), with a few lines of your own code around each app frame; for database errors only the kind of error and the SQL with placeholders, since the driver's text repeats the values. Visits never store IP addresses or user agents, and count pages by route; guests are told apart by a hash that changes daily. Keys you choose (cache keys, custom metrics) are stored as given, after grouping; drop anything sensitive with `Laralyze::filter()`.

## Authorization

Outside the `local` environment, nobody can open Laralyze until you allow it:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewLaralyze', function ($user) {
    return $user->isAdmin();
});
```

The gate is checked on the page and on every card update.

## Your own metrics

```php
use MohammedMojaly\Laralyze\Facades\Laralyze;

Laralyze::record('checkout', $plan, $total)->count()->sum()->max();
```

Then show them with a card of your own: `php artisan laralyze:make-card CheckoutFunnel`.

## Documentation

- [Configuration](docs/configuration.md): every setting and recorder
- [Customization](docs/customization.md): pages, cards, recorders, filters, theme
- [Running in production](docs/runtime.md): overhead, scheduler, FPM, Octane, queues, several servers

## Development

```bash
composer test      # Pest (SQLite by default)
composer analyse   # Larastan
composer lint      # Pint
composer bench     # overhead benchmark, with and without Laralyze
```

Run the storage tests against another database with `LARALYZE_TEST_DB=mysql|mariadb|pgsql|sqlsrv` (credentials via `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, and database `laralyze_test`). For SQL Server with Windows authentication, leave `DB_USERNAME` empty: `LARALYZE_TEST_DB=sqlsrv DB_HOST=my-pc DB_USERNAME= composer test`.

`composer bench -- --compare` fails when Laralyze adds more than max(0.5 ms, 1%) to a typical request before the response is sent. Heavier scenarios (1,000 queries, 200 cache calls) are reported, not gated.

## Credits

Created and maintained by [Mohammed Mojaly](https://github.com/Mohammed-Mojaly).

Brand icons for systems, browsers and bots come from [Simple Icons](https://simpleicons.org) (CC0). The trademarks belong to their owners.

## License

MIT. See [LICENSE.md](LICENSE.md).
