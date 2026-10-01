<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Recorders\Jobs;
use stdClass;

/**
 * Jobs that ran longer than their threshold, slowest first.
 */
#[Lazy]
class SlowJobs extends Card
{
    public int $limit = 25;

    public function render(): View
    {
        $recorder = $this->recorder(Jobs::class);

        $jobs = $this->aggregate('slow_job', ['count', 'max'], orderBy: 'max', limit: $this->limit)
            ->each(fn (stdClass $job) => $job->threshold = $recorder?->threshold((string) $job->key));

        return view('laralyze::cards.slow-jobs', ['jobs' => $jobs]);
    }
}
