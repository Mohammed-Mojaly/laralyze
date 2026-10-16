# Alerts

Make rules on the dashboard's **Alerts** page. Each rule watches one thing Laralyze already measures, and tells your channels once when it starts and once when it's back to normal. Every message is kept in the History tab with how each channel took it, and the bell in the top bar shows the latest ones.

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

The webhook gets a JSON POST with the event, the rule, the title, the items, the note and a link. With a secret set, it also gets `X-Laralyze-Timestamp` and `X-Laralyze-Signature`, an HMAC-SHA256 of `{timestamp}.{body}`:

```php
$expected = hash_hmac('sha256', $request->header('X-Laralyze-Timestamp').'.'.$request->getContent(), $secret);

abort_unless(hash_equals($expected, (string) $request->header('X-Laralyze-Signature')), 403);
abort_if(abs(time() - (int) $request->header('X-Laralyze-Timestamp')) > 300, 403);
```

## Rules

A rule is one metric, one condition and one filter at most:

- **Watch:** exceptions, requests, jobs, queues, scheduled tasks, logs, AI, database health or servers. Only areas whose recorder is on are offered.
- **Condition:** a threshold over 5 minutes, 15 minutes, an hour or a day. The form shows the current value as you type it.
- **Only:** one dimension, like a route, a job class, a queue, a user or a model. Text filters compare without case.
- **Several items:** "all together" checks everything the filter matches as one; for durations and percentages that's the highest item. "Each separately" gives each item its own state, and one message lists up to 5 of them.
- **Remind** while it's firing: never (default), every hour, 6 hours or day.
- **Title and note:** the title may use `{rule}`, `{item}`, `{value}` and `{threshold}`. The note goes with every message, e.g. what to check first.

A rule fires when its condition is true, and is back to normal after two looks in a row where it's false. Too little data, like fewer than 20 requests for the error rate, counts as false, so missing data never fires anything. Exceptions, failed or overdue scheduled tasks and database problems are told once each, with no "back to normal".

Rules can be turned off, muted for an hour, a day or until unmuted, and test-sent. Laralyze keeps up to 100 rules.

## Default rules

On the first check after you upgrade, Laralyze makes these rules, sent to every channel set in `.env`:

1. A new exception, or a resolved one that happens again.
2. Error rate over 5% over 5 minutes.
3. 10 or more failed jobs over 5 minutes.
4. A database problem: blocking, connections near the limit, a deadlock or a restart. Long-running queries and open transactions are only warnings, routine in some apps: add a rule for them if you want one.
5. A scheduled task failed.
6. A server stopped reporting for 5 minutes.
7. A disk 90% full.
8. AI cost over a day, turned off until you set the amount.

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
