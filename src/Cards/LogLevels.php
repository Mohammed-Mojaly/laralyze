<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Laralyze\Recorders\Logs;
use Livewire\Attributes\Lazy;

/**
 * Logged messages per level over time.
 */
#[Lazy]
class LogLevels extends Card
{
    public function render(): View
    {
        $counts = $this->counts('log');
        $levels = array_values(array_filter(array_reverse(Logs::LEVELS), fn (string $level) => ($counts[$level] ?? 0) > 0));

        $totals = [];
        $series = [];

        foreach ($levels as $level) {
            $totals[$level] = $counts[$level];
            $series[$level] = $this->graph('log', 'count', $level);
        }

        return view('laralyze::cards.log-levels', [
            'totals' => $totals,
            'total' => array_sum($totals),
            'series' => $series,
        ]);
    }
}
