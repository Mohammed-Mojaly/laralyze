<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Laralyze\Recorders\Requests;
use Livewire\Attributes\Lazy;

/**
 * How many requests came in, split by status class, over time.
 */
#[Lazy]
class RequestTotals extends Card
{
    public function render(): View
    {
        $statuses = [];
        $series = [];

        foreach (Requests::STATUS_CLASSES as $class) {
            $statuses[$class] = (float) ($this->total("request_{$class}", ['count'])->count ?? 0);
            $series[$class] = $this->graph("request_{$class}", 'count');
        }

        return view('laralyze::cards.request-totals', [
            'total' => array_sum($statuses),
            'statuses' => $statuses,
            'series' => $series,
        ]);
    }
}
