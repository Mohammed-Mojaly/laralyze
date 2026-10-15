<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;

/**
 * Something that happened: each one is told once, with no threshold,
 * window, or recovery.
 */
abstract class EventMetric extends Metric
{
    /**
     * How far back something still counts as new: long jobs and the
     * ingest queue write what they recorded minutes later.
     */
    public const LOOKBACK = 3_600;

    public function kind(): string
    {
        return self::EVENT;
    }

    /**
     * What happened in the last hour, each with an id that stays the same
     * from one look to the next.
     *
     * @return list<array{id: string, item: string, text: string, url?: string}>
     */
    abstract public function events(Storage $storage, Rule $rule, int $now): array;

    /**
     * How many per item in the last hour, for the form.
     */
    public function read(Storage $storage, Rule $rule, int $now): array
    {
        $items = [];

        foreach ($this->events($storage, $rule, $now) as $event) {
            $items[$event['item']]['value'] = ($items[$event['item']]['value'] ?? 0.0) + 1;
        }

        return $items;
    }
}
