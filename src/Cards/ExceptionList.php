<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * Exceptions by class and location, handled and unhandled, with the
 * latest message.
 */
#[Lazy]
class ExceptionList extends Card
{
    public int $limit = 50;

    public function render(): View
    {
        $unhandled = $this->counts('exception_unhandled');

        $exceptions = $this->aggregate('exception', ['count', 'max'], orderBy: 'count', limit: $this->limit);
        $messages = $this->values('exception_message', array_values($exceptions->pluck('key')->map(fn ($key) => (string) $key)->all()))->pluck('value', 'key');

        $exceptions->each(function (stdClass $exception) use ($unhandled, $messages) {
            [$exception->class, $exception->location] = array_pad($this->parts((string) $exception->key), 2, '');
            $exception->unhandled = $unhandled[$exception->key] ?? 0.0;
            $exception->latest = $exception->max;
            $exception->message = $messages[$exception->key] ?? null;
        });

        return view('laralyze::cards.exception-list', [
            'exceptions' => $exceptions,
            'totals' => [
                'handled' => (float) ($this->total('exception_handled', ['count'])->count ?? 0),
                'unhandled' => (float) ($this->total('exception_unhandled', ['count'])->count ?? 0),
            ],
            'series' => [
                'handled' => $this->graph('exception_handled', 'count'),
                'unhandled' => $this->graph('exception_unhandled', 'count'),
            ],
        ]);
    }
}
