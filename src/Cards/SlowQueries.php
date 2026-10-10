<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Recorders\Queries;
use stdClass;

/**
 * Queries over their threshold, with the line of your code that ran them.
 */
#[Lazy]
class SlowQueries extends Card
{
    public int $limit = 25;

    /**
     * Only this connection's queries; every connection when empty.
     */
    #[Url]
    public string $connection = '';

    public function render(): View
    {
        $recorder = $this->recorder(Queries::class);

        $queries = $this->aggregate('slow_query', ['count', 'max'], orderBy: 'max', limit: $this->limit, keyPrefix: $this->connection === '' ? null : Queries::prefix($this->connection))
            ->each(function (stdClass $query) use ($recorder) {
                [$query->connection, $query->sql, $query->location] = Queries::slowParts((string) $query->key);
                // Its row on the Queries page: keys from before connections were kept are the SQL alone.
                $query->group = $query->connection === '' ? $query->sql : Queries::key($query->connection, $query->sql);
                $query->threshold = $recorder?->threshold($query->sql);
            });

        return view('laralyze::cards.slow-queries', [
            'queries' => $queries,
            'connections' => $queries->pluck('connection')->unique()->count() > 1,
        ]);
    }
}
