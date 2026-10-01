<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Log\Events\MessageLogged;

/**
 * How many messages were logged, per level.
 */
class Logs extends Recorder
{
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    protected array $listen = [MessageLogged::class];

    public function record(MessageLogged $event): void
    {
        $level = strtolower((string) $event->level);

        if (! $this->shouldIgnore($level)) {
            $this->laralyze->record('log', $level)->count();
        }
    }
}
