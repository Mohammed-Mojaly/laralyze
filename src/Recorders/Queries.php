<?php

namespace Laralyze\Recorders;

use Illuminate\Database\Events\QueryExecuted;
use Laralyze\Laralyze;
use Laralyze\Metrics\Histogram;
use Laralyze\Support\Location;

/**
 * Times every query. During the request it only adds each duration to a
 * running total per SQL string; fingerprinting and grouping happen after
 * the response is sent.
 */
class Queries extends Recorder
{
    protected array $listen = [QueryExecuted::class];

    /**
     * Durations kept before they are added to the totals. Work done once
     * per query is several times dearer than the same work in a loop, so
     * each query only appends its time.
     */
    protected const FOLD_AT = 1_000;

    /**
     * Distinct raw SQL strings kept per execution before new ones are
     * fingerprinted straight away, so long IN lists can't grow memory.
     */
    protected const MAX_DISTINCT = 500;

    /**
     * [connection][sql] => list of durations
     *
     * @var array<string, array<string, list<float>>>
     */
    protected array $times = [];

    protected int $buffered = 0;

    /**
     * [connection][sql] => [count, sum, max, [bin => count]]
     *
     * @var array<string, array<string, array{0: int, 1: float, 2: float, 3: array<int, int>}>>
     */
    protected array $pending = [];

    protected int $distinct = 0;

    /**
     * @var list<array{connection: string, sql: string, time: float, location: ?string}>
     */
    protected array $slow = [];

    /**
     * The lowest threshold in the config: anything faster is never slow.
     */
    protected float $slowFrom;

    public function __construct(Laralyze $laralyze, array $config = [])
    {
        parent::__construct($laralyze, $config);

        $threshold = $config['threshold'] ?? 1_000;

        $this->slowFrom = is_array($threshold)
            ? ($threshold === [] ? 1_000.0 : (float) min($threshold))
            : (float) $threshold;
    }

    public function record(QueryExecuted $event): void
    {
        $this->times[$event->connectionName][$event->sql][] = (float) $event->time;

        // One check covers both rare cases.
        if (++$this->buffered >= self::FOLD_AT || $event->time >= $this->slowFrom) {
            $this->recordRare($event);
        }
    }

    protected function recordRare(QueryExecuted $event): void
    {
        // The call stack only exists now, so slow queries grab their location
        // here. The exact per-query threshold is checked later.
        if ($event->time >= $this->slowFrom) {
            $this->slow[] = [
                'connection' => $event->connectionName,
                'sql' => $event->sql,
                'time' => (float) $event->time,
                'location' => ($this->config['location'] ?? true) ? Location::here() : null,
            ];
        }

        if ($this->buffered >= self::FOLD_AT) {
            $this->fold();
        }
    }

    /**
     * Add the buffered durations to the totals.
     */
    protected function fold(): void
    {
        foreach ($this->times as $connection => $queries) {
            foreach ($queries as $sql => $times) {
                $sql = (string) $sql;

                if (! isset($this->pending[$connection][$sql]) && ++$this->distinct > self::MAX_DISTINCT) {
                    $sql = $this->fingerprint($sql);
                }

                $entry = $this->pending[$connection][$sql] ?? [0, 0.0, 0.0, []];

                foreach ($times as $time) {
                    $entry[0]++;
                    $entry[1] += $time;

                    if ($time > $entry[2]) {
                        $entry[2] = $time;
                    }

                    $bin = Histogram::bin($time);
                    $entry[3][$bin] = ($entry[3][$bin] ?? 0) + 1;
                }

                $this->pending[$connection][$sql] = $entry;
            }
        }

        $this->times = [];
        $this->buffered = 0;
    }

    public function digest(): void
    {
        $this->fold();

        [$pending, $slow] = [$this->pending, $this->slow];
        $this->pending = [];
        $this->slow = [];
        $this->distinct = 0;

        foreach ($pending as $connection => $queries) {
            foreach ($queries as $sql => [$count, $sum, $max, $bins]) {
                $key = $this->fingerprint($sql);

                if ($this->ignores($key)) {
                    continue;
                }

                $aggregates = ['count' => $count, 'sum' => $sum, 'max' => $max];

                foreach ($bins as $bin => $binCount) {
                    $aggregates['h'.$bin] = $binCount;
                }

                $this->laralyze->merge('query', $key, $aggregates);
                $this->laralyze->merge('query_connection', (string) $connection, ['count' => $count, 'sum' => $sum]);
                $this->laralyze->merge('query_kind', $this->isRead($key) ? 'read' : 'write', ['count' => $count, 'sum' => $sum]);
            }
        }

        foreach ($slow as $query) {
            $key = $this->fingerprint($query['sql']);

            if ($this->ignores($key) || $query['time'] < $this->threshold($key)) {
                continue;
            }

            $this->laralyze->record('slow_query', (string) json_encode([$key, $query['location']]), $query['time'])->count()->max();
        }
    }

    /**
     * "select * from users where id in (?, ?, ?)" and the same query with
     * ten ids become one row.
     */
    public function fingerprint(string $sql): string
    {
        $sql = (string) preg_replace(
            ['/\s+/', "/'(?:[^'\\\\]|\\\\.)*'/", '/\b\d+(?:\.\d+)?\b/', '/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', '/\bvalues\s*\([^)]*\)(?:\s*,\s*\([^)]*\))*/i'],
            [' ', '?', '?', 'in (...)', 'values (...)'],
            trim($sql),
        );

        return mb_strimwidth($this->group($sql), 0, 2_000, '…');
    }

    protected function isRead(string $sql): bool
    {
        return (bool) preg_match('/^\s*\(?\s*(select|with|show|explain|describe|pragma)\b/i', $sql);
    }

    protected function ignores(string $sql): bool
    {
        return str_contains($sql, 'laralyze_aggregates') || str_contains($sql, 'laralyze_values') || $this->shouldIgnore($sql);
    }
}
