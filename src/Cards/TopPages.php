<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;

/**
 * The most visited pages.
 */
#[Lazy]
class TopPages extends Card
{
    public int $limit = 50;

    public function render(): View
    {
        return view('laralyze::cards.top-pages', [
            'pages' => $this->aggregate('visit', ['count'], orderBy: 'count', limit: $this->limit),
        ]);
    }
}
