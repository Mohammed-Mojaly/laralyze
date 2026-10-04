<?php

namespace MohammedMojaly\Laralyze\Assistant;

use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Range;
use MohammedMojaly\Laralyze\Recorders\Ai;
use MohammedMojaly\Laralyze\Support\Chart;

/**
 * Charts in the assistant's answers. The model doesn't draw numbers: it
 * names what Laralyze recorded, and the chart is drawn from the data, the
 * same way as on the dashboard. Only rankings may carry their own values.
 *
 *     {"type": "series", "title": "p95", "period": "24h", "series": [{"metric": "request", "show": "p95", "key": "GET /books"}]}
 *     {"type": "ranking", "title": "Cost per model", "format": "money", "items": [{"label": "gpt-4o-mini", "value": 0.38}]}
 */
class Charts
{
    public const SHOWS = ['count', 'sum', 'avg', 'min', 'max', 'p50', 'p75', 'p90', 'p95', 'p99'];

    public const FORMATS = ['number', 'duration', 'money'];

    public function __construct(protected Storage $storage) {}

    /**
     * What to draw: nothing when the request makes no sense, and one chart
     * per unit when series mix counts, durations and money.
     *
     * @return list<array{type: string, title: string, format: string, chart?: Chart, items?: list<array{label: string, value: float}>}>
     */
    public function make(string $json): array
    {
        $spec = json_decode(trim($json), true);

        if (! is_array($spec)) {
            return [];
        }

        $title = mb_strimwidth(is_string($spec['title'] ?? null) ? $spec['title'] : '', 0, 120, '…');

        if (($spec['type'] ?? 'lines') === 'ranking') {
            $ranking = $this->ranking($spec, $title);

            return $ranking === null ? [] : [$ranking];
        }

        return $this->series($spec, $title);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array{type: string, title: string, format: string, chart: Chart}>
     */
    protected function series(array $spec, string $title): array
    {
        $range = Range::tryFrom((string) ($spec['period'] ?? '')) ?? Range::Day;
        $byFormat = [];

        foreach (array_slice(array_values((array) ($spec['series'] ?? [])), 0, 4) as $i => $line) {
            $metric = is_array($line) ? (string) ($line['metric'] ?? '') : '';
            $show = is_array($line) ? (string) ($line['show'] ?? 'count') : '';
            $key = is_array($line) && is_scalar($line['key'] ?? null) && $line['key'] !== '' ? (string) $line['key'] : null;

            if (! preg_match('/^[a-z0-9_]{1,40}$/', $metric) || ! in_array($show, self::SHOWS, true)) {
                continue;
            }

            $values = $this->storage->graph($metric, $show, $range->seconds(), $key);

            if (str_ends_with($metric, '_cost')) {
                $values = $values->map(fn (?float $value) => $value === null ? null : $value / Ai::MICRO);
            }

            $name = 'a'.($i + 1);
            $format = $this->format($metric, $show);
            $byFormat[$format]['series'][$name] = $values;
            $byFormat[$format]['titles'][$name] = mb_strimwidth(is_string($line['label'] ?? null) && $line['label'] !== '' ? $line['label'] : trim("{$metric} {$show} ".($key ?? '')), 0, 60, '…');
        }

        $charts = [];

        foreach ($byFormat as $format => ['series' => $series, 'titles' => $titles]) {
            $charts[] = [
                // Like the dashboard: counts and money as bars, timings as lines.
                'type' => $format === 'duration' ? 'lines' : 'bars',
                // A second chart is named after what it shows.
                'title' => $charts === [] ? $title : implode(', ', $titles),
                'format' => $format,
                'chart' => new Chart($series, $range, $titles),
            ];
        }

        return $charts;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{type: string, title: string, format: string, items: list<array{label: string, value: float}>}|null
     */
    protected function ranking(array $spec, string $title): ?array
    {
        $items = [];

        foreach (array_slice(array_values((array) ($spec['items'] ?? [])), 0, 20) as $item) {
            if (is_array($item) && is_numeric($item['value'] ?? null)) {
                $items[] = ['label' => mb_strimwidth((string) ($item['label'] ?? ''), 0, 80, '…'), 'value' => (float) $item['value']];
            }
        }

        $format = (string) ($spec['format'] ?? 'number');

        return $items === [] ? null : [
            'type' => 'ranking',
            'title' => $title,
            'format' => in_array($format, self::FORMATS, true) ? $format : 'number',
            'items' => $items,
        ];
    }

    protected function format(string $metric, string $show): string
    {
        return match (true) {
            str_ends_with($metric, '_cost') => 'money',
            in_array($show, ['count', 'sum'], true) => 'number',
            default => 'duration',
        };
    }
}
