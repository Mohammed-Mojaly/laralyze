<?php

namespace MohammedMojaly\Laralyze\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use MohammedMojaly\Laralyze\Dashboard\Range;

/**
 * Prepares graph() results for the chart components.
 */
final class Chart
{
    /**
     * @var list<int>
     */
    public readonly array $slots;

    /**
     * @param  array<string, Collection<int, float|null>>  $series  Series name => graph() result.
     */
    public function __construct(public readonly array $series, public readonly Range $range)
    {
        $first = reset($series);

        $this->slots = $first === false ? [] : array_values(array_map(intval(...), $first->keys()->all()));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->series));
    }

    public function value(string $name, int $slot): ?float
    {
        $value = $this->series[$name][$slot] ?? null;

        return $value === null ? null : (float) $value;
    }

    public function stack(int $slot): float
    {
        return array_sum(array_map(fn (string $name) => $this->value($name, $slot) ?? 0.0, $this->names()));
    }

    /**
     * The top of the y axis: the highest point, or stack when bars are stacked.
     */
    public function max(bool $stacked = false): float
    {
        $max = 0.0;

        foreach ($this->slots as $slot) {
            $max = max($max, $stacked
                ? $this->stack($slot)
                : max(0.0, ...array_map(fn (string $name) => $this->value($name, $slot) ?? 0.0, $this->names())));
        }

        return $max;
    }

    public function isEmpty(): bool
    {
        return $this->max() <= 0.0;
    }

    public function label(int $slot): string
    {
        return Carbon::createFromTimestamp($slot, (string) config('app.timezone', 'UTC'))->format($this->range->timeFormat());
    }

    /**
     * SVG polyline points for one series, split wherever there's no data.
     *
     * @return list<string>
     */
    public function lines(string $name, float $max, int $width = 600, int $height = 100): array
    {
        $count = count($this->slots);
        $lines = [];
        $points = [];

        foreach ($this->slots as $index => $slot) {
            $value = $this->value($name, $slot);

            if ($value === null) {
                $lines[] = $points;
                $points = [];

                continue;
            }

            $x = $count > 1 ? $index / ($count - 1) * $width : $width / 2;
            $y = $height - ($max > 0 ? $value / $max * $height : 0);

            $points[] = round($x, 1).','.round($y, 1);
        }

        $lines[] = $points;

        // A single point can't draw a line, so give it a tiny width.
        return array_values(array_map(
            fn (array $points) => count($points) === 1 ? $points[0].' '.$points[0] : implode(' ', $points),
            array_filter($lines),
        ));
    }
}
