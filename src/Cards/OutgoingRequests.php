<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Livewire\Concerns\ListsRows;
use MohammedMojaly\Laralyze\Recorders\Requests;
use stdClass;

/**
 * Calls your app makes to other services, by URL, with failures.
 */
#[Lazy]
class OutgoingRequests extends Card
{
    use ListsRows;

    public string $sort = 'count';

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
                $request->key = $key;
                [$request->method, $request->url] = array_pad(explode(' ', $key, 2), 2, '');
                $request->avg = $timings[$key]->avg ?? null;
                $request->p95 = $timings[$key]->p95 ?? null;

                foreach ($counts as $class => $byKey) {
                    $request->{$class} = $byKey[$key] ?? 0.0;
                }

                $request->ok = $request->{'2xx'} + $request->{'3xx'};
                $request->count = ($timings[$key]->count ?? 0) + $request->failed;

                return $request;
            });

        return view('laralyze::cards.outgoing-requests', [
            'statuses' => $statuses,
            'total' => array_sum($statuses),
            'series' => $series,
            'requests' => $this->arrange($requests->values()),
        ]);
    }

    protected function sortable(): array
    {
        return ['count', 'key', 'ok', '4xx', '5xx', 'failed', 'avg', 'p95'];
    }
}
