<?php

namespace MohammedMojaly\Laralyze\Tests\Fixtures;

use Livewire\Component;

class CheckoutForm extends Component
{
    public int $orders = 0;

    public function placeOrder(): void
    {
        $this->orders++;
    }

    public function render(): string
    {
        return '<div>{{ $orders }} orders</div>';
    }
}
