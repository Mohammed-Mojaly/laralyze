<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Recorders\Requests;
use stdClass;

/**
 * Every route with its traffic, status classes and timings.
 */
#[Lazy]
class Routes extends Card
{
    /**
     * count, avg or p95.
     */
    public string $sort = 'count';

    public int $limit = 100;

    public function render(): View
    {
        return view('laralyze::cards.routes', [
            'routes' => $this->routes(),
        ]);
    }

    /**
     * @return Collection<int, stdClass>
     */
    protected function routes(): Collection
    {
        $routes = $this->aggregate('request', ['count', 'avg', 'p95'], orderBy: $this->sort === 'avg' ? 'avg' : 'count', limit: $this->limit);

        $statuses = collect(Requests::STATUS_CLASSES)->mapWithKeys(fn (string $class) => [
            $class => $this->aggregate("request_{$class}", ['count'], limit: 1_000)->pluck('count', 'key'),
        ]);

        $routes->each(function (stdClass $route) use ($statuses) {
            [$route->method, $route->path] = array_pad(explode(' ', (string) $route->key, 2), 2, '');

            foreach ($statuses as $class => $counts) {
                $route->{$class} = (float) ($counts[$route->key] ?? 0);
            }
        });

        return $this->sort === 'p95' ? $routes->sortByDesc('p95')->values() : $routes;
    }
}
