<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;

/**
 * What to plan for: the size, how fast it grows, the largest and fastest
 * growing tables, and ids running out. Measured once an hour.
 */
#[Lazy]
class DatabaseSize extends DatabaseCard
{
    public function render(): View
    {
        $size = $this->latest('database_size');
        $history = array_values((array) ($size['history'] ?? []));
        [$first, $last] = [$history[0] ?? null, $history[count($history) - 1] ?? null];
        $days = $first === null || $last === null ? 0 : (int) round((strtotime((string) $last['day']) - strtotime((string) $first['day'])) / 86_400);

        $growing = [];

        if ($days > 0) {
            foreach ((array) $last['tables'] as $table => $bytes) {
                $growth = ((int) $bytes - (int) ($first['tables'][$table] ?? 0)) / $days;

                if ($growth > 0) {
                    $growing[(string) $table] = $growth;
                }
            }

            arsort($growing);
        }

        return view('laralyze::cards.database-size', [
            'size' => $size,
            'perDay' => $days > 0 ? ((int) $last['total'] - (int) $first['total']) / $days : null,
            'days' => $days,
            'growing' => array_slice($growing, 0, 10, true),
        ]);
    }
}
