<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Issues;
use MohammedMojaly\Laralyze\Recorders;
use stdClass;

/**
 * A new exception, or one you resolved happening again.
 */
class NewExceptions extends EventMetric
{
    public function __construct(private Issues $issues, private Repository $config)
    {
        parent::__construct('exceptions.new', 'Exceptions', 'New, or back after it was resolved', 'count', Recorders\Exceptions::class);
    }

    public function dimensions(): array
    {
        return [
            'class' => new Dimension('class', 'Exception class'),
            'message' => new Dimension('message', 'Message'),
        ];
    }

    public function hint(): ?string
    {
        return 'Each new exception, and each resolved one that happens again, is told once.';
    }

    public function events(Storage $storage, Rule $rule, int $now): array
    {
        $since = $now - self::LOOKBACK;
        $kept = (int) $this->config->get('laralyze.retention', 30) * 86_400;
        $recent = $storage->aggregate('exception', ['min', 'max'], $kept, 'max', 1_000)
            ->filter(fn (stdClass $row) => (float) $row->max >= $since);

        if ($recent->isEmpty()) {
            return [];
        }

        $keys = array_values($recent->map(fn (stdClass $row) => (string) $row->key)->all());
        $statuses = $this->issues->statuses($recent->mapWithKeys(fn (stdClass $row) => [(string) $row->key => $row->max])->all());
        $messages = $storage->values('exception_message', $keys)->pluck('value', 'key');
        $filter = $rule->filter;
        $events = [];

        foreach ($recent as $row) {
            $key = (string) $row->key;
            $class = (string) (json_decode($key, true)[0] ?? $key);
            $message = Str::limit((string) ($messages[$key] ?? ''), 300);

            if ($filter !== null && ! $filter->matches($filter->by === 'message' ? $message : $class)) {
                continue;
            }

            $url = route('laralyze.group', ['page' => 'exceptions', 'group' => hash('xxh128', $key)]);

            if (($statuses[$key] ?? null) === Issues::REOPENED) {
                $events[] = ['id' => 'reopened:'.$key.':'.$this->issues->resolvedAt($key), 'item' => "Reopened: {$class}", 'text' => $message, 'url' => $url];
            } elseif ((float) $row->min >= $since && ($statuses[$key] ?? null) === Issues::OPEN) {
                $events[] = ['id' => 'new:'.$key, 'item' => "New exception: {$class}", 'text' => $message, 'url' => $url];
            }
        }

        return $events;
    }

    public function suggestions(Storage $storage, string $dimension): array
    {
        return $dimension === 'class' ? parent::suggestions($storage, $dimension) : [];
    }

    protected function seen(Storage $storage): Collection
    {
        return $storage->aggregate('exception', ['count'], 86_400, 'count', 200)
            ->map(fn (stdClass $row) => (string) (json_decode((string) $row->key, true)[0] ?? $row->key));
    }
}
