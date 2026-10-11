<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Database\Errors;
use MohammedMojaly\Laralyze\Database\Incidents;

/**
 * Every problem the minute's look found over the period, newest first,
 * each opened in place for the sessions at its worst moment or the
 * deadlock's transactions.
 */
#[Lazy]
class DatabaseIncidents extends DatabaseCard
{
    public const LIMIT = 50;

    /**
     * The incident opened in place.
     */
    public string $expanded = '';

    public function toggle(string $id): void
    {
        $this->expanded = $this->expanded === $id ? '' : $id;
    }

    public function render(): View
    {
        $name = $this->database();
        $since = now()->getTimestamp() - $this->range()->seconds();

        // Keys are "{connection}|{started at}:…", so the last ones are the newest.
        $incidents = $this->values('db_incident', keyPrefix: "{$name}|", limit: self::LIMIT)
            ->map(fn ($row) => json_decode((string) $row->value, true))
            ->filter(fn ($incident) => is_array($incident) && (int) ($incident['last_seen'] ?? 0) >= $since)
            ->values();

        $counts = [];

        foreach ($this->aggregate('db_incident', ['count'], keyPrefix: Errors::prefix($name)) as $row) {
            $counts[(string) (json_decode((string) $row->key, true)[1] ?? '')] = (float) $row->count;
        }

        return view('laralyze::cards.database-incidents', [
            'incidents' => $incidents,
            // In the order of the kinds, the ones that happened.
            'counts' => array_filter(array_replace(array_fill_keys(array_keys(Incidents::KINDS), 0), array_intersect_key($counts, Incidents::KINDS))),
            'kinds' => Incidents::KINDS,
            'deadlocks' => $this->total('db_deadlocks', ['count'], $name)->count ?? null,
            'reached' => (float) ($this->counts('db_error')[Errors::key($name, 'deadlock')] ?? 0),
        ]);
    }
}
