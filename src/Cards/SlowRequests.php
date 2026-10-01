<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Laralyze\Recorders\Requests;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * Routes that went over their slow threshold, slowest first.
 */
#[Lazy]
class SlowRequests extends Card
{
    public int $limit = 25;

    public function render(): View
    {
        $recorder = $this->recorder(Requests::class);

        $requests = $this->aggregate('slow_request', ['count', 'max'], orderBy: 'max', limit: $this->limit)
            ->each(function (stdClass $request) use ($recorder) {
                [$request->method, $request->path] = array_pad(explode(' ', (string) $request->key, 2), 2, '');
                $request->threshold = $recorder?->threshold((string) $request->key);
            });

        return view('laralyze::cards.slow-requests', [
            'requests' => $requests,
        ]);
    }
}
