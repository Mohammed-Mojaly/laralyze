<?php

namespace MohammedMojaly\Laralyze\Assistant\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Issues;
use MohammedMojaly\Laralyze\Dashboard\Range;
use MohammedMojaly\Laralyze\Recorders\Ai;
use MohammedMojaly\Laralyze\Support\Format;

/**
 * What Laralyze recorded, by topic, as tables the assistant can read.
 */
class LaralyzeData implements Tool
{
    public const TOPICS = ['overview', 'routes', 'exceptions', 'findings', 'queries', 'jobs', 'outgoing_requests', 'cache', 'ai'];

    public function __construct(protected Storage $storage, protected Issues $issues) {}

    public function description(): string
    {
        return 'Read what Laralyze recorded about the app over a period: overview (totals), routes (traffic, timings, errors), exceptions, findings (N+1 and duplicate queries), queries (by total time), jobs, outgoing_requests, cache, ai (the app\'s own AI calls).';
    }

    public function handle(Request $request): string
    {
        $range = Range::fromQuery((string) ($request['period'] ?? '24h'));
        $window = $range->seconds();
        $topic = (string) ($request['topic'] ?? 'overview');

        $data = match ($topic) {
            'routes' => $this->routes($window),
            'exceptions' => $this->exceptions($window),
            'findings' => $this->findings($window),
            'queries' => $this->table('query', ['count', 'sum', 'avg', 'p95'], $window, 'sum', ['SQL', 'Runs', 'Total', 'Avg', 'p95'], [null, 'number', 'duration', 'duration', 'duration']),
            'jobs' => $this->jobs($window),
            'outgoing_requests' => $this->table('http', ['count', 'avg', 'p95'], $window, 'count', ['URL', 'Calls', 'Avg', 'p95'], [null, 'number', 'duration', 'duration']),
            'cache' => $this->cache($window),
            'ai' => $this->ai($window),
            default => $this->overview($window),
        };

        return "## {$topic}, {$range->label()}\n\n{$data}";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->enum(self::TOPICS)->required(),
            'period' => $schema->string()->enum(array_map(fn (Range $range) => $range->value, Range::cases()))->description('Defaults to 24h.'),
        ];
    }

    protected function overview(int $window): string
    {
        $count = fn (string $type) => Format::number((float) ($this->storage->total($type, ['count'], $window)->count ?? 0));
        $requests = $this->storage->total('request', ['count', 'avg', 'p95'], $window);

        return implode("\n", [
            '- Requests: '.Format::number($requests->count ?? 0).', average '.Format::duration($requests->avg ?? null).', p95 '.Format::duration($requests->p95 ?? null),
            '- Responses 4xx: '.$count('request_4xx').', 5xx: '.$count('request_5xx'),
            '- Exceptions: '.$count('exception').' ('.$this->storage->countKeys('exception', $window).' distinct)',
            '- Jobs processed: '.$count('job').', failed: '.$count('job_failed'),
            '- Queries: '.$count('query').', slow: '.$count('slow_query'),
            '- N+1 found in: '.$count('n_plus_one').' executions, duplicate queries in: '.$count('duplicate_query'),
            '- Outgoing requests: '.$count('http').', no response: '.$count('http_failed'),
            '- AI calls: '.$count('ai').', failed: '.$count('ai_failed').', estimated cost '.Format::money((float) ($this->storage->total('ai_cost', ['sum'], $window)->sum ?? 0) / Ai::MICRO),
        ]);
    }

    protected function routes(int $window): string
    {
        $errors = $this->storage->aggregate('request_5xx', ['count'], $window, 'count', 1_000)->pluck('count', 'key');

        return $this->rows(
            ['Route', 'Requests', 'Avg', 'p95', '5xx'],
            $this->storage->aggregate('request', ['count', 'avg', 'p95'], $window, 'count', 30)
                ->map(fn ($row) => [$row->key, Format::number($row->count), Format::duration($row->avg), Format::duration($row->p95), Format::number($errors[$row->key] ?? 0)])
                ->all(),
        );
    }

    protected function exceptions(int $window): string
    {
        $rows = $this->storage->aggregate('exception', ['count', 'max'], $window, 'count', 30);
        $messages = $this->storage->values('exception_message', array_values($rows->pluck('key')->map(fn ($key) => (string) $key)->all()))->pluck('value', 'key');
        $statuses = $this->issues->statuses($rows->pluck('max', 'key')->map(fn ($max) => (float) $max)->all());

        return $this->rows(
            ['Exception', 'Where', 'Message', 'Count', 'Status'],
            $rows->map(function ($row) use ($messages, $statuses) {
                [$class, $location] = array_pad(array_map('strval', (array) json_decode((string) $row->key, true)), 2, '');

                return [$class, $location, mb_strimwidth((string) ($messages[$row->key] ?? ''), 0, 200, '…'), Format::number($row->count), (string) ($statuses[$row->key] ?? 'open')];
            })->all(),
        );
    }

    protected function findings(int $window): string
    {
        $rows = [];

        foreach (['n_plus_one' => 'N+1', 'duplicate_query' => 'Duplicate'] as $type => $label) {
            foreach ($this->storage->aggregate($type, ['count', 'max'], $window, 'count', 20) as $row) {
                [$sql, $location] = array_pad(array_map('strval', (array) json_decode((string) $row->key, true)), 2, '');
                $rows[] = [$label, $location, mb_strimwidth($sql, 0, 300, '…'), Format::number($row->count), Format::number($row->max)];
            }
        }

        return $this->rows(['Type', 'Where', 'SQL', 'Executions', 'Most in one'], $rows);
    }

    protected function jobs(int $window): string
    {
        $failed = $this->storage->aggregate('job_failed', ['count'], $window, 'count', 1_000)->pluck('count', 'key');

        return $this->rows(
            ['Job', 'Runs', 'Failed', 'Avg', 'p95'],
            $this->storage->aggregate('job', ['count', 'avg', 'p95'], $window, 'count', 30)
                ->map(fn ($row) => [$row->key, Format::number($row->count), Format::number($failed[$row->key] ?? 0), Format::duration($row->avg), Format::duration($row->p95)])
                ->all(),
        );
    }

    protected function cache(int $window): string
    {
        $misses = $this->storage->aggregate('cache_miss', ['count'], $window, 'count', 1_000)->pluck('count', 'key');

        return $this->rows(
            ['Key', 'Hits', 'Misses'],
            $this->storage->aggregate('cache_hit', ['count'], $window, 'count', 30)
                ->map(fn ($row) => [$row->key, Format::number($row->count), Format::number($misses[$row->key] ?? 0)])
                ->all(),
        );
    }

    protected function ai(int $window): string
    {
        $cost = $this->storage->aggregate('ai_cost', ['sum'], $window, 'sum', 1_000)->pluck('sum', 'key');
        $failed = $this->storage->aggregate('ai_failed', ['count'], $window, 'count', 1_000)->pluck('count', 'key');

        return $this->rows(
            ['Agent', 'Calls', 'Avg', 'Failed', 'Cost'],
            $this->storage->aggregate('ai', ['count', 'avg'], $window, 'count', 30)
                ->map(fn ($row) => [$row->key, Format::number($row->count), Format::duration($row->avg), Format::number($failed[$row->key] ?? 0), Format::money((float) ($cost[$row->key] ?? 0) / Ai::MICRO)])
                ->all(),
        );
    }

    /**
     * @param  list<string>  $aggregates
     * @param  list<string>  $headers
     * @param  list<string|null>  $formats
     */
    protected function table(string $type, array $aggregates, int $window, string $orderBy, array $headers, array $formats): string
    {
        return $this->rows($headers, $this->storage->aggregate($type, $aggregates, $window, $orderBy, 30)->map(function ($row) use ($aggregates, $formats) {
            $cells = [mb_strimwidth((string) $row->key, 0, 300, '…')];

            foreach ($aggregates as $i => $aggregate) {
                $cells[] = match ($formats[$i + 1] ?? null) {
                    'duration' => Format::duration($row->{$aggregate}),
                    default => Format::number($row->{$aggregate}),
                };
            }

            return $cells;
        })->all());
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function rows(array $headers, array $rows): string
    {
        if ($rows === []) {
            return 'Nothing recorded.';
        }

        $line = fn (array $cells) => '| '.implode(' | ', array_map(fn ($cell) => str_replace(['|', "\n"], ['\|', ' '], (string) $cell), $cells)).' |';

        return implode("\n", [$line($headers), $line(array_fill(0, count($headers), '---')), ...array_map($line, $rows)]);
    }
}
