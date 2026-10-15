<?php

namespace MohammedMojaly\Laralyze\Alerts;

/**
 * A rule looks at one dimension at a time: a route, a job class, a user…
 */
final class Filter
{
    public const OPERATORS = [
        'equals' => 'is',
        'not_equals' => 'is not',
        'contains' => 'contains',
        'not_contains' => 'does not contain',
        'in' => 'is one of',
        'not_in' => 'is none of',
    ];

    public const TEXT = ['equals', 'not_equals', 'contains', 'not_contains'];

    public const LIST = ['in', 'not_in'];

    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public readonly string $by,
        public readonly string $operator,
        public readonly array $values,
    ) {}

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['by'] ?? null) || $data['by'] === '' || ! isset(self::OPERATORS[$data['operator'] ?? ''])) {
            return null;
        }

        $values = array_values(array_filter(
            array_map(fn (mixed $value) => trim((string) $value), (array) ($data['values'] ?? [])),
            fn (string $value) => $value !== '',
        ));

        return $values === [] ? null : new self($data['by'], (string) $data['operator'], $values);
    }

    /**
     * @return array{by: string, operator: string, values: list<string>}
     */
    public function toArray(): array
    {
        return ['by' => $this->by, 'operator' => $this->operator, 'values' => $this->values];
    }

    /**
     * Without case: "/API/" matches "/api/".
     */
    public function matches(string $value): bool
    {
        $value = mb_strtolower($value);
        $values = array_map(mb_strtolower(...), $this->values);
        $contains = function () use ($value, $values): bool {
            foreach ($values as $part) {
                if (str_contains($value, $part)) {
                    return true;
                }
            }

            return false;
        };

        return match ($this->operator) {
            'equals', 'in' => in_array($value, $values, true),
            'not_equals', 'not_in' => ! in_array($value, $values, true),
            'contains' => $contains(),
            'not_contains' => ! $contains(),
            default => false,
        };
    }

    public function describe(): string
    {
        return $this->by.' '.self::OPERATORS[$this->operator].' '.implode(', ', $this->values);
    }
}
