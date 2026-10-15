<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Support\Collection;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use stdClass;

/**
 * An average or a percentile per key, like a route's p95 or a server's
 * CPU. All together, it's the highest of the matching items.
 */
class Highest extends Metric
{
    /**
     * @param  'count'|'%'|'ms'|'s'|'$'|'min'  $unit
     * @param  array<string, Dimension>  $dimensions
     * @param  class-string|null  $recorder
     * @param  float  $divisor  From the stored unit to the shown one, e.g. 1,000 for ms to s.
     */
    public function __construct(
        string $key,
        string $area,
        string $label,
        string $unit,
        protected string $type,
        protected string $aggregate,
        protected array $dimensions,
        ?string $recorder,
        protected float $divisor = 1.0,
        protected ?string $note = null,
    ) {
        parent::__construct($key, $area, $label, $unit, $recorder);
    }

    public function dimensions(): array
    {
        return $this->dimensions;
    }

    public function hint(): ?string
    {
        return trim(($this->note ?? '').' All together, it\'s the highest of the matching items.');
    }

    public function read(Storage $storage, Rule $rule, int $now): array
    {
        $items = [];

        foreach ($storage->aggregate($this->type, [$this->aggregate], $rule->window, null, 1_000) as $row) {
            $value = $row->{$this->aggregate} ?? null;

            if ($value !== null) {
                $items[(string) $row->key] = ['value' => (float) $value / $this->divisor];
            }
        }

        return $this->matching($rule, $items);
    }

    public function combine(array $raws): array
    {
        return ['value' => max(array_map(fn (array $raw) => (float) $raw['value'], $raws))];
    }

    protected function seen(Storage $storage): Collection
    {
        return $storage->aggregate($this->type, [$this->aggregate], 86_400, null, 200)->map(fn (stdClass $row) => (string) $row->key);
    }
}
