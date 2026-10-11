<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Database\Errors;

/**
 * What builds up over the period: connections in use, long-running
 * queries, and database errors by kind.
 */
#[Lazy]
class DatabaseTrends extends DatabaseCard
{
    public function render(): View
    {
        $name = $this->database();
        $errors = [];

        foreach ($this->aggregate('db_error', ['count'], keyPrefix: Errors::prefix($name)) as $row) {
            $kind = (string) (json_decode((string) $row->key, true)[1] ?? '');
            $errors[$kind] = (float) $row->count;
        }

        return view('laralyze::cards.database-trends', [
            'series' => [
                'max' => $this->graph('db_connections', 'max', $name),
                'avg' => $this->graph('db_connections', 'avg', $name),
            ],
            'connections' => $this->total('db_connections', ['avg', 'max'], $name),
            'longRunning' => $this->total('db_long_running', ['max'], $name)->max ?? null,
            'errors' => $errors,
            'kinds' => Errors::KINDS,
        ]);
    }
}
