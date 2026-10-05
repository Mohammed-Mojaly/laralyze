<?php

namespace MohammedMojaly\Laralyze\Ingest;

use JsonException;

/**
 * Several flushes merged into one write, by the same rules storage
 * applies: counts and sums add up, min and max keep the extreme, the
 * newest value wins, and executions are all kept.
 */
final class Batch
{
    /**
     * @var array<string, array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>
     */
    private array $rows = [];

    /**
     * @var array<string, array{timestamp: int, type: string, key: string, value: string}>
     */
    private array $values = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $executions = [];

    /**
     * What one flush hands over, ready for the ingest table.
     *
     * @param  list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>  $rows
     * @param  list<array{timestamp: int, type: string, key: string, value: string}>  $values
     * @param  list<array<string, mixed>>  $executions
     *
     * @throws JsonException
     */
    public static function encode(array $rows, array $values, array $executions): string
    {
        $json = json_encode(
            ['rows' => $rows, 'values' => $values, 'executions' => $executions],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        // Text, not binary, so every database stores it the same way.
        return base64_encode((string) gzdeflate($json, 6));
    }

    /**
     * @return array{rows: list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>, values: list<array{timestamp: int, type: string, key: string, value: string}>, executions: list<array<string, mixed>>}
     *
     * @throws JsonException
     */
    public static function decode(string $payload): array
    {
        $json = gzinflate((string) base64_decode($payload, true));

        if ($json === false) {
            throw new JsonException('A Laralyze ingest payload is corrupt.');
        }

        /** @var array{rows?: list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>, values?: list<array{timestamp: int, type: string, key: string, value: string}>, executions?: list<array<string, mixed>>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return [
            'rows' => array_map(fn (array $row) => [...$row, 'value' => (float) $row['value']], $data['rows'] ?? []),
            'values' => $data['values'] ?? [],
            'executions' => $data['executions'] ?? [],
        ];
    }

    /**
     * @param  array{rows: list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>, values: list<array{timestamp: int, type: string, key: string, value: string}>, executions: list<array<string, mixed>>}  $flush
     */
    public function add(array $flush): void
    {
        foreach ($flush['rows'] as $row) {
            $id = $row['period'].'|'.$row['bucket'].'|'.$row['type'].'|'.$row['aggregate'].'|'.$row['key'];

            if (! isset($this->rows[$id])) {
                $this->rows[$id] = $row;

                continue;
            }

            $current = $this->rows[$id]['value'];

            $this->rows[$id]['value'] = match ($row['aggregate']) {
                'max' => max($current, $row['value']),
                'min' => min($current, $row['value']),
                default => $current + $row['value'],
            };
        }

        foreach ($flush['values'] as $value) {
            $id = $value['type'].'|'.$value['key'];

            // A slow request's flush can arrive after a newer one: the newer value wins, a tie goes to the later batch.
            if (! isset($this->values[$id]) || $value['timestamp'] >= $this->values[$id]['timestamp']) {
                $this->values[$id] = $value;
            }
        }

        foreach ($flush['executions'] as $execution) {
            $this->executions[] = $execution;
        }
    }

    /**
     * @return list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>
     */
    public function rows(): array
    {
        return array_values($this->rows);
    }

    /**
     * @return list<array{timestamp: int, type: string, key: string, value: string}>
     */
    public function values(): array
    {
        return array_values($this->values);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function executions(): array
    {
        return $this->executions;
    }
}
