<?php

namespace MohammedMojaly\Laralyze\Livewire\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use stdClass;

/**
 * A searchable list whose column headers sort it. The card declares the
 * columns it can sort by; the first one is the default.
 *
 * @property string $sort
 */
trait ListsRows
{
    public string $search = '';

    public string $direction = 'desc';

    /**
     * @return list<string>
     */
    abstract protected function sortable(): array;

    /**
     * Columns that hold names rather than numbers.
     *
     * @return list<string>
     */
    protected function textColumns(): array
    {
        return ['key'];
    }

    /**
     * Sort by a column, or flip the order when it's already the one.
     */
    public function sortBy(string $column): void
    {
        if (! in_array($column, $this->sortable(), true)) {
            return;
        }

        $this->direction = match (true) {
            $this->sort === $column => $this->direction === 'desc' ? 'asc' : 'desc',
            // Names read best A to Z, numbers biggest first.
            in_array($column, $this->textColumns(), true) => 'asc',
            default => 'desc',
        };

        $this->sort = $column;
    }

    public function sortColumn(): string
    {
        return in_array($this->sort, $this->sortable(), true) ? $this->sort : $this->sortable()[0];
    }

    /**
     * Filter rows by the search box and sort them by the chosen column.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<int, stdClass>
     */
    protected function arrange(Collection $rows, string $searchIn = 'key'): Collection
    {
        $term = trim($this->search);
        $column = $this->sortColumn();
        $text = in_array($column, $this->textColumns(), true);

        return $rows
            ->when($term !== '', fn (Collection $rows) => $rows->filter(
                fn (stdClass $row) => Str::contains((string) ($row->{$searchIn} ?? ''), $term, ignoreCase: true),
            ))
            ->sortBy(
                fn (stdClass $row) => $row->{$column} ?? ($text ? '' : -1),
                $text ? SORT_NATURAL | SORT_FLAG_CASE : SORT_REGULAR,
                $this->direction !== 'asc',
            )
            ->values();
    }
}
