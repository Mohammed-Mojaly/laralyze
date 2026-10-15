<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Support\Collection;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Recorders;
use stdClass;

/**
 * Requests answered with a 5xx, out of all requests.
 */
class ErrorRate extends Metric
{
    /**
     * Fewer requests than this say little: one error in three isn't 33%.
     */
    public const MINIMUM = 20;

    public function __construct()
    {
        parent::__construct('requests.error_rate', 'Requests', 'Error rate', '%', Recorders\Requests::class);
    }

    public function dimensions(): array
    {
        return ['route' => new Dimension('route', 'Route')];
    }

    public function hint(): ?string
    {
        return 'Requests answered with a 5xx, out of all requests. Needs at least '.self::MINIMUM.' requests in the window.';
    }

    public function read(Storage $storage, Rule $rule, int $now): array
    {
        $errors = $storage->aggregate('request_5xx', ['count'], $rule->window, 'count', 1_000)->pluck('count', 'key');
        $items = [];

        foreach ($storage->aggregate('request', ['count'], $rule->window, 'count', 1_000) as $row) {
            $items[(string) $row->key] = ['value' => (float) ($errors[$row->key] ?? 0), 'requests' => (float) $row->count];
        }

        return $this->matching($rule, $items);
    }

    public function value(array $raw): ?float
    {
        $requests = $raw['requests'] ?? 0.0;

        return $requests < self::MINIMUM ? null : ($raw['value'] ?? 0.0) * 100 / $requests;
    }

    public function combine(array $raws): array
    {
        return [
            'value' => (float) array_sum(array_column($raws, 'value')),
            'requests' => (float) array_sum(array_column($raws, 'requests')),
        ];
    }

    protected function seen(Storage $storage): Collection
    {
        return $storage->aggregate('request', ['count'], 86_400, 'count', 200)->map(fn (stdClass $row) => (string) $row->key);
    }
}
