<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;

/**
 * How much time goes to the database: count, total time, typical
 * timings, reads against writes and each connection.
 */
#[Lazy]
class QueryTotals extends Card
{
    public function render(): View
    {
        return view('laralyze::cards.query-totals', [
            'totals' => $this->total('query', ['count', 'sum', 'avg', 'p95']),
            'kinds' => $this->aggregate('query_kind', ['count', 'sum'])->keyBy('key'),
            'connections' => $this->aggregate('query_connection', ['count', 'sum'], orderBy: 'sum'),
            'series' => [
                'avg' => $this->graph('query', 'avg'),
                'p95' => $this->graph('query', 'p95'),
            ],
        ]);
    }
}
