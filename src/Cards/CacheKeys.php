<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use stdClass;

/**
 * Hits, misses, writes and deletes per key group.
 */
#[Lazy]
class CacheKeys extends Card
{
    public int $limit = 100;

    public function render(): View
    {
        $counts = [];

        foreach (CacheTotals::TYPES as $type) {
            $counts[$type] = $this->counts("cache_{$type}");
        }

        $keys = collect($counts)->flatMap(fn (array $byKey) => array_keys($byKey))->unique()
            ->map(function (string $key) use ($counts) {
                $row = new stdClass;
                $row->key = $key;

                foreach ($counts as $type => $byKey) {
                    $row->{$type} = $byKey[$key] ?? 0.0;
                }

                return $row;
            })
            ->sortByDesc(fn (stdClass $row) => $row->hit + $row->miss)
            ->take($this->limit)
            ->values();

        return view('laralyze::cards.cache-keys', ['keys' => $keys]);
    }
}
