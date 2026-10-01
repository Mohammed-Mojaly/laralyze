<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * Every scheduled task: its runs, failures and skips over the period,
 * and how its last run went.
 */
#[Lazy]
class ScheduledTaskList extends Card
{
    public function render(): View
    {
        $runs = $this->aggregate('scheduled', ['count', 'avg', 'max'])->keyBy('key');
        $failed = $this->counts('scheduled_failed');
        $skipped = $this->counts('scheduled_skipped');
        $latest = $this->values('scheduled_task')->keyBy('key');

        $tasks = $latest->keys()->merge($runs->keys())->merge(array_keys($skipped))->unique()->sort()->values()
            ->map(fn (int|string $name) => (string) $name)
            ->map(function (string $name) use ($runs, $failed, $skipped, $latest) {
                $last = json_decode((string) ($latest[$name]->value ?? '[]'), true) ?: [];

                $task = new stdClass;
                $task->name = $name;
                $task->runs = $runs[$name]->count ?? 0.0;
                $task->avg = $runs[$name]->avg ?? null;
                $task->max = $runs[$name]->max ?? null;
                $task->failed = $failed[$name] ?? 0.0;
                $task->skipped = $skipped[$name] ?? 0.0;
                $task->expression = $last['expression'] ?? null;
                $task->status = $last['status'] ?? null;
                $task->ran_at = $last['ran_at'] ?? null;
                $task->next_at = $last['next_at'] ?? null;

                return $task;
            });

        return view('laralyze::cards.scheduled-task-list', ['tasks' => $tasks]);
    }
}
