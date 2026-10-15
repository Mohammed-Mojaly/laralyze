<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Recorders;
use stdClass;

/**
 * A scheduled task that failed, told once per failure.
 */
class FailedTasks extends EventMetric
{
    public function __construct()
    {
        parent::__construct('scheduled.failed', 'Scheduled tasks', 'Failed', 'count', Recorders\ScheduledTasks::class);
    }

    public function dimensions(): array
    {
        return ['task' => new Dimension('task', 'Task')];
    }

    public function hint(): ?string
    {
        return 'Each failure is told once.';
    }

    public function events(Storage $storage, Rule $rule, int $now): array
    {
        $events = [];

        foreach ($storage->aggregate('scheduled_failed', ['max'], self::LOOKBACK, 'max', 1_000) as $row) {
            $at = (int) ($row->max ?? 0);
            $task = (string) $row->key;

            if ($at >= $now - self::LOOKBACK) {
                $events[$task] = [
                    'id' => "{$task}:{$at}",
                    'item' => $task,
                    'text' => 'It failed at '.Date::createFromTimestamp($at, (string) config('app.timezone', 'UTC'))->format('H:i').'.',
                    'url' => route('laralyze.group', ['page' => 'scheduled', 'group' => hash('xxh128', $task)]),
                ];
            }
        }

        return array_values($this->matching($rule, $events));
    }

    protected function seen(Storage $storage): Collection
    {
        return $storage->values('scheduled_task')->map(fn (stdClass $row) => (string) $row->key);
    }
}
