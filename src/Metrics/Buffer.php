<?php

namespace MohammedMojaly\Laralyze\Metrics;

/**
 * Holds metrics for the current execution, already aggregated per bucket.
 *
 * Merging on the way in means a request that runs 1,000 identical queries
 * still holds a handful of rows, not 1,000 entries. This sits on the hot
 * path, so it trades a little elegance for speed.
 */
final class Buffer
{
    /**
     * Nested as [period][bucket][type][aggregate][key] => value. Nested
     * lookups are about twice as fast as building a string key per call.
     *
     * @var array<int, array<int, array<string, array<string, array<string, float>>>>>
     */
    private array $rows = [];

    /**
     * @var array<string, array{timestamp: int, type: string, key: string, value: string}>
     */
    private array $values = [];

    private int $size = 0;

    public function __construct(private int $limit = 5_000) {}

    /**
     * Merge a value into the minute and hour buckets. Returns false when
     * the buffer is full and the value needed a new row.
     */
    public function add(string $type, string $key, string $aggregate, float $value, int $timestamp): bool
    {
        return $this->addTo(Period::MINUTE, $timestamp - ($timestamp % Period::MINUTE), $type, $key, $aggregate, $value)
            && $this->addTo(Period::HOUR, $timestamp - ($timestamp % Period::HOUR), $type, $key, $aggregate, $value);
    }

    private function addTo(int $period, int $bucket, string $type, string $key, string $aggregate, float $value): bool
    {
        if (isset($this->rows[$period][$bucket][$type][$aggregate][$key])) {
            $current = $this->rows[$period][$bucket][$type][$aggregate][$key];

            $this->rows[$period][$bucket][$type][$aggregate][$key] = match ($aggregate) {
                'max' => $value > $current ? $value : $current,
                'min' => $value < $current ? $value : $current,
                default => $current + $value,
            };

            return true;
        }

        if ($this->size >= $this->limit) {
            return false;
        }

        $this->size++;
        $this->rows[$period][$bucket][$type][$aggregate][$key] = $value;

        return true;
    }

    public function set(string $type, string $key, string $value, int $timestamp): void
    {
        $id = $type.'|'.$key;

        if (! isset($this->values[$id])) {
            $this->size++;
        }

        $this->values[$id] = ['timestamp' => $timestamp, 'type' => $type, 'key' => $key, 'value' => $value];
    }

    /**
     * @return list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->rows as $period => $buckets) {
            foreach ($buckets as $bucket => $types) {
                foreach ($types as $type => $aggregates) {
                    foreach ($aggregates as $aggregate => $keys) {
                        foreach ($keys as $key => $value) {
                            // PHP turns numeric-looking array keys into ints; keys are always strings here.
                            $rows[] = ['bucket' => $bucket, 'period' => $period, 'type' => (string) $type, 'aggregate' => (string) $aggregate, 'key' => (string) $key, 'value' => $value];
                        }
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<array{timestamp: int, type: string, key: string, value: string}>
     */
    public function values(): array
    {
        return array_values($this->values);
    }

    /**
     * Take everything out of the buffer.
     *
     * @return array{0: list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>, 1: list<array{timestamp: int, type: string, key: string, value: string}>}
     */
    public function drain(): array
    {
        $drained = [$this->rows(), $this->values()];

        $this->clear();

        return $drained;
    }

    public function clear(): void
    {
        $this->rows = [];
        $this->values = [];
        $this->size = 0;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function isEmpty(): bool
    {
        return $this->size === 0;
    }

    public function limitTo(int $limit): void
    {
        $this->limit = $limit;
    }
}
