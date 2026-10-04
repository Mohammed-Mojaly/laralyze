<?php

namespace MohammedMojaly\Laralyze\Assistant;

use MohammedMojaly\Laralyze\Cards\ExceptionDetail;
use MohammedMojaly\Laralyze\Cards\Findings;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;
use MohammedMojaly\Laralyze\Support\Format;
use stdClass;

/**
 * What a chat is about, and everything the assistant needs to know about
 * it, from what Laralyze already keeps: an exception, an N+1 or duplicate
 * query, a query, a route, or one request, job or command.
 */
final class Subject
{
    public const KINDS = ['general', 'exception', 'n_plus_one', 'duplicate_query', 'query', 'request', 'execution'];

    protected const WEEK = 7 * 86_400;

    public function __construct(public readonly string $kind, public readonly string $key, public readonly string $label) {}

    public static function general(): self
    {
        return new self('general', '', 'Your app');
    }

    /**
     * The subject for a kind and key, or null when Laralyze has nothing on it.
     */
    public static function find(string $kind, string $key, DatabaseStorage $storage): ?self
    {
        if ($kind === 'general' || ! in_array($kind, self::KINDS, true)) {
            return self::general();
        }

        $parts = json_decode($key, true);
        [$first, $second] = is_array($parts) ? array_pad(array_map('strval', $parts), 2, '') : [$key, ''];

        $label = match ($kind) {
            'exception' => 'Exception · '.class_basename($first).($second !== '' ? ' · '.$second : ''),
            'n_plus_one' => 'N+1 · '.($second !== '' ? $second : 'query'),
            'duplicate_query' => 'Duplicate query · '.($second !== '' ? $second : 'query'),
            'query' => 'Query · '.mb_strimwidth($key, 0, 60, '…'),
            'request' => 'Route · '.$key,
            'execution' => self::executionLabel($storage->execution($key)),
            default => null,
        };

        return $label === null ? null : new self($kind, $key, $label);
    }

    /**
     * What the assistant is told about it, as Markdown.
     */
    public function context(DatabaseStorage $storage, Files $files): string
    {
        $context = match ($this->kind) {
            'exception' => $this->exception($storage, $files),
            'n_plus_one', 'duplicate_query' => $this->finding($storage, $files),
            'query' => $this->query($storage, $files),
            'request' => $this->route($storage),
            'execution' => $this->executionContext($storage->execution($this->key)),
            default => '',
        };

        return mb_strimwidth($context, 0, 30_000, "\n…");
    }

