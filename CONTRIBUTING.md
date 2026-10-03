# Contributing

Thanks for helping. Bug reports, ideas and pull requests are welcome.

## Before you start

For anything bigger than a small fix, open an issue first, so we can agree on the approach before you spend time on it. Laralyze aims to stay simple: one install command, no extra services, and a small cost per request.

## Setup

```bash
git clone https://github.com/Mohammed-Mojaly/laralyze.git
cd laralyze
composer install
```

## Checks

```bash
composer test      # Pest (SQLite by default)
composer analyse   # Larastan
composer lint      # Pint: run it before you push
composer bench     # overhead benchmark, with and without Laralyze
```

Run the storage tests against another database with `LARALYZE_TEST_DB=mysql|mariadb|pgsql|sqlsrv` (credentials via `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, and database `laralyze_test`). For SQL Server with Windows authentication, leave `DB_USERNAME` empty.

`composer bench -- --compare` fails when Laralyze adds more than max(0.5 ms, 1%) to a typical request before the response is sent. If your change touches what runs on every request, query or event, run it.

## Pull requests

- Open them against `main`.
- Add a test for what you fix or add.
- Keep each one to a single change, and describe what it does and why.
- Add a line under `Unreleased` in [CHANGELOG.md](CHANGELOG.md).
