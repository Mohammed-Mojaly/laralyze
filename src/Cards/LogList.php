<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Recorders\Logs;
use stdClass;

/**
 * Log entries, newest first: search their messages, pick levels and a user,
 * and open one in place for its context and where it was written.
 */
#[Lazy]
class LogList extends Card
{
    /**
     * @var list<string>
     */
    public array $levels = [];

    public string $search = '';

    public string $user = '';

    public int $page = 1;

    public int $limit = 50;

    /**
     * The entry shown open, by its uuid.
     */
    public string $open = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['levels', 'search', 'user'], true)) {
            [$this->page, $this->open] = [1, ''];
        }
    }

    public function toggleLevel(string $level): void
    {
        $this->levels = in_array($level, $this->levels, true)
            ? array_values(array_diff($this->levels, [$level]))
            : [...$this->levels, $level];

        $this->updated('levels');
    }

    public function toggle(string $uuid): void
    {
        $this->open = $this->open === $uuid ? '' : $uuid;
    }

    public function previousPage(): void
    {
        [$this->page, $this->open] = [max(1, $this->page - 1), ''];
    }

    public function nextPage(): void
    {
        [$this->page, $this->open] = [$this->page + 1, ''];
    }

    public function render(): View
    {
        $page = max(1, $this->page);
        $limit = max(1, min(200, $this->limit));
        $filters = array_filter([
            'levels' => array_values(array_intersect(Logs::LEVELS, array_filter($this->levels, 'is_string'))),
            'search' => trim($this->search),
            'user' => $this->user,
        ], fn (array|string $value) => $value !== [] && $value !== '');

        $rows = collect($this->remember(
            ['logs', $filters, $limit, $page],
            fn (Storage $storage, int $window) => $storage->logs($filters, $window, $limit + 1, ($page - 1) * $limit)->map(fn (stdClass $row) => (array) $row)->all(),
        ))->map(fn (array $row) => (object) $row);

        $logs = $rows->take($limit)->values();
        $opened = $logs->firstWhere('uuid', $this->open);
        $userIds = $this->remember(['log_users'], fn (Storage $storage, int $window) => $storage->logUsers($window));

        return view('laralyze::cards.log-list', [
            'logs' => $logs,
            'opened' => $opened,
            // Only a run that was kept with its timeline can be opened.
            'kept' => $opened?->execution !== null && app(Storage::class)->keptExecutions([$opened->execution]) !== [],
            'levelCounts' => $this->levelCounts(),
            'users' => $this->userNames([...$userIds, ...$logs->pluck('user_id')->filter()->all()]),
            'userIds' => $userIds,
            'more' => $rows->count() > $limit,
            'current' => $page,
            'filtered' => $filters !== [],
        ]);
    }

    /**
     * Levels kept as entries, with how many were logged.
     *
     * @return array<string, float>
     */
    protected function levelCounts(): array
    {
        $counts = $this->counts('log');
        $lowest = array_search(strtolower((string) config('laralyze.recorders.'.Logs::class.'.level', 'info')), Logs::LEVELS, true);
        $kept = array_slice(Logs::LEVELS, 0, $lowest === false ? 7 : $lowest + 1);

        return collect($kept)
            ->filter(fn (string $level) => ($counts[$level] ?? 0) > 0 || in_array($level, $this->levels, true))
            ->mapWithKeys(fn (string $level) => [$level => (float) ($counts[$level] ?? 0)])
            ->all();
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return array<string, string>
     */
    protected function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => is_scalar($id) ? (string) $id : '', $ids))));

        return $ids === [] ? [] : $this->values('user', $ids)
            ->mapWithKeys(fn (stdClass $row) => [(string) $row->key => (string) (json_decode((string) $row->value, true)['name'] ?? $row->key)])
            ->all();
    }
}
