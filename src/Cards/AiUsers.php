<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Dashboard\Pages;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Recorders\Ai;
use stdClass;

/**
 * Signed-in users who used AI the most, and what jobs, commands and
 * guests used.
 */
#[Lazy]
class AiUsers extends Card
{
    public int $limit = 10;

    public function render(): View
    {
        $byUser = $this->aggregate('ai_user', ['count', 'sum'], orderBy: 'sum', limit: $this->limit);
        $cost = $this->sums('ai_user_cost');
        $details = $this->values('user', array_values($byUser->pluck('key')->map(fn ($key) => (string) $key)->all()))->keyBy('key');

        $users = $byUser->map(function (stdClass $row) use ($cost, $details) {
            $key = (string) $row->key;
            $user = new stdClass;
            $user->id = $key;
            $user->name = (string) (json_decode((string) ($details[$key]->value ?? '[]'), true)['name'] ?? $key);
            $user->count = (float) $row->count;
            $user->tokens = (float) $row->sum;
            $user->cost = isset($cost[$key]) ? $cost[$key] / Ai::MICRO : null;

            return $user;
        });

        // The rest: queued jobs, commands, guests, and users below the top ones.
        $users->push((object) [
            'id' => null,
            'name' => $users->count() < $this->limit ? 'Jobs, commands and guests' : 'Everyone else',
            'count' => max(0, (float) ($this->total('ai', ['count'])->count ?? 0) - $users->sum('count')),
            'tokens' => max(0, (float) ($this->total('ai_input', ['sum'])->sum ?? 0) + (float) ($this->total('ai_output', ['sum'])->sum ?? 0) - $users->sum('tokens')),
            'cost' => max(0, (float) ($this->total('ai_cost', ['sum'])->sum ?? 0) / Ai::MICRO - $users->sum('cost')),
        ]);

        return view('laralyze::cards.ai-users', [
            'users' => $users->filter(fn (stdClass $user) => $user->count > 0)->values(),
            'linked' => app(Pages::class)->find('users') !== null,
        ]);
    }
}
