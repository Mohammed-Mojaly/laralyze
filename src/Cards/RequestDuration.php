<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;

/**
 * How long requests take: average, p95 and the slowest one.
 */
#[Lazy]
class RequestDuration extends Card
{
    public function render(): View
    {
        return view('laralyze::cards.request-duration', [
            'totals' => $this->total('request', ['count', 'avg', 'max', 'p95', 'p99']),
            'series' => [
                'avg' => $this->graph('request', 'avg'),
                'p95' => $this->graph('request', 'p95'),
            ],
        ]);
    }
}
