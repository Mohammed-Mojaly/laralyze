<?php

namespace Laralyze\Recorders;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

/**
 * Runs, duration and exit codes of Artisan commands.
 */
class Commands extends Recorder
{
    protected array $listen = [CommandStarting::class, CommandFinished::class];

    /**
     * Start times, as a stack per command so commands calling commands work.
     *
     * @var array<string, list<float>>
     */
    protected array $started = [];

    public function record(CommandStarting|CommandFinished $event): void
    {
        $name = (string) $event->command;

        if ($name === '' || $this->shouldIgnore($name)) {
            return;
        }

        if ($event instanceof CommandStarting) {
            $this->started[$name][] = microtime(true);

            return;
        }

        $startedAt = isset($this->started[$name]) ? array_pop($this->started[$name]) : null;

        if ($startedAt === null) {
            return;
        }

        $this->laralyze->record('command', $name, (microtime(true) - $startedAt) * 1_000)->avg()->max();

        if ($event->exitCode !== 0) {
            $this->laralyze->record('command_failed', $name)->count();
        }
    }
}
