<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Dashboard\Issues;
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

    /**
     * Rows in the compact list.
     */
    public const COMPACT_ROWS = 5;

    public string $sort = 'latest';

    /**
     * all, handled or unhandled.
     */
    public string $show = 'all';

    /**
     * open (with reopened), resolved or ignored.
     */
    public string $status = 'open';

    public int $limit = 100;

    /**
     * Only the open ones seen most, without the list's controls, as on the dashboard.
     */
    public bool $compact = false;

    public function render(): View
    {
        $exceptions = $this->exceptions();

        if ($this->compact) {
            [$this->status, $this->show, $this->search, $this->sort, $this->direction] = ['open', 'all', '', 'count', 'desc'];
        }
        $inStatus = $exceptions->filter(fn (stdClass $exception) => match ($this->status) {
            'resolved' => $exception->status === Issues::RESOLVED,
            'ignored' => $exception->status === Issues::IGNORED,
            default => in_array($exception->status, [Issues::OPEN, Issues::REOPENED], true),
        });

        return view('laralyze::cards.exception-list', [
            'exceptions' => $this->arrange($inStatus->filter(fn (stdClass $exception) => match ($this->show) {
                'handled' => $exception->handled > 0,
                'unhandled' => $exception->unhandled > 0,
                default => true,
            }), 'search')->when($this->compact, fn (Collection $rows) => $rows->take(self::COMPACT_ROWS)),
            'unhandledCount' => $inStatus->where('unhandled', '>', 0)->count(),
            'statusCounts' => [
                'open' => $exceptions->whereIn('status', [Issues::OPEN, Issues::REOPENED])->count(),
                'resolved' => $exceptions->where('status', Issues::RESOLVED)->count(),
                'ignored' => $exceptions->where('status', Issues::IGNORED)->count(),
            ],
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
        $statuses = app(Issues::class)->statuses($exceptions->mapWithKeys(fn (stdClass $exception) => [(string) $exception->key => $exception->max])->all());

        return $exceptions->each(function (stdClass $exception) use ($unhandled, $messages, $users, $statuses) {
            [$exception->class, $exception->location] = array_pad($this->parts((string) $exception->key), 2, '');
            $exception->unhandled = $unhandled[$exception->key] ?? 0.0;
            $exception->handled = max(0, $exception->count - $exception->unhandled);
            $exception->latest = $exception->max;
            $exception->message = $messages[$exception->key] ?? null;
            $exception->users = $users[hash('xxh128', (string) $exception->key)] ?? 0;
            $exception->search = "{$exception->class} {$exception->message} {$exception->location}";
            $exception->status = $statuses[(string) $exception->key] ?? Issues::OPEN;
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
