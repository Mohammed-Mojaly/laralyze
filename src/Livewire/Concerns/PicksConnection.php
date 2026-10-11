<?php

namespace MohammedMojaly\Laralyze\Livewire\Concerns;

use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * A card that shows one database connection, picked in the page's top bar.
 */
trait PicksConnection
{
    /**
     * The connection's name; empty is the card's own default.
     */
    #[Url(except: '')]
    public string $connection = '';

    #[On('laralyze-connection')]
    public function filterConnection(string $connection): void
    {
        $this->connection = $connection;
    }
}
