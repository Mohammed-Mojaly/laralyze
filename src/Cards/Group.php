<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Recorders\Requests;
use MohammedMojaly\Laralyze\Support\Format;

/**
 * Everything about one route, job, command, query, outgoing URL or user:
 * how often it ran, how it went, and how long it took.
 */
#[Lazy]
class Group extends Card
{
    /**
     * The page it belongs to: requests, jobs, commands, queries, outgoing-requests or users.
     */
    #[Locked]
    public string $page = '';

    /**
     * The route, class, command name, SQL, URL or user id.
     */
    #[Locked]
    public string $name = '';

    public function render(): View
    {
        [$type, $outcomes, $percentiles] = $this->definition();

        $series = [];
        $counts = [];

        foreach ($outcomes as $outcome => $outcomeType) {
            $series[$outcome] = $this->graph($outcomeType, 'count', $this->name);
            $counts[$outcome] = (float) ($this->total($outcomeType, ['count'], $this->name)->count ?? 0);
        }

        // Jobs and commands count every run; the ones that went fine are the rest.
        if (in_array($this->page, ['jobs', 'commands'], true)) {
            $failed = $series['failed'];
            $series['processed'] = $series['processed']->map(fn (?float $runs, int $slot) => $runs === null ? null : max(0, $runs - ($failed[$slot] ?? 0)));
            $counts['processed'] = max(0, $counts['processed'] - $counts['failed']);
            $series = ['processed' => $series['processed'], 'failed' => $failed];
        }

        $totals = $this->total($type, $percentiles ? ['count', 'sum', 'avg', 'max', 'p95', 'p99'] : ['count', 'sum', 'avg', 'max'], $this->name);

        return view('laralyze::cards.group', [
            'series' => $series,
            'counts' => $counts,
            'calls' => array_sum($counts),
            'totals' => $totals,
            'percentiles' => $percentiles,
            'durations' => $this->durations($type, $percentiles),
            'details' => $this->details(),
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, string>, 2: bool} [timing type, outcome => type, has percentiles]
     */
    protected function definition(): array
    {
        $statuses = fn (string $prefix) => collect(Requests::STATUS_CLASSES)->mapWithKeys(fn (string $class) => [$class => "{$prefix}_{$class}"])->all();

        return match ($this->page) {
            'requests' => ['request', $statuses('request'), true],
            'outgoing-requests' => ['http', [...$statuses('http'), 'failed' => 'http_failed'], true],
            'jobs' => ['job', ['processed' => 'job', 'failed' => 'job_failed'], true],
            'commands' => ['command', ['processed' => 'command', 'failed' => 'command_failed'], true],
            'queries' => ['query', ['calls' => 'query'], true],
            'users' => ['user_request', $statuses('user_request'), false],
            default => abort(404),
        };
    }

    /**
     * Extra lines for the info box, label => [value, css class].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    protected function details(): array
    {
        if ($this->page !== 'users') {
            return [];
        }

        $value = $this->values('user', [$this->name])->first();
        $about = json_decode((string) ($value->value ?? '[]'), true) ?: [];
        $count = fn (string $type) => (float) ($this->total($type, ['count'], $this->name)->count ?? 0);

        $details = ['ID' => [$this->name, '']];

        if (($about['extra'] ?? '') !== '') {
            $details['Email'] = [(string) $about['extra'], ''];
        }

        $details['Last seen'] = [isset($value->timestamp) ? now()->setTimestamp((int) $value->timestamp)->diffForHumans() : '—', ''];

        foreach (['Slow requests' => ['user_slow_request', 'lz-warn'], 'Jobs queued' => ['user_job', ''], 'Exceptions' => ['user_exception', 'lz-bad']] as $label => [$type, $class]) {
            $n = $count($type);
            $details[$label] = [Format::number($n), $n > 0 ? $class : ''];
        }

        return $details;
    }

    /**
     * @return array<string, Collection<int, float|null>>
     */
    protected function durations(string $type, bool $percentiles): array
    {
        return $percentiles
            ? ['avg' => $this->graph($type, 'avg', $this->name), 'p95' => $this->graph($type, 'p95', $this->name)]
            : ['avg' => $this->graph($type, 'avg', $this->name), 'max' => $this->graph($type, 'max', $this->name)];
    }
}
