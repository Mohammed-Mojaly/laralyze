<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Laralyze\Recorders\Requests;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * Calls your app makes to other services, by URL, with failures.
 */
#[Lazy]
class OutgoingRequests extends Card
{
    public int $limit = 100;

    public function render(): View
    {
        $statuses = [];
        $series = [];
        $counts = [];

        foreach ([...Requests::STATUS_CLASSES, 'failed'] as $class) {
            $statuses[$class] = (float) ($this->total("http_{$class}", ['count'])->count ?? 0);
            $series[$class] = $this->graph("http_{$class}", 'count');
            $counts[$class] = $this->counts("http_{$class}");
        }

        $timings = $this->aggregate('http', ['count', 'avg', 'p95'], orderBy: 'count', limit: $this->limit)->keyBy('key');

        $requests = $timings->keys()->merge(array_keys($counts['failed']))->unique()
            ->map(fn (int|string $key) => (string) $key)
            ->map(function (string $key) use ($timings, $counts) {
                $request = new stdClass;
                [$request->method, $request->url] = array_pad(explode(' ', $key, 2), 2, '');
                $request->avg = $timings[$key]->avg ?? null;
                $request->p95 = $timings[$key]->p95 ?? null;

                foreach ($counts as $class => $byKey) {
                    $request->{$class} = $byKey[$key] ?? 0.0;
                }

                $request->count = ($timings[$key]->count ?? 0) + $request->failed;

                return $request;
            })
            ->sortByDesc('count')
            ->values();

        return view('laralyze::cards.outgoing-requests', [
            'statuses' => $statuses,
            'total' => array_sum($statuses),
            'series' => $series,
            'requests' => $requests,
        ]);
    }
}
