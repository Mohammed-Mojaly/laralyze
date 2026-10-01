<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * The most active signed-in users.
 */
#[Lazy]
class UserList extends Card
{
    public int $limit = 50;

    public function render(): View
    {
        $requests = $this->counts('user_request');
        $slow = $this->counts('user_slow_request');
        $jobs = $this->counts('user_job');

        $ids = collect(array_keys($requests))->merge(array_keys($jobs))->map(fn ($id) => (string) $id)->unique()
            ->sortByDesc(fn (string $id) => ($requests[$id] ?? 0) + ($jobs[$id] ?? 0))
            ->take($this->limit)
            ->values();

        $names = $this->values('user', array_values($ids->all()))->pluck('value', 'key');

        $users = $ids->map(function (string $id) use ($requests, $slow, $jobs, $names) {
            $details = json_decode((string) ($names[$id] ?? '[]'), true) ?: [];

            $user = new stdClass;
            $user->id = $id;
            $user->name = (string) ($details['name'] ?? $id);
            $user->extra = (string) ($details['extra'] ?? '');
            $user->requests = $requests[$id] ?? 0.0;
            $user->slow = $slow[$id] ?? 0.0;
            $user->jobs = $jobs[$id] ?? 0.0;

            return $user;
        });

        return view('laralyze::cards.user-list', ['users' => $users]);
    }
}
