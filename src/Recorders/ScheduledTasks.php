<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

/**
 * Runs, failures and skips of every scheduled task, with the last run,
 * its outcome and when it runs next.
 */
class ScheduledTasks extends Recorder
{
    protected array $listen = [
        ScheduledTaskStarting::class,
        ScheduledTaskFinished::class,
        ScheduledTaskSkipped::class,
        ScheduledTaskFailed::class,
    ];

    /**
     * @var array<string, float>
     */
    protected array $started = [];

    public function record(ScheduledTaskStarting|ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event): void
    {
        $name = self::name($event->task);

        if (str_starts_with($name, 'laralyze:') || $this->shouldIgnore($name)) {
            return;
        }

        if ($event instanceof ScheduledTaskStarting) {
            $this->started[$name] = microtime(true);

            return;
        }

        $duration = match (true) {
            $event instanceof ScheduledTaskFinished => $event->runtime * 1_000,
            isset($this->started[$name]) => (microtime(true) - $this->started[$name]) * 1_000,
            default => null,
        };
        unset($this->started[$name]);

        $status = match (true) {
            $event instanceof ScheduledTaskFinished => 'processed',
            $event instanceof ScheduledTaskSkipped => 'skipped',
            default => 'failed',
        };

        if ($status === 'skipped') {
            $this->laralyze->record('scheduled_skipped', $name)->count();
        } else {
            $this->laralyze->record('scheduled', $name, $duration ?? 0)->avg()->max();

            if ($status === 'failed') {
                $this->laralyze->record('scheduled_failed', $name)->count();
            }
        }

        $this->laralyze->set('scheduled_task', $name, (string) json_encode([
            'expression' => $event->task->expression,
            'status' => $status,
            'duration' => $duration,
            'ran_at' => time(),
            'next_at' => $event->task->nextRunDate()->getTimestamp(),
            'command' => self::command($event->task),
        ]));
    }

    /**
     * "inspire" rather than "'/usr/bin/php' 'artisan' inspire".
     */
    public static function name(Event $task): string
    {
        $summary = $task->description ?: (string) $task->command;

        if (preg_match('/(?:^|\s)["\']?artisan["\']?\s+(.+)$/', $summary, $matches)) {
            return $matches[1];
        }

        return $summary ?: 'Closure';
    }

    /**
     * The artisan command it runs, e.g. "books:import": that runs in a process
     * of its own, traced as the command. Null for closures, jobs and shell commands.
     */
    public static function command(Event $task): ?string
    {
        if ($task instanceof CallbackEvent) {
            return null;
        }

        return preg_match('/(?:^|\s)["\']?artisan["\']?\s+["\']?([^\s"\']+)/', (string) $task->command, $matches) ? $matches[1] : null;
    }
}
