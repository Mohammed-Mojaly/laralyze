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
 * Single requests, jobs or commands that were kept, newest or slowest
 * first, each opening its timeline. Narrowed to one route, job, command,
 * user or exception by the page it sits on, and by status and speed.
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

    /**
     * all, ok or failed.
     */
    public string $status = 'all';

    /**
     * all, avg or p95: only the ones at least that slow.
     */
    public string $speed = 'all';

    public int $page = 1;

    public int $limit = 25;

    public function updated(string $property): void
    {
        if (in_array($property, ['order', 'status', 'speed'], true)) {
            $this->page = 1;
        }
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function render(): View
    {
        $order = in_array($this->order, ['slowest', 'recent'], true) ? $this->order : 'slowest';
        $page = max(1, $this->page);
        $speeds = $this->speeds();

        $filters = array_filter([
            'type' => $this->type,
            'name' => $this->name,
            'user' => $this->user,
            'exception' => $this->exception === '' ? '' : hash('xxh128', $this->exception),
        ], fn (string $value) => $value !== '');

        if (in_array($this->status, ['ok', 'failed'], true)) {
            $filters['failed'] = $this->status === 'failed';
        }

        if (isset($speeds[$this->speed])) {
            $filters['slower'] = $speeds[$this->speed];
        }

        $rows = collect($this->remember(
            ['executions', $filters, $order, $this->limit, $page],
            fn (DatabaseStorage $storage, int $window) => $storage->executions($filters, $window, $order, $this->limit + 1, ($page - 1) * $this->limit)->map(fn (stdClass $row) => (array) $row)->all(),
        ))->map(fn (array $row) => (object) $row);

        $executions = $rows->take($this->limit)->values();

        return view('laralyze::cards.execution-list', [
            'executions' => $executions,
            'users' => $this->userNames($executions),
            'order' => $order,
            'speeds' => $speeds,
            'more' => $rows->count() > $this->limit,
            'current' => $page,
        ]);
    }

    /**
     * The route's, job's or command's average and p95, to filter by.
     *
     * @return array<string, float>
     */
    protected function speeds(): array
    {
        if ($this->name === '' || ! in_array($this->type, ['request', 'job', 'command'], true)) {
            return [];
        }

        $totals = $this->total($this->type, ['avg', 'p95'], $this->name);

        return array_filter(['avg' => (float) ($totals->avg ?? 0), 'p95' => (float) ($totals->p95 ?? 0)], fn (float $ms) => $ms > 0);
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
