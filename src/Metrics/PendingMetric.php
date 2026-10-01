<?php

namespace Laralyze\Metrics;

use Laralyze\Laralyze;

/**
 * The fluent part of Laralyze::record(): each call adds one aggregation.
 *
 *     Laralyze::record('request', $route, $duration)->count()->max()->histogram();
 */
final class PendingMetric
{
    /**
     * How many real events this sample stands for.
     */
    private float $weight = 1.0;

    /**
     * count() and avg() both need a count; add it only once.
     */
    private bool $counted = false;

    /**
     * @param  Buffer|null  $buffer  Null when Laralyze isn't recording, which turns every call into a no-op.
     */
    public function __construct(
        private Laralyze $laralyze,
        private ?Buffer $buffer,
        private string $type,
        private string $key,
        private float $value,
        private int $timestamp,
    ) {}

    /**
     * Mark the value as one sample out of many. Call before adding
     * aggregations so counts and sums are scaled back up.
     */
    public function sample(float $rate): self
    {
        $this->weight = $rate > 0 ? 1 / $rate : 1.0;

        return $this;
    }

    public function count(): self
    {
        if ($this->counted) {
            return $this;
        }

        $this->counted = true;

        return $this->push('count', $this->weight);
    }

    public function sum(): self
    {
        return $this->push('sum', $this->value * $this->weight);
    }

    public function min(): self
    {
        return $this->push('min', $this->value);
    }

    public function max(): self
    {
        return $this->push('max', $this->value);
    }

    public function avg(): self
    {
        return $this->sum()->count();
    }

    public function histogram(): self
    {
        return $this->push('h'.Histogram::bin($this->value), $this->weight);
    }

    private function push(string $aggregate, float $value): self
    {
        if ($this->buffer !== null && ! $this->buffer->add($this->type, $this->key, $aggregate, $value, $this->timestamp)) {
            $this->laralyze->bufferFull($this->type, $this->key, $aggregate, $value, $this->timestamp);
        }

        return $this;
    }
}
