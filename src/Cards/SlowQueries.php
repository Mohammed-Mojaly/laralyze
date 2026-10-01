<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Laralyze\Recorders\Queries;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * Queries over their threshold, with the line of your code that ran them.
 */
#[Lazy]
class SlowQueries extends Card
{
    public int $limit = 25;

    public function render(): View
    {
        $recorder = $this->recorder(Queries::class);

        $queries = $this->aggregate('slow_query', ['count', 'max'], orderBy: 'max', limit: $this->limit)
            ->each(function (stdClass $query) use ($recorder) {
                [$query->sql, $query->location] = array_pad($this->parts((string) $query->key), 2, '');
                $query->threshold = $recorder?->threshold($query->sql);
            });

        return view('laralyze::cards.slow-queries', ['queries' => $queries]);
    }
}
