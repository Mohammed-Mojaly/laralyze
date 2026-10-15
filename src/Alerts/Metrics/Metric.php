<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Support\Collection;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Support\Format;

/**
 * Something Laralyze already measures that a rule can watch: where it's
 * read from, what it can be filtered on, and how items add up.
 */
abstract class Metric
{
    /**
     * A number over the rule's window.
     */
    public const NUMBER = 'number';

    /**
     * The state right now, without a window.
     */
    public const NOW = 'now';

    /**
     * Something that happened, told once.
     */
    public const EVENT = 'event';

    /**
     * The item of a rule checked all together.
     */
    public const ALL = '*';

    /**
     * @param  'count'|'%'|'ms'|'s'|'$'|'min'  $unit
     * @param  class-string|null  $recorder  The recorder it needs, to offer it only when that's on.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $area,
        public readonly string $label,
        public readonly string $unit = 'count',
        public readonly ?string $recorder = null,
    ) {}

    public function kind(): string
    {
        return self::NUMBER;
    }

    /**
     * @return array<string, Dimension>
     */
    public function dimensions(): array
    {
        return [];
    }

    /**
     * What the form says about this metric.
     */
    public function hint(): ?string
    {
        return null;
    }

    /**
     * Raw numbers per item, only the items the rule's filter matches.
     *
     * @return array<string|int, array<string, float>> Keyed by item; PHP turns a numeric key like a user id into an int.
     */
    abstract public function read(Storage $storage, Rule $rule, int $now): array;

    /**
     * One item's value from its raw numbers; null when there's too little to judge.
     *
     * @param  array<string, float>  $raw
     */
    public function value(array $raw): ?float
    {
        return $raw['value'] ?? null;
    }

    /**
     * Every matching item as one. Counts and sums add up.
     *
     * @param  non-empty-list<array<string, float>>  $raws
     * @return array<string, float>
     */
    public function combine(array $raws): array
    {
        return ['value' => (float) array_sum(array_column($raws, 'value'))];
    }

    /**
     * A value per item for "each separately", or one value under '*' for
     * "together". Null means no data, or too little to judge.
     *
     * @return array<string|int, float|null>
     */
    public function measure(Storage $storage, Rule $rule, int $now): array
    {
        $raws = $this->read($storage, $rule, $now);

        if ($rule->mode === Rule::EACH) {
            return array_map(fn (array $raw) => $this->value($raw), $raws);
        }

        return [self::ALL => $raws === [] ? null : $this->value($this->combine(array_values($raws)))];
    }

    /**
     * How an item reads in messages and on the page.
     */
    public function itemLabel(string $item): string
    {
        return $item;
    }

    public function format(?float $value): string
    {
        return match (true) {
            $value === null => '—',
            $this->unit === '%' => round($value, 1).'%',
            $this->unit === 'ms' => Format::duration($value),
            $this->unit === 's' => Format::duration($value * 1_000),
            $this->unit === '$' => Format::money($value),
            $this->unit === 'min' => Format::number(floor($value)).' min',
            default => Format::number($value),
        };
    }

    /**
     * Values seen in the last day for a dimension, or its fixed choices.
     *
     * @return array<string, string> value => label
     */
    public function suggestions(Storage $storage, string $dimension): array
    {
        $found = $this->dimensions()[$dimension] ?? null;

        if ($found === null) {
            return [];
        }

        if ($found->options !== []) {
            return $found->options;
        }

        $suggestions = [];

        foreach ($this->seen($storage) as $key) {
            $value = $found->of($key);
            $suggestions[$value] = $value;

            if (count($suggestions) >= 50) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Keys seen in the last day, for suggestions.
     *
     * @return Collection<int, string>
     */
    protected function seen(Storage $storage): Collection
    {
        return collect();
    }

    /**
     * @template T
     *
     * @param  array<string|int, T>  $items
     * @return array<string|int, T>
     */
    protected function matching(Rule $rule, array $items): array
    {
        $filter = $rule->filter;
        $dimension = $filter === null ? null : ($this->dimensions()[$filter->by] ?? null);

        if ($filter === null || $dimension === null) {
            return $items;
        }

        return array_filter($items, fn (int|string $key) => $filter->matches($dimension->of((string) $key)), ARRAY_FILTER_USE_KEY);
    }
}
