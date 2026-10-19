# Alerts

Make rules on the dashboard's **Alerts** page. Each rule watches one thing Laralyze already measures, and tells your channels once when it starts and once when it's back to normal. Every message is kept in the History tab with how each channel took it, and the bell in the top bar shows the latest ones.

## Getting started

1. Set at least one channel in `.env` (see below). Without one, alerts are only kept on the page and in the bell.
2. Make sure your scheduler runs (`* * * * * php artisan schedule:run` in cron, or `php artisan schedule:work`). Rules are checked from it.
3. Open the Alerts page. The default rules are already on; the ones under "Ready to turn on" need one click.
4. Use "Send a test" in a rule's ⋯ menu to see a message reach your channel.

To make your own rule, press **New rule** and answer three things: what to watch, when to alert and where to send it.

## Channels

Channels come from `.env`; the dashboard never asks for a URL or a token.

```env
LARALYZE_ALERTS_MAIL=ops@example.com,cto@example.com
LARALYZE_ALERTS_SLACK_WEBHOOK=https://hooks.slack.com/services/...
LARALYZE_ALERTS_DISCORD_WEBHOOK=https://discord.com/api/webhooks/...
LARALYZE_ALERTS_TELEGRAM_TOKEN=123456:ABC...
LARALYZE_ALERTS_TELEGRAM_CHATS=-1001234567890,42
LARALYZE_ALERTS_WEBHOOK_URL=https://example.com/hooks/laralyze
LARALYZE_ALERTS_WEBHOOK_SECRET=a-long-random-string
```

Each mail address and each Telegram chat is a channel of its own, so a rule can pick some of them. A rule can also pick "every channel set in .env", which includes channels you add later.

The webhook gets a JSON POST:

```json
{
    "event": "fired",
    "rule": {"id": "01K8Z3...", "name": "Failed jobs"},
    "title": "Failed jobs: App\\Jobs\\Import 12 (>= 10)",
    "items": [{"item": "App\\Jobs\\Import", "value": "12"}],
    "more": 0,
    "text": "",
    "note": "Check the import API first.",
    "url": "https://example.com/laralyze/alerts?tab=history&rule=01K8Z3...",
    "at": 1792368450
}
```

`event` is `fired`, `recovered`, `reminder` or `test`. `items` lists up to 5 items and `more` counts the rest; `text` carries more detail when there is some. With a secret set, it also gets `X-Laralyze-Timestamp` and `X-Laralyze-Signature`, an HMAC-SHA256 of `{timestamp}.{body}`:

```php
$expected = hash_hmac('sha256', $request->header('X-Laralyze-Timestamp').'.'.$request->getContent(), $secret);

abort_unless(hash_equals($expected, (string) $request->header('X-Laralyze-Signature')), 403);
abort_if(abs(time() - (int) $request->header('X-Laralyze-Timestamp')) > 300, 403);
```

## Rules

A rule is one metric, one condition and one filter at most. The form asks three things: what to watch, when to alert and where to send it; the rest is under "More options". A rule left without a name is named after what it watches, and a sentence above Save says what the rule will do.

- **Watch:** one of the metrics below. Only areas whose recorder is on are offered.
- **Condition:** above, at least, below, at most, exactly or not a threshold. Counts, durations and rates are taken over 5 minutes, 15 minutes, an hour or a day; a few are read as they are now. The form shows the current value as you type it.- **Only:** one dimension, like a route, a job class, a queue, a user or a model. Text filters compare without case.
- **Several items:** "all together" checks everything the filter matches as one; for durations and percentages that's the highest item. "Each separately" gives each item its own state, and one message lists up to 5 of them.
- **Remind** while it's firing: never (default), every hour, 6 hours or day.
- **Title and note:** the title may use `{rule}`, `{item}`, `{value}` and `{threshold}`. The note goes with every message, e.g. what to check first.

### What a rule can watch

| Area | Metric | Condition | Only |
| --- | --- | --- | --- |
| Exceptions | New, or back after it was resolved | Each one, once | Exception class, message |
| Exceptions | Occurrences | Over a window | Exception class |
| Requests | Error rate (%) | Over a window, from 20 requests | Route |
| Requests | 5xx responses, 4xx responses | Over a window | Route |
| Requests | Duration p95 | Over a window | Route |
| Jobs | Failed | Over a window | Job class |
| Jobs | Duration p95 | Over a window | Job class |
| Queues | Wait time | Over a window | Queue |
| Queues | Waiting (jobs in the queue) | Now | Queue |
| Scheduled tasks | Failed, Overdue | Each one, once | Task |
| Logs | Entries | Over a window | Level, message |
| AI | Cost, Tokens | Over a window | Model, agent, user |
| AI | Failed calls | Over a window | Model, agent |
| Database health | A problem | Each one, once | Connection, kind |
| Servers | CPU, Memory (%) | Over a window | Server |
| Servers | Disk (%), Stopped reporting (minutes) | Now | Server |

