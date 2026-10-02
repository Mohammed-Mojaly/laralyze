<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;

/**
 * How many users were signed in, and how much of the traffic is theirs.
 */
#[Lazy]
class UserTotals extends Card
{
    public function render(): View
    {
        $signedIn = $this->graph('user_request', 'count');
        $all = $this->graph('request', 'count');

        $authenticated = (float) ($this->total('user_request', ['count'])->count ?? 0);
        $requests = (float) ($this->total('request', ['count'])->count ?? 0);

        return view('laralyze::cards.user-totals', [
            'users' => $this->countKeys('user_request'),
            'usersOverTime' => ['users' => $this->graphKeys('user_request')],
            'requests' => ['authenticated' => $authenticated, 'guest' => max(0, $requests - $authenticated)],
            'requestsOverTime' => [
                'authenticated' => $signedIn,
                // Whatever isn't a signed-in user's request is a guest's.
                'guest' => $all->map(fn (?float $count, int $slot) => $count === null ? null : max(0, $count - ($signedIn[$slot] ?? 0))),
            ],
        ]);
    }
}
