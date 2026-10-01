<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * Every job class with its runs, failures and timings.
 */
#[Lazy]
class JobList extends Card
{
    public int $limit = 100;

    public function render(): View
    {
        $failed = $this->counts('job_failed');

        $jobs = $this->aggregate('job', ['count', 'avg', 'p95', 'max'], orderBy: 'count', limit: $this->limit)
            ->each(fn (stdClass $job) => $job->failed = $failed[$job->key] ?? 0.0);

        return view('laralyze::cards.job-list', ['jobs' => $jobs]);
    }
}
