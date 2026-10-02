<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Livewire\Concerns\ListsRows;

/**
 * Queries grouped by their SQL, the most expensive first.
 */
#[Lazy]
class QueryList extends Card
{
    use ListsRows;

    public string $sort = 'sum';

    public int $limit = 100;

    public function render(): View
    {
        $queries = $this->aggregate('query', ['count', 'sum', 'avg', 'p95', 'max'], orderBy: 'sum', limit: $this->limit);

        return view('laralyze::cards.query-list', ['queries' => $this->arrange($queries)]);
    }

    protected function sortable(): array
    {
        return ['sum', 'key', 'count', 'avg', 'p95', 'max'];
    }
}
