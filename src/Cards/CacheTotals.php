<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;

/**
 * Cache hits against misses over time, with writes, deletes and failures.
 */
#[Lazy]
class CacheTotals extends Card
{
    public const TYPES = ['hit', 'miss', 'write', 'delete', 'failure'];

    public function render(): View
    {
        $totals = [];

        foreach (self::TYPES as $type) {
            $totals[$type] = (float) ($this->total("cache_{$type}", ['count'])->count ?? 0);
        }

        return view('laralyze::cards.cache-totals', [
            'totals' => $totals,
            'series' => [
                'hit' => $this->graph('cache_hit', 'count'),
                'miss' => $this->graph('cache_miss', 'count'),
            ],
        ]);
    }
}
