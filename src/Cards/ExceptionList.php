<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Livewire\Concerns\ListsRows;
use stdClass;

/**
 * Exceptions by class and location, handled and unhandled, with the
 * latest message and how many users ran into each.
 */
#[Lazy]
class ExceptionList extends Card
{
    use ListsRows;

    public string $sort = 'latest';

    /**
     * all, handled or unhandled.
     */
    public string $show = 'all';

    public int $limit = 100;

    public function render(): View
    {
        $exceptions = $this->exceptions();

        return view('laralyze::cards.exception-list', [
            'exceptions' => $this->arrange($exceptions->filter(fn (stdClass $exception) => match ($this->show) {
                'handled' => $exception->handled > 0,
                'unhandled' => $exception->unhandled > 0,
                default => true,
            }), 'search'),
            'unhandledCount' => $exceptions->where('unhandled', '>', 0)->count(),
            'totals' => [
                'handled' => (float) ($this->total('exception_handled', ['count'])->count ?? 0),
                'unhandled' => (float) ($this->total('exception_unhandled', ['count'])->count ?? 0),
            ],
            'series' => [
                'handled' => $this->graph('exception_handled', 'count'),
                'unhandled' => $this->graph('exception_unhandled', 'count'),
            ],
        ]);
    }

    /**
     * @return Collection<int, stdClass>
     */
    protected function exceptions(): Collection
    {
        $unhandled = $this->counts('exception_unhandled');
        $exceptions = $this->aggregate('exception', ['count', 'max'], orderBy: 'count', limit: $this->limit);
        $messages = $this->values('exception_message', array_values($exceptions->pluck('key')->map(fn ($key) => (string) $key)->all()))->pluck('value', 'key');
        $users = $this->usersPerException();

        return $exceptions->each(function (stdClass $exception) use ($unhandled, $messages, $users) {
            [$exception->class, $exception->location] = array_pad($this->parts((string) $exception->key), 2, '');
            $exception->unhandled = $unhandled[$exception->key] ?? 0.0;
            $exception->handled = max(0, $exception->count - $exception->unhandled);
            $exception->latest = $exception->max;
            $exception->message = $messages[$exception->key] ?? null;
            $exception->users = $users[hash('xxh128', (string) $exception->key)] ?? 0;
            $exception->search = "{$exception->class} {$exception->message} {$exception->location}";
        });
    }

    /**
     * Users are recorded as "<exception hash>:<user id>"; count them per exception.
     *
     * @return array<string, int>
     */
    protected function usersPerException(): array
    {
        $users = [];

        foreach (array_keys($this->counts('exception_user', 5_000)) as $key) {
            $hash = substr((string) $key, 0, 32);
            $users[$hash] = ($users[$hash] ?? 0) + 1;
        }

        return $users;
    }

    protected function sortable(): array
    {
        return ['latest', 'count', 'users', 'class'];
    }

    /**
     * @return list<string>
     */
    protected function textColumns(): array
    {
        return ['class'];
    }
}
