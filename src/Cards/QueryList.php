<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;

/**
 * Queries grouped by their SQL, the most expensive first.
 */
#[Lazy]
class QueryList extends Card
{
    /**
     * sum (total time), count or avg.
     */
    public string $sort = 'sum';

    public int $limit = 100;

    public function render(): View
    {
        $sort = in_array($this->sort, ['sum', 'count', 'avg'], true) ? $this->sort : 'sum';

        return view('laralyze::cards.query-list', [
            'queries' => $this->aggregate('query', ['count', 'sum', 'avg', 'p95', 'max'], orderBy: $sort, limit: $this->limit),
        ]);
    }
}
