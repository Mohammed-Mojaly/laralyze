<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use stdClass;

/**
 * Artisan commands with their runs, failures and timings.
 */
#[Lazy]
class CommandList extends Card
{
    public function render(): View
    {
        $failed = $this->counts('command_failed');

        $commands = $this->aggregate('command', ['count', 'avg', 'max'], orderBy: 'count')
            ->each(fn (stdClass $command) => $command->failed = $failed[$command->key] ?? 0.0);

        return view('laralyze::cards.command-list', ['commands' => $commands]);
    }
}
