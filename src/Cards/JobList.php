<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Livewire\Concerns\ListsRows;
use stdClass;

/**
 * Every job class with its runs, failures and timings.
 */
#[Lazy]
class JobList extends Card
{
    use ListsRows;

    public string $sort = 'count';

    public int $limit = 100;

    public function render(): View
    {
        $failed = $this->counts('job_failed');

        $jobs = $this->aggregate('job', ['count', 'avg', 'p95', 'max'], orderBy: 'count', limit: $this->limit)
            ->each(fn (stdClass $job) => $job->failed = $failed[$job->key] ?? 0.0);

        return view('laralyze::cards.job-list', ['jobs' => $this->arrange($jobs)]);
    }

    protected function sortable(): array
    {
        return ['count', 'key', 'failed', 'avg', 'p95', 'max'];
    }
}
