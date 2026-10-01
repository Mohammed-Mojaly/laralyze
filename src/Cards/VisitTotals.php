<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;

/**
 * Visitors right now, page views and unique visitors over the period.
 */
#[Lazy]
class VisitTotals extends Card
{
    public int $poll = 30;

    public function render(): View
    {
        return view('laralyze::cards.visit-totals', [
            'live' => $this->countValues('visitor_seen', 300),
            'visits' => (float) ($this->total('visit', ['count'])->count ?? 0),
            'visitors' => (float) ($this->total('visitor', ['count'])->count ?? 0),
            'bots' => (float) ($this->total('visit_bot', ['count'])->count ?? 0),
            'series' => ['visits' => $this->graph('visit', 'count')],
        ]);
    }
}
