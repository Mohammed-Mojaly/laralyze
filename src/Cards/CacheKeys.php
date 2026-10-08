<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Livewire\Concerns\ListsRows;
use stdClass;

/**
 * Hits, misses, writes and deletes per key group.
 */
#[Lazy]
class CacheKeys extends Card
{
    use ListsRows;

    public string $sort = 'total';

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

                $row->total = $row->hit + $row->miss + $row->write + $row->delete + $row->failure;
                // Keys that were never read sort below any ratio.
                $row->ratio = $row->hit + $row->miss > 0 ? $row->hit / ($row->hit + $row->miss) : null;

                return $row;
            });

        return view('laralyze::cards.cache-keys', ['keys' => $this->arrange($keys)->take($this->limit)]);
    }

    protected function sortable(): array
    {
        return ['total', 'key', 'hit', 'miss', 'ratio', 'write', 'delete', 'failure'];
    }
}
