<?php

namespace MohammedMojaly\Laralyze\Livewire;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Range;
use MohammedMojaly\Laralyze\Laralyze;
use MohammedMojaly\Laralyze\Recorders\Recorder;
use stdClass;

/**
 * Base for every dashboard card, built-in or your own. It knows the
 * selected period and reads Laralyze's data for it.
 *
 *     $routes = $this->aggregate('request', ['count', 'avg', 'p95'], orderBy: 'count');
 */
abstract class Card extends Component
{
    use Concerns\AuthorizesAccess;

    /**
     * The period chosen in the top bar: 15m, 1h, 24h, 7d, 14d or 30d.
     */
    #[Url]
    public string $period = '1h';

    /**
     * Grid columns to span, 1 to 12, or 'full'.
     */
    public int|string $cols = 'full';

    /**
     * Grid rows to span.
     */
    public int|string $rows = 1;

    /**
     * Extra CSS classes for the card.
     */
    public string $class = '';

    /**
     * Seconds between refreshes while the card is on screen. 0 turns it off.
     */
    public int $poll = 15;

    /**
     * Seconds that query results are shared between viewers.
     */
    protected int $cacheFor = 5;

    /**
     * Milliseconds spent on the queries behind this render.
     */
    protected float $queryTime = 0;

    public function range(): Range
    {
        return Range::fromQuery($this->period);
    }

    public function queryTime(): float
    {
        return $this->queryTime;
    }

    public function placeholder(): View
    {
        return view('laralyze::components.placeholder', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
        ]);
    }

    /**
     * Totals per key over the period, e.g. every route with its p95.
     *
     * @param  list<string>  $aggregates  count, sum, min, max, avg, and percentiles like p95.
     * @return Collection<int, stdClass>
     */
    protected function aggregate(string $type, array $aggregates, ?string $orderBy = null, int $limit = 100): Collection
    {
        return $this->objects($this->remember(
            ['aggregate', $type, $aggregates, $orderBy, $limit],
            fn (Storage $storage, int $window) => $this->arrays($storage->aggregate($type, $aggregates, $window, $orderBy, $limit)),
        ));
    }

    /**
     * One set of totals across every key of a type, or for one key.
     *
     * @param  list<string>  $aggregates
     */
    protected function total(string $type, array $aggregates, ?string $key = null): stdClass
    {
        return (object) $this->remember(
            ['total', $type, $aggregates, $key],
            fn (Storage $storage, int $window) => (array) $storage->total($type, $aggregates, $window, $key),
        );
    }

    /**
     * About 60 points over the period, keyed by timestamp. Gaps are null.
     *
     * @return Collection<int, float|null>
     */
    protected function graph(string $type, string $aggregate, ?string $key = null): Collection
    {
        return collect($this->remember(
            ['graph', $type, $aggregate, $key],
            fn (Storage $storage, int $window) => $storage->graph($type, $aggregate, $window, $key)->all(),
        ));
    }

    /**
     * How many different keys had data in each slot, e.g. signed-in users.
     *
     * @return Collection<int, float|null>
     */
    protected function graphKeys(string $type): Collection
    {
        return collect($this->remember(
            ['graphKeys', $type],
            fn (Storage $storage, int $window) => $storage->graphKeys($type, $window)->all(),
        ));
    }

    /**
     * How many different keys had data over the period.
     */
    protected function countKeys(string $type): int
    {
        return $this->remember(
            ['countKeys', $type],
            fn (Storage $storage, int $window) => $storage->countKeys($type, $window),
        );
    }

    /**
     * The latest values set with Laralyze::set().
     *
     * @param  list<string>|null  $keys
     * @return Collection<int, stdClass>
     */
    protected function values(string $type, ?array $keys = null): Collection
    {
        return $this->objects($this->remember(
            ['values', $type, $keys],
            fn (Storage $storage) => $this->arrays($storage->values($type, $keys)),
        ));
    }

    /**
     * How many keys of a type were set in the last few seconds.
     */
    protected function countValues(string $type, int $seconds): int
    {
        return $this->remember(
            ['countValues', $type, $seconds],
            fn (Storage $storage) => $storage->countValues($type, $seconds),
        );
    }

    /**
     * Counts per key, for joining extra columns onto a table.
     *
     * @return array<string, float>
     */
    protected function counts(string $type, int $limit = 1_000): array
    {
        return $this->aggregate($type, ['count'], limit: $limit)
            ->mapWithKeys(fn (stdClass $row) => [(string) $row->key => (float) $row->count])
            ->all();
    }

    /**
     * Sums per key, e.g. tokens per model.
     *
     * @return array<string, float>
     */
    protected function sums(string $type, int $limit = 1_000): array
    {
        return $this->aggregate($type, ['sum'], limit: $limit)
            ->mapWithKeys(fn (stdClass $row) => [(string) $row->key => (float) $row->sum])
            ->all();
    }

    /**
     * The configured recorder of a class (or your subclass of it), built
     * with its options, e.g. to show the thresholds it records with.
     *
     * @template TRecorder of Recorder
     *
     * @param  class-string<TRecorder>  $class
     * @return TRecorder|null
     */
    protected function recorder(string $class): ?Recorder
    {
        foreach ((array) config('laralyze.recorders', []) as $configured => $options) {
            if (is_a((string) $configured, $class, true)) {
                $recorder = app()->make((string) $configured, ['laralyze' => app(Laralyze::class), 'config' => (array) $options]);

                return $recorder instanceof $class ? $recorder : null;
            }
        }

        return null;
    }

    /**
     * The page of one row, e.g. a route or a query, for the same period.
     */
    public function groupUrl(string $page, string $key): string
    {
        $query = $this->range() === Range::Hour ? [] : ['period' => $this->range()->value];

        return route('laralyze.group', ['page' => $page, 'group' => hash('xxh128', $key), ...$query]);
    }

    /**
     * Keys that hold several parts are stored as JSON, e.g. [class, location].
     *
     * @return list<string>
     */
    protected function parts(string $key): array
    {
        $parts = json_decode($key, true);

        return is_array($parts) ? array_values(array_map(fn ($part) => (string) $part, $parts)) : [$key];
    }

    /**
     * Run a query once per period for everyone looking at the dashboard.
     *
     * Return arrays and scalars only: Laravel 13 refuses to rebuild objects
     * from the cache unless the app allows their classes.
     *
     * @template T of array<mixed>|scalar|null
     *
     * @param  array<int, mixed>  $key
     * @param  Closure(Storage, int): T  $query
     * @return T
     */
    protected function remember(array $key, Closure $query): mixed
    {
        $range = $this->range();
        $cacheKey = 'laralyze:card:'.hash('xxh128', serialize([$range->value, ...$key]));

        [$value, $milliseconds] = app(Laralyze::class)->ignore(fn () => Cache::remember(
            $cacheKey,
            $this->cacheFor,
            fn () => Benchmark::value(fn () => $query(app(Storage::class), $range->seconds())),
        ));

        $this->queryTime += $milliseconds;

        return $value;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function arrays(Collection $rows): array
    {
        return array_values($rows->map(fn (stdClass $row): array => get_object_vars($row))->all());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, stdClass>
     */
    private function objects(array $rows): Collection
    {
        return collect($rows)->map(fn (array $row) => (object) $row);
    }
}
