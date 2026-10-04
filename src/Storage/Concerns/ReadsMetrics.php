<?php

namespace MohammedMojaly\Laralyze\Storage\Concerns;

use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use MohammedMojaly\Laralyze\Metrics\Period;
use stdClass;

/**
 * What every storage does the same way once the rows are read, so each
 * returns the same numbers: aggregate names, percentiles from histogram
 * bins, timeline slots and execution rows.
 */
trait ReadsMetrics
{
    protected const READABLE = ['count', 'sum', 'min', 'max', 'avg'];

    /**
     * About 60 slots over the window, never finer than the stored buckets.
     *
     * @return array{0: int, 1: int, 2: int} [step, first slot, last slot]
     */
    protected function timeline(int $window): array
    {
        $period = Period::forWindow($window);
        $step = (int) (ceil($window / 60 / $period) * $period);
        $now = $this->now();

        return [$step, $now - $window - (($now - $window) % $step), $now - ($now % $step)];
    }

    /**
     * @param  array<string, mixed>  $bins  Aggregate name (h12) => count.
     * @return array<int, float>
     */
    protected function binCounts(array $bins): array
    {
        $counts = [];

        foreach ($bins as $aggregate => $count) {
            $counts[(int) substr((string) $aggregate, 1)] = (float) $count;
        }

        return $counts;
    }

    /**
     * A percentile is read off its histogram bin, which can overshoot the
     * slowest value actually seen.
     */
    protected function capped(?float $percentile, stdClass $row): ?float
    {
        $max = $row->max ?? null;

        return $percentile === null || $max === null ? $percentile : min($percentile, (float) $max);
    }

    /**
     * Split requested names into plain aggregates and percentiles (p95 => 0.95).
     *
     * @param  list<string>  $aggregates
     * @return array{0: list<string>, 1: array<string, float>}
     */
    protected function parseAggregates(array $aggregates): array
    {
        $plain = [];
        $percentiles = [];

        foreach ($aggregates as $aggregate) {
            if (preg_match('/^p(\d{1,2})$/', $aggregate, $matches)) {
                $percentiles[$aggregate] = ((int) $matches[1]) / 100;
            } else {
                $plain[] = $this->assertKnown($aggregate, self::READABLE);
            }
        }

        return [$plain, $percentiles];
    }

    /**
     * @param  list<string>  $allowed
     */
    protected function assertKnown(string $aggregate, array $allowed): string
    {
        if (! in_array($aggregate, $allowed, true)) {
            throw new InvalidArgumentException("Unknown aggregate [{$aggregate}].");
        }

        return $aggregate;
    }

    /**
     * @param  list<string>  $aggregates
     * @return list<string>
     */
    protected function storedAggregatesFor(array $aggregates): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (string $aggregate) => $aggregate === 'avg' ? ['sum', 'count'] : [$aggregate],
            $aggregates,
        ))));
    }

    /**
     * @param  list<string>  $aggregates
     */
    protected function castRow(stdClass $row, array $aggregates): stdClass
    {
        foreach ($aggregates as $aggregate) {
            $value = $row->{$aggregate} ?? null;

            $row->{$aggregate} = $value === null ? null : (float) $value;
        }

        return $row;
    }

    protected function now(): int
    {
        return Date::now()->getTimestamp();
    }

    protected function castExecution(stdClass $row): stdClass
    {
        $row->failed = (bool) $row->failed;
        $row->duration = (float) $row->duration;
        $row->started_at = (int) $row->started_at;
        // Empty means none: ClickHouse has no NULL columns.
        $row->user_id = ($row->user_id ?? '') === '' ? null : (string) $row->user_id;
        $row->job_uuid = ($row->job_uuid ?? '') === '' ? null : (string) $row->job_uuid;
        $row->counts = json_decode((string) $row->counts, true) ?: [];
        $row->meta = json_decode((string) ($row->meta ?? ''), true) ?: [];

        if (property_exists($row, 'events')) {
            $row->events = json_decode((string) $row->events, true) ?: [];
        }

        return $row;
    }
}
