<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Livewire\Concerns\ListsRows;
use MohammedMojaly\Laralyze\Recorders\Requests;
use stdClass;

/**
 * Signed-in users with their requests by status, slow requests, jobs,
 * exceptions and when they were last seen.
 */
#[Lazy]
class UserList extends Card
{
    use ListsRows;

    public string $sort = 'requests';

    public int $limit = 100;

    public function render(): View
    {
        return view('laralyze::cards.user-list', ['users' => $this->arrange($this->users(), 'search')]);
    }

    /**
     * @return Collection<int, stdClass>
     */
    protected function users(): Collection
    {
        $counts = ['requests' => $this->counts('user_request')];

        foreach (['slow' => 'user_slow_request', 'jobs' => 'user_job', 'exceptions' => 'user_exception'] as $name => $type) {
            $counts[$name] = $this->counts($type);
        }

        foreach (Requests::STATUS_CLASSES as $class) {
            $counts[$class] = $this->counts("user_request_{$class}");
        }

        $ids = collect(array_keys($counts['requests']))->merge(array_keys($counts['jobs']))->map(fn (int|string $id) => (string) $id)->unique()
            ->sortByDesc(fn (string $id) => ($counts['requests'][$id] ?? 0) + ($counts['jobs'][$id] ?? 0))
            ->take($this->limit)
            ->values();

        $details = $this->values('user', array_values($ids->all()))->keyBy('key');

        return $ids->map(function (string $id) use ($counts, $details) {
            $about = json_decode((string) ($details[$id]->value ?? '[]'), true) ?: [];

            $user = new stdClass;
            $user->id = $id;
            $user->name = (string) ($about['name'] ?? $id);
            $user->extra = (string) ($about['extra'] ?? '');
            $user->search = "{$user->name} {$user->extra} {$id}";
            $user->seen = $details[$id]->timestamp ?? null;

            foreach ($counts as $name => $byId) {
                $user->{$name} = $byId[$id] ?? 0.0;
            }

            $user->ok = $user->{'2xx'} + $user->{'3xx'};

            return $user;
        });
    }

    protected function sortable(): array
    {
        return ['requests', 'name', 'ok', '4xx', '5xx', 'slow', 'jobs', 'exceptions', 'seen'];
    }

    /**
     * @return list<string>
     */
    protected function textColumns(): array
    {
        return ['name'];
    }
}
