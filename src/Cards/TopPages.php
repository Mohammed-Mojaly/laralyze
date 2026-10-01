<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Livewire\Attributes\Lazy;

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
