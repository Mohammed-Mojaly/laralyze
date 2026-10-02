# Laralyze

Self-hosted production monitoring for Laravel. A dashboard inside your app shows requests, jobs, queries, exceptions, cache, outgoing HTTP, mail, notifications, logs, users, servers and visits, with near-zero overhead.

> Laralyze is in early development (heading for v0.1.0-beta). See the roadmap in the project docs.

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

Then open `/laralyze`. Make sure Laravel's scheduler runs (`* * * * * php artisan schedule:run`): Laralyze uses it for cleanup and server stats.

## What you get

| Page | Shows |
|---|---|
| Dashboard | Requests, timings, exceptions, queues and slow requests at a glance |
| Requests | Throughput by status class, avg/p95/p99, every route, slow requests. Livewire updates by component |
| Jobs | Queued, processed, released, failed per queue; wait times; every job class; slow jobs |
| Commands, Scheduled Tasks | Runs, failures, durations; last and next run of each task |
| Exceptions | By class and line, handled vs unhandled, latest message |
| Queries | Time spent per query (lists folded), reads vs writes, connections, slow queries with the line that ran them |
| Cache | Hit ratio, hits/misses/writes/deletes/failures per key group |
| Outgoing Requests | Calls to other services, errors and connections that never got a response |
| Mail, Notifications | Sent and failed, per mailable, notification and channel |
| Visits | Visitors right now, page views, unique visitors, devices, systems, browsers, top pages, bots |
| Users, Logs, Servers | Most active users, log levels, CPU/memory/disk |

Each page shows the last 15 minutes, hour, 24 hours, 7, 14 or 30 days. Lists can be searched and sorted by any column, and every route, job, command, query and outgoing URL opens a page of its own: calls and outcomes over time, duration (avg and p95), totals, and the SQL formatted and highlighted. Every recorder can be turned off; a page disappears with its recorder.

## Privacy

Laralyze stores counts and timings, not payloads. Query bindings, request bodies and cache values are never recorded; SQL is stored with its values replaced by `?`. For each exception, the latest message is kept; for database errors only the kind of error and the SQL with placeholders, since the driver's text repeats the values. Visits never store IP addresses or user agents, and count pages by route; guests are told apart by a hash that changes daily. Keys you choose (cache keys, custom metrics) are stored as given, after grouping; drop anything sensitive with `Laralyze::filter()`.

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

## License

MIT. See [LICENSE.md](LICENSE.md).
