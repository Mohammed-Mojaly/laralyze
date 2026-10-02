<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;
use stdClass;

/**
 * Single requests, jobs or commands that were kept, slowest or newest
 * first, each opening its timeline. Narrowed to one route, job, command,
 * user or exception by the page it sits on.
 */
#[Lazy]
class ExecutionList extends Card
{
    /**
     * request, job or command; empty for all.
     */
    #[Locked]
    public string $type = '';

    #[Locked]
    public string $name = '';

    #[Locked]
    public string $user = '';

    /**
     * An exception's key, to list where it happened.
     */
    #[Locked]
    public string $exception = '';

    #[Locked]
    public string $title = 'Executions';

    /**
     * slowest or recent.
     */
    public string $order = 'slowest';

    public int $limit = 50;

    public function render(): View
    {
        $order = in_array($this->order, ['slowest', 'recent'], true) ? $this->order : 'slowest';

        $filters = array_filter([
            'type' => $this->type,
            'name' => $this->name,
            'user' => $this->user,
            'exception' => $this->exception === '' ? '' : hash('xxh128', $this->exception),
        ], fn (string $value) => $value !== '');

        $executions = collect($this->remember(
            ['executions', $filters, $order, $this->limit],
            fn (DatabaseStorage $storage, int $window) => $storage->executions($filters, $window, $order, $this->limit)->map(fn (stdClass $row) => (array) $row)->all(),
        ))->map(fn (array $row) => (object) $row);

        return view('laralyze::cards.execution-list', [
            'executions' => $executions,
            'users' => $this->userNames($executions),
            'order' => $order,
        ]);
    }

    /**
     * @param  Collection<int, stdClass>  $executions
     * @return array<string, string>
     */
    protected function userNames(Collection $executions): array
    {
        $ids = $executions->pluck('user_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();

        if ($ids === [] || $this->user !== '') {
            return [];
        }

        return $this->values('user', array_values($ids))
            ->mapWithKeys(fn (stdClass $row) => [(string) $row->key => (string) (json_decode((string) $row->value, true)['name'] ?? $row->key)])
            ->all();
    }
}
