<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Livewire\Concerns\ListsRows;
use MohammedMojaly\Laralyze\Recorders\Queries;
use stdClass;

/**
 * Queries grouped by their SQL, the most expensive first.
 */
#[Lazy]
class QueryList extends Card
{
    use ListsRows;

    public string $sort = 'sum';

    public int $limit = 100;

    /**
     * Only this connection's queries; every connection when empty.
     */
    #[Url(except: '')]
    public string $connection = '';

    /**
     * Picked in the Queries page's top bar.
     */
    #[On('laralyze-connection')]
    public function filterConnection(string $connection): void
    {
        $this->connection = $connection;
    }

    public function render(): View
    {
        $queries = $this->aggregate('query', ['count', 'sum', 'avg', 'p95', 'max'], orderBy: 'sum', limit: $this->limit, keyPrefix: $this->connection === '' ? null : Queries::prefix($this->connection))
            ->each(function (stdClass $query) {
                [$query->connection, $query->sql] = Queries::parts((string) $query->key);
            });

        return view('laralyze::cards.query-list', [
            'queries' => $this->arrange($queries, 'sql'),
            'connections' => $queries->pluck('connection')->unique()->count() > 1,
        ]);
    }

    protected function sortable(): array
    {
        return ['sum', 'sql', 'count', 'avg', 'p95', 'max'];
    }

    /**
     * @return list<string>
     */
    protected function textColumns(): array
    {
        return ['sql'];
    }
}