AI metrics need laravel/ai. "Each one, once" rules have no threshold: each exception, failed or overdue task or database problem is told once.

A rule fires when its condition is true, and is back to normal after two looks in a row where it's false. Too little data, like fewer than 20 requests for the error rate, counts as false, so missing data never fires anything. Exceptions, failed or overdue scheduled tasks and database problems are told once each, with no "back to normal".

Rules can be turned off, muted for an hour, a day or until unmuted, and test-sent. A muted rule isn't checked and sends nothing; when the mute ends it carries on where it was. Laralyze keeps up to 100 rules.

## Default rules

On the first check, or the first visit to the Alerts page, Laralyze makes these rules, sent to every channel set in `.env`:

1. A new exception, or a resolved one that happens again.
2. Error rate over 5% over 5 minutes.
3. 10 or more failed jobs over 5 minutes.
4. A database problem: blocking, connections near the limit, a deadlock or a restart. Long-running queries and open transactions are only warnings, routine in some apps: add a rule for them if you want one.
5. A scheduled task failed.
6. A server stopped reporting for 5 minutes.
7. A disk 90% full.

And these, ready but turned off, to show what else a rule can watch. Turn one on as it is, or edit it first:

- Slow requests: p95 over 1 second over 5 minutes.
- 100 or more jobs waiting in a queue.
- 10 or more errors in the logs over 5 minutes.
- A scheduled task overdue.
- CPU over 90% over 15 minutes, each server separately.
- AI cost over $10 a day.

The old `alerts.rules` config sets the first three, once: a rule turned off there is made turned off. After that, the page is the only place for rules, and a deleted default doesn't come back. `alerts.every` is no longer used: set a reminder on the rule instead.

## Who can change rules

Everyone who may see the dashboard sees the page, the history and the bell. Making, changing, muting, deleting and test-sending need the `manageLaralyzeAlerts` gate, which by default follows `viewLaralyze`:

```php
Gate::define('manageLaralyzeAlerts', fn ($user) => $user->isAdmin());
```

## Delivery

Rules are checked every minute from your scheduler; rules with a 24-hour window are checked every five minutes. Each channel gets 5 seconds. A channel that fails is tried again on the next checks, three times in all, then the History tab and the bell show it with the error. A check stops sending after about 30 seconds so it never holds up the scheduler; the rest go out on the next one. Messages are kept for `LARALYZE_RETENTION_DAYS` days (30).

## Several servers

Every server may run the scheduler; one checks and sends, and a check never overlaps itself, which needs a cache they share (redis, database or memcached). With two servers reporting and a `file` or `array` cache, the dashboard warns you.

## When the scheduler stops

Alerts can't tell you their own scheduler stopped. Set a heartbeat URL, from a service such as healthchecks.io, and Laralyze pings it after each check that runs; the service tells you when the pings stop, including when a check is skipped because Laralyze's storage can't be reached:

```env
LARALYZE_HEARTBEAT_URL=https://hc-ping.com/your-uuid
```

Laravel's own `->pingOnSuccess($url)` on any scheduled task works too.

## Good to know

- "Overdue" only catches a task skipped while the scheduler runs. A task counts as overdue when it's more than a minute past its next run plus the length of its last run, so a long task isn't flagged while it's still running.
- "Stopped reporting" needs a second server: on a single server, its own scheduler is what stopped. Use the heartbeat there.
- A queue's wait time is recorded when a worker picks a job up, so it can't see a stopped worker. Use "Waiting", which asks the queue how many jobs it holds.
- When a queue can't be read, say its backend is down, the "Waiting" rule is skipped for that check: it neither fires nor recovers. The error goes to your app's exception handler.
- A message filter on logs reads the stored entries at error and above, up to 1,000 per check.
- Event rules (exceptions, failed or overdue tasks, database problems) look back an hour, so a new or unmuted rule may tell you what happened in the last hour.
