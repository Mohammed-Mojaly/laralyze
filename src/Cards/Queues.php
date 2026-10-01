<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * Jobs per queue: queued, processed, released and failed, with wait times.
 */
#[Lazy]
class Queues extends Card
{
    public const OUTCOMES = ['processed', 'released', 'failed'];

    public function render(): View
    {
        $totals = ['queued' => (float) ($this->total('queue_queued', ['count'])->count ?? 0)];
        $series = [];
        $counts = ['queued' => $this->counts('queue_queued')];

        foreach (self::OUTCOMES as $outcome) {
            $totals[$outcome] = (float) ($this->total("queue_{$outcome}", ['count'])->count ?? 0);
            $series[$outcome] = $this->graph("queue_{$outcome}", 'count');
            $counts[$outcome] = $this->counts("queue_{$outcome}");
        }

        $waits = $this->aggregate('queue_wait', ['avg', 'max'])->keyBy('key');
        $names = collect($counts)->flatMap(fn (array $byQueue) => array_keys($byQueue))->unique()->sort()->values();

        $queues = $names->map(function (string $name) use ($counts, $waits) {
            $queue = new stdClass;
            $queue->name = $name;

            foreach ($counts as $type => $byQueue) {
                $queue->{$type} = $byQueue[$name] ?? 0.0;
            }

            $queue->wait = $waits[$name]->avg ?? null;
            $queue->max_wait = $waits[$name]->max ?? null;

            return $queue;
        });

        return view('laralyze::cards.queues', [
            'totals' => $totals,
            'series' => $series,
            'queues' => $queues,
        ]);
    }
}
