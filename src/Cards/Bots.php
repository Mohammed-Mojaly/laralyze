<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Livewire\Attributes\Lazy;

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
