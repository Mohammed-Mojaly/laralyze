<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use stdClass;

/**
 * Problems found in how the app queries: N+1 (the same read again and
 * again with other values) and duplicates (the same read, same values),
 * with where it happens and how to fix it.
 */
#[Lazy]
class Findings extends Card
{
    public const TYPES = ['n_plus_one' => 'N+1', 'duplicate_query' => 'Duplicate'];

    public function render(): View
    {
        $findings = collect();

        foreach (self::TYPES as $type => $label) {
            $findings = $findings->merge($this->aggregate($type, ['count', 'max'], orderBy: 'count', limit: 50)->map(function (stdClass $row) use ($type, $label) {
                [$row->sql, $row->location] = array_pad($this->parts((string) $row->key), 2, '');
                $row->type = $type;
                $row->label = $label;
                $row->hint = $this->hint($type, (string) $row->sql, (int) $row->max);

                return $row;
            }));
        }

        $examples = $this->examples($findings);

        return view('laralyze::cards.findings', [
            'findings' => $findings->sortByDesc('count')->values()->each(fn (stdClass $row) => $row->example = $examples[(string) $row->key] ?? null),
        ]);
    }

    /**
     * @param  Collection<int, stdClass>  $findings
     * @return array<string, string>
     */
    protected function examples(Collection $findings): array
    {
        if ($findings->isEmpty()) {
            return [];
        }

        return $this->values('finding_example', array_values($findings->map(fn (stdClass $row) => (string) $row->key)->all()))
            ->pluck('value', 'key')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();
    }

    /**
     * How to fix it. For an N+1, a guess at the relation from the SQL.
     */
    public function hint(string $type, string $sql, int $times): string
    {
        if ($type === 'duplicate_query') {
            return "The same query with the same values ran up to {$times} times in one execution. Keep the result in a variable, or cache it for the request with once().";
        }

        $quote = '[`"\[\]]?';

        if (preg_match("/from\s+{$quote}(\w+){$quote}\s+where\s+(?:{$quote}\w+{$quote}\.)?{$quote}(\w+){$quote}\s*(?:=|in\b)/i", $sql, $match)) {
            [, $table, $column] = $match;

            $relation = $column === 'id' ? Str::camel(Str::singular($table)) : Str::camel($table);

            return "Each row loads its {$table} one query at a time, up to {$times} in one execution. Eager load the relation, probably ->with('{$relation}'), or ->load('{$relation}') on a collection you already have.";
        }

        return "The same query ran up to {$times} times in one execution with different values. Load them together, e.g. with whereIn() or eager loading.";
    }
}
