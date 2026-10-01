<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;

/**
 * Crawlers, link previews and scripts, kept apart from real visitors.
 */
#[Lazy]
class Bots extends Card
{
    public function render(): View
    {
        return view('laralyze::cards.bots', [
            'bots' => $this->aggregate('visit_bot', ['count'], orderBy: 'count', limit: 50),
        ]);
    }
}
