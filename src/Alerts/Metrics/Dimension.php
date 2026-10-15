<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Closure;
use MohammedMojaly\Laralyze\Alerts\Filter;

/**
 * What a rule can filter a metric on: a route, a job class, a level…
 */
final class Dimension
{
    /**
     * @param  'text'|'list'  $type
     * @param  array<string, string>  $options  Fixed choices for a list, value => label.
     * @param  (Closure(string): string)|null  $part  The part of a key it compares, e.g. the model in ["openai","gpt-4o"].
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly array $options = [],
        public readonly ?Closure $part = null,
    ) {}

    public function of(string $key): string
    {
        return $this->part === null ? $key : (string) ($this->part)($key);
    }

    /**
     * @return list<string>
     */
    public function operators(): array
    {
        return $this->type === 'list' ? Filter::LIST : Filter::TEXT;
    }
}
