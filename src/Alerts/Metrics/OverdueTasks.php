<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Recorders;
use stdClass;

/**
 * A task more than a minute past its next run.
 */
class OverdueTasks extends EventMetric
{
    public const LATE = 60;

    public function __construct()
    {
        parent::__construct('scheduled.overdue', 'Scheduled tasks', 'Overdue', 'count', Recorders\ScheduledTasks::class);
    }

    public function dimensions(): array
    {
        return ['task' => new Dimension('task', 'Task')];
    }

    public function hint(): ?string
    {
        return 'A task more than a minute past its next run, plus the length of its last run, so one still running isn\'t counted. It only catches a task skipped while the scheduler runs: when the scheduler stops, alerts stop too, so set LARALYZE_HEARTBEAT_URL for that.';
    }

    public function events(Storage $storage, Rule $rule, int $now): array
    {
        $events = [];

        foreach ($storage->values('scheduled_task') as $row) {
            $data = json_decode((string) $row->value, true);
            $nextAt = (int) ($data['next_at'] ?? 0);
            $task = (string) $row->key;

            // The next run is only written when a run ends, so a task still running looks late:
            // it gets the length of its last run (in ms) on top of the minute.
            $lastRun = (int) ceil((float) ($data['duration'] ?? 0) / 1_000);

            // More than a day late: most likely taken off the schedule.
            if ($nextAt <= 0 || $now - $nextAt <= self::LATE + $lastRun || $nextAt < $now - 86_400) {
                continue;
            }

            $events[$task] = [
                'id' => "{$task}:{$nextAt}",
                'item' => $task,
                'text' => 'It was due at '.Date::createFromTimestamp($nextAt, (string) config('app.timezone', 'UTC'))->format('H:i').' and hasn\'t run.',
                'url' => route('laralyze.group', ['page' => 'scheduled', 'group' => hash('xxh128', $task)]),
            ];
        }

        return array_values($this->matching($rule, $events));
    }

    protected function seen(Storage $storage): Collection
    {
        return $storage->values('scheduled_task')->map(fn (stdClass $row) => (string) $row->key);
    }
}