    /**
     * @return array{kind: string, key: string, label: string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'key' => $this->key, 'label' => $this->label];
    }

    protected function exception(DatabaseStorage $storage, Files $files): string
    {
        [$class, $location] = array_pad(array_map('strval', (array) json_decode($this->key, true)), 2, '');
        $details = json_decode((string) ($storage->values('exception_details', [$this->key])->first()->value ?? ''), true);
        $details = is_array($details) ? $details : [];

        $exception = (object) [
            'class' => $class,
            'location' => $location,
            'message' => (string) ($details['message'] ?? $storage->values('exception_message', [$this->key])->first()->value ?? ''),
            'previous' => $details['previous'] ?? [],
            'context' => $details['context'] ?? null,
            'source' => $details['source'] ?? null,
            'php' => $details['php'] ?? null,
            'laravel' => $details['laravel'] ?? null,
            'frames' => $details['frames'] ?? [],
        ];

        $counts = [
            'handled' => (float) ($storage->total('exception_handled', ['count'], self::WEEK, $this->key)->count ?? 0),
            'unhandled' => (float) ($storage->total('exception_unhandled', ['count'], self::WEEK, $this->key)->count ?? 0),
        ];

        $markdown = ExceptionDetail::markdown($exception, $counts, $storage->total('exception', ['max'], self::WEEK, $this->key)->max ?? null);

        // The code around where it was thrown, when the trace kept none.
        if (! str_contains($markdown, '```php') && preg_match('/^(.+):(\d+)$/', $location, $match)) {
            $markdown .= $this->code($files, $match[1], (int) $match[2]);
        }

        return "Occurrences are for the last 7 days.\n\n".$markdown;
    }

    protected function finding(DatabaseStorage $storage, Files $files): string
    {
        [$sql, $location] = array_pad(array_map('strval', (array) json_decode($this->key, true)), 2, '');
        $totals = $storage->total($this->kind, ['count', 'max'], self::WEEK, $this->key);
        $type = $this->kind === 'n_plus_one' ? 'N+1 query' : 'Duplicate query';

        $lines = [
            "## {$type}",
            '',
            "- **Where:** `{$location}`",
            '- **Found in:** '.Format::number($totals->count ?? 0).' executions in the last 7 days, up to '.Format::number($totals->max ?? 0).' times in one',
            '- **Laralyze\'s hint:** '.(new Findings)->hint($this->kind, $sql, (int) ($totals->max ?? 0)),
            '',
            '```sql',
            $sql,
            '```',
        ];

        if (preg_match('/^(.+):(\d+)$/', $location, $match)) {
            $lines[] = $this->code($files, $match[1], (int) $match[2]);
        }

        $example = $storage->values('finding_example', [$this->key])->first()->value ?? null;

        if (is_string($example) && ($execution = $storage->execution($example)) !== null) {
            $lines = [...$lines, '', '## An execution where it happened', '', $this->executionContext($execution)];
        }

        return implode("\n", $lines);
    }

    protected function query(DatabaseStorage $storage, Files $files): string
    {
        $totals = $storage->total('query', ['count', 'avg', 'p95', 'max'], self::WEEK, $this->key);

        $lines = [
            '## A query',
            '',
            '```sql',
            $this->key,
            '```',
            '',
            '- **Last 7 days:** '.Format::number($totals->count ?? 0).' runs, average '.Format::duration($totals->avg ?? null).', p95 '.Format::duration($totals->p95 ?? null).', slowest '.Format::duration($totals->max ?? null),
        ];

        foreach ($storage->aggregate('slow_query', ['count', 'max'], self::WEEK, 'count', 1_000) as $row) {
            [$sql, $location] = array_pad(array_map('strval', (array) json_decode((string) $row->key, true)), 2, '');

            if ($sql === $this->key && $location !== '') {
                $lines[] = "- **Slow {$row->count} times at** `{$location}`, slowest ".Format::duration($row->max);

                if (preg_match('/^(.+):(\d+)$/', $location, $match)) {
                    $lines[] = $this->code($files, $match[1], (int) $match[2]);
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function route(DatabaseStorage $storage): string
    {
        $totals = $storage->total('request', ['count', 'avg', 'p95', 'max'], self::WEEK, $this->key);

        $lines = [
            "## Route {$this->key}",
            '',
            '- **Last 7 days:** '.Format::number($totals->count ?? 0).' requests, average '.Format::duration($totals->avg ?? null).', p95 '.Format::duration($totals->p95 ?? null).', slowest '.Format::duration($totals->max ?? null),
        ];

        $slowest = $storage->executions(['type' => 'request', 'name' => $this->key], self::WEEK, 'slowest', 1)->first();

        if ($slowest !== null && ($execution = $storage->execution($slowest->uuid)) !== null) {
            $lines = [...$lines, '', '## Its slowest kept request', '', $this->executionContext($execution)];
        }

        return implode("\n", $lines);
    }

    /**
     * One request, job or command: its stages, what took the time, and
     * what happened inside, in order.
     */
    protected function executionContext(?stdClass $execution): string
    {
        if ($execution === null) {
            return 'Laralyze no longer keeps it.';
        }

        $title = $execution->type === 'command' ? ($execution->meta['line'] ?? $execution->name) : $execution->name;
        $lines = [
            "### {$execution->type} `{$title}`",
            '',
            "- **Status:** {$execution->status}".($execution->failed ? ' (failed)' : ''),
            '- **Duration:** '.Format::duration($execution->duration).', peak memory '.Format::bytes($execution->counts['memory'] ?? null),
            '- **Started:** '.now()->setTimestamp($execution->started_at)->toIso8601String(),
        ];

        foreach ($execution->meta['stages'] ?? [] as [$stage, $from, $to]) {
            $lines[] = "- **Stage {$stage}:** ".Format::duration((float) $to - (float) $from);
        }

        $counts = array_filter($execution->counts, fn ($count, $kind) => $kind !== 'memory', ARRAY_FILTER_USE_BOTH);
        $lines[] = '- **Events:** '.implode(', ', array_map(fn ($kind, $count) => "{$count} {$kind}".(isset($execution->meta['ms'][$kind]) ? ' ('.Format::duration((float) $execution->meta['ms'][$kind]).')' : ''), array_keys($counts), $counts));
        $lines = [...$lines, '', 'Timeline (ms from start, duration, what):', '```'];

        foreach (array_slice($execution->events, 0, 150) as [$kind, $at, $ms, $label, $detail]) {
            $lines[] = sprintf('%8.1f %9s %-12s %s%s', $at, $ms === null ? '' : Format::duration((float) $ms), $kind, mb_strimwidth((string) $label, 0, 300, '…'), $detail === null || $detail === '' || $kind === 'query' ? '' : ' · '.mb_strimwidth((string) $detail, 0, 200, '…'));
        }

        if (count($execution->events) > 150) {
            $lines[] = '… '.(count($execution->events) - 150).' more events';
        }

        $lines[] = '```';

        return implode("\n", $lines);
    }

    protected function code(Files $files, string $file, int $line): string
    {
        $code = $files->around($file, $line);

        return $code === null ? '' : "\n\nCode around {$file}:{$line}:\n```php\n{$code}\n```";
    }

    protected static function executionLabel(?stdClass $execution): ?string
    {
        if ($execution === null) {
            return null;
        }

        return ucfirst($execution->type).' · '.($execution->type === 'command' ? ($execution->meta['line'] ?? $execution->name) : $execution->name);
    }
}
