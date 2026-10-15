<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Recorders;
use stdClass;

/**
 * AI cost, tokens or failures by model, agent or user. Each is stored
 * apart, so the filter's dimension picks what's read.
 */
class AiUsage extends Metric
{
    /**
     * @param  'count'|'$'  $unit
     * @param  array<string, list<string>>  $types  Per dimension, the types added up. The first is read without a filter.
     */
    public function __construct(
        string $key,
        string $label,
        string $unit,
        protected array $types,
        protected string $aggregate,
        protected float $divisor = 1.0,
        protected ?string $note = null,
    ) {
        parent::__construct($key, 'AI', $label, $unit, Recorders\Ai::class);
    }

    public function dimensions(): array
    {
        return array_intersect_key([
            'model' => new Dimension('model', 'Model', part: fn (string $key) => (string) (json_decode($key, true)[1] ?? $key)),
            'agent' => new Dimension('agent', 'Agent'),
            'user' => new Dimension('user', 'User'),
        ], $this->types);
    }

    public function hint(): ?string
    {
        return $this->note;
    }

    public function read(Storage $storage, Rule $rule, int $now): array
    {
        $items = [];

        foreach ($this->types[$this->by($rule)] as $type) {
            foreach ($storage->aggregate($type, [$this->aggregate], $rule->window, $this->aggregate, 1_000) as $row) {
                $key = (string) $row->key;
                $items[$key]['value'] = ($items[$key]['value'] ?? 0.0) + (float) $row->{$this->aggregate} / $this->divisor;
            }
        }

        return $this->matching($rule, $items);
    }

    public function itemLabel(string $item): string
    {
        $parts = json_decode($item, true);

        return is_array($parts) ? implode(' · ', array_map(fn (mixed $part) => (string) $part, $parts)) : $item;
    }

    public function suggestions(Storage $storage, string $dimension): array
    {
        if ($dimension === 'user' && isset($this->types['user'])) {
            return $storage->values('user', limit: 50)
                ->mapWithKeys(fn (stdClass $row) => [(string) $row->key => (string) (json_decode((string) $row->value, true)['name'] ?? $row->key)])
                ->all();
        }

        $found = $this->dimensions()[$dimension] ?? null;

        if ($found === null) {
            return [];
        }

        $suggestions = [];

        foreach ($storage->aggregate($this->types[$dimension][0], [$this->aggregate], 86_400, $this->aggregate, 200) as $row) {
            $value = $found->of((string) $row->key);
            $suggestions[$value] = $value;
        }

        return array_slice($suggestions, 0, 50, true);
    }

    protected function by(Rule $rule): string
    {
        $by = $rule->filter?->by;

        return $by !== null && isset($this->types[$by]) ? $by : (string) array_key_first($this->types);
    }
}
