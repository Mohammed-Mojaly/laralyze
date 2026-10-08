# Known limits

What Laralyze doesn't see, and where its numbers are estimates.

- **Queries** are seen through Laravel's database events. Raw PDO calls and queries made outside Laravel aren't.
- **Values in SQL**: bindings are never stored. A value written into the SQL text itself is kept as written in timelines; the Queries page folds literals and lists.
- **`Laralyze::filter()`** works on metric keys. A route, job or command it rejects loses its timelines too, but it can't remove single events inside a timeline.
- **N+1** is a suspicion: the same read ran 5 or more times in one execution with other values. A duplicate query (same read, same values) can be intentional.
- **p95 and p99** are estimates: values are counted in bins 25% wide.
- **Timelines are a sample.** Slow, failed and throwing ones are always kept; the rest at `LARALYZE_TRACES_SAMPLE_RATE` (0.1). The list isn't a count of traffic; the other pages count every request.
- **Events per timeline**: past `max_events` (500), events are counted but not listed.
- **Log entries**: `info` and above by default (`LARALYZE_LOGS_LEVEL`). At most 200 per web request (commands and jobs write early instead), messages up to 4 KB, and context up to 8 KB, or it's left out; every message is still counted by level. A log entry links to its request or job only while that one's timeline is kept, and with `Traces` off, entries written in jobs and commands don't say which one.
- **Delay**: with database ingest, the dashboard is up to a minute behind.
- **A full buffer**: a web request that records more than `LARALYZE_BUFFER` distinct metrics drops the rest, and the dashboard says so. Commands and jobs write early instead.
- **Long-running commands** (`queue:work`, `horizon`, `octane:start`, `schedule:work`…) aren't traced themselves; their jobs are.
- **Workers** load Laralyze when they start. Restart them after installing or upgrading.
- **Alerts** are checked every minute. Each exception is told once; other alerts repeat at most every `LARALYZE_ALERTS_EVERY` minutes.
- **ClickHouse**: inserts are asynchronous and metrics merge in the background, so a write shows up after its batch is saved.
- **When it writes**: after the response is sent on PHP-FPM and LiteSpeed. Octane: written at the end of each request like elsewhere; anything left unwritten is discarded when the next request starts.
