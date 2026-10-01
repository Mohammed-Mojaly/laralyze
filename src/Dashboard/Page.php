<?php

namespace MohammedMojaly\Laralyze\Dashboard;

final readonly class Page
{
    public function __construct(
        public string $key,
        public string $label,
        public string $view,
        public ?string $section = null,
        public string $icon = 'page',
    ) {}

    public function url(?Range $range = null): string
    {
        $query = $range === null || $range === Range::Hour ? [] : ['period' => $range->value];

        return $this->key === Pages::HOME
            ? route('laralyze.dashboard', $query)
            : route('laralyze.page', ['page' => $this->key, ...$query]);
    }
}
