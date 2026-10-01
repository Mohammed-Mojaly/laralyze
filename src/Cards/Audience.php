<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Devices, operating systems and browsers of unique visitors.
 */
#[Lazy]
class Audience extends Card
{
    public function render(): View
    {
        return view('laralyze::cards.audience', [
            'groups' => [
                'Devices' => $this->counts('visitor_device'),
                'Systems' => $this->counts('visitor_os'),
                'Browsers' => $this->counts('visitor_browser'),
            ],
        ]);
    }
}
