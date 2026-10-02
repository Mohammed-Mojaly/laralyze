<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;
use stdClass;

/**
 * One exception: when and how often it happened, who ran into it, and its
 * latest stack trace with the code around your lines.
 */
#[Lazy]
class ExceptionDetail extends Card
{
    /**
     * The exception's key: its class and location.
     */
    #[Locked]
    public string $name = '';

    /**
     * Polling would fold open stack frames back up.
     */
    public int $poll = 0;

    public function render(): View
    {
        [$class, $location] = array_pad($this->parts($this->name), 2, '');
        $details = json_decode((string) ($this->values('exception_details', [$this->name])->first()->value ?? ''), true);
        $details = is_array($details) ? $details : [];
        $storage = app(DatabaseStorage::class);
        $kept = (int) config('laralyze.retention', 30) * 86_400;

        $exception = (object) [
            'class' => $class,
            'location' => $location,
            'message' => (string) ($details['message'] ?? $this->values('exception_message', [$this->name])->first()->value ?? ''),
            'previous' => $details['previous'] ?? [],
            'context' => $details['context'] ?? null,
            'code' => $details['code'] ?? null,
            'source' => $details['source'] ?? null,
            'server' => $details['server'] ?? null,
            'php' => $details['php'] ?? null,
            'laravel' => $details['laravel'] ?? null,
            'frames' => $details['frames'] ?? [],
        ];

        $counts = [
            'handled' => (float) ($this->total('exception_handled', ['count'], $this->name)->count ?? 0),
            'unhandled' => (float) ($this->total('exception_unhandled', ['count'], $this->name)->count ?? 0),
        ];

        $seen = $storage->total('exception', ['min', 'max'], $kept, $this->name);

        return view('laralyze::cards.exception', [
            'exception' => $exception,
            'counts' => $counts,
            'series' => [
                'handled' => $this->graph('exception_handled', 'count', $this->name),
                'unhandled' => $this->graph('exception_unhandled', 'count', $this->name),
            ],
            'firstSeen' => $seen->min,
            'lastSeen' => $seen->max,
            'lastDay' => (float) ($storage->total('exception', ['count'], 86_400, $this->name)->count ?? 0),
            'lastWeek' => (float) ($storage->total('exception', ['count'], 7 * 86_400, $this->name)->count ?? 0),
            'users' => $this->users(),
            'groups' => $this->groups($exception->frames),
            'markdown' => $this->markdown($exception, $counts, $seen->max),
        ]);
    }

    protected function users(): int
    {
        $prefix = hash('xxh128', $this->name).':';

        return count(array_filter(array_keys($this->counts('exception_user', 5_000)), fn ($key) => str_starts_with((string) $key, $prefix)));
    }

    /**
     * Runs of vendor frames fold into one line; app frames stand alone,
     * and the first one with code starts open.
     *
     * @param  list<array<string, mixed>>  $frames
     * @return list<array{app: bool, open: bool, frames: list<array<string, mixed>>}>
     */
    protected function groups(array $frames): array
    {
        $groups = [];
        $opened = false;

        foreach ($frames as $frame) {
            $app = (bool) ($frame['app'] ?? false);
            $last = array_key_last($groups);

            if (! $app && $last !== null && ! $groups[$last]['app']) {
                $groups[$last]['frames'][] = $frame;

                continue;
            }

            $open = $app && ! $opened && isset($frame['code']);
            $opened = $opened || $open;
            $groups[] = ['app' => $app, 'open' => $open, 'frames' => [$frame]];
        }

        return $groups;
    }

    /**
     * The exception as Markdown, to paste into an issue or an AI chat.
     *
     * @param  array{handled: float, unhandled: float}  $counts
     */
    protected function markdown(stdClass $exception, array $counts, ?float $lastSeen): string
    {
        $lines = ["## {$exception->class}", '', $exception->message, ''];
        $lines[] = "- **Location:** `{$exception->location}`";
        $lines[] = '- **Occurrences:** '.($counts['handled'] + $counts['unhandled'])." ({$counts['handled']} handled, {$counts['unhandled']} unhandled)";

        if ($lastSeen !== null) {
            $lines[] = '- **Last seen:** '.now()->setTimestamp((int) $lastSeen)->toIso8601String();
        }

        if (is_array($exception->source)) {
            $lines[] = '- **Source:** '.$exception->source['type'].' `'.$exception->source['name'].'`';
        }

        if ($exception->php !== null) {
            $lines[] = "- **PHP** {$exception->php}, **Laravel** {$exception->laravel}";
        }

        if ($exception->frames !== []) {
            $lines = [...$lines, '', '### Stack trace', '', '```'];

            foreach ($exception->frames as $i => $frame) {
                $lines[] = "#{$i} {$frame['file']}:{$frame['line']} {$frame['call']}";
            }

            $lines[] = '```';
        }

        foreach ($exception->previous as $previous) {
            $lines = [...$lines, '', "### Caused by {$previous['class']}", '', $previous['message'], '', "- **Location:** `{$previous['location']}`"];
        }

        if (is_array($exception->context)) {
            $lines = [...$lines, '', '### Context', '', '```json', (string) json_encode($exception->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '```'];
        }

        foreach (array_slice(array_filter($exception->frames, fn (array $frame) => isset($frame['code'])), 0, 3) as $frame) {
            $lines = [...$lines, '', "### {$frame['file']}:{$frame['line']}", '', '```php'];

            foreach ($frame['code']['lines'] as $offset => $code) {
                $number = $frame['code']['start'] + $offset;
                $lines[] = ($number === $frame['line'] ? '> ' : '  ').$number.' '.$code;
            }

            $lines[] = '```';
        }

        return implode("\n", $lines)."\n";
    }
}
