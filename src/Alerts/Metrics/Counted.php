<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Closure;
use Illuminate\Support\Collection;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use stdClass;

/**
 * How many times something happened over the window, per key.
 */
class Counted extends Metric
{
    /**
     * @param  array<string, Dimension>  $dimensions
     * @param  class-string|null  $recorder
     * @param  (Closure(string): string)|null  $labels
     */
    public function __construct(
        string $key,
        string $area,
        string $label,
        protected string $type,
        protected array $dimensions = [],
        ?string $recorder = null,
        protected ?Closure $labels = null,
    ) {
        parent::__construct($key, $area, $label, 'count', $recorder);
    }

    public function dimensions(): array
    {
        return $this->dimensions;
    }

    public function read(Storage $storage, Rule $rule, int $now): array
    {
        $items = [];

        foreach ($storage->aggregate($this->type, ['count'], $rule->window, 'count', 1_000) as $row) {
            $items[(string) $row->key] = ['value' => (float) $row->count];
        }

        return $this->matching($rule, $items);
    }

    public function itemLabel(string $item): string
    {
        return $this->labels === null ? $item : ($this->labels)($item);
    }

    protected function seen(Storage $storage): Collection
    {
        return $storage->aggregate($this->type, ['count'], 86_400, 'count', 200)->map(fn (stdClass $row) => (string) $row->key);
    }
}
