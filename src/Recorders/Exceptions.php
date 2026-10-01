<?php

namespace Laralyze\Recorders;

use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Laralyze\Support\Location;
use Throwable;

/**
 * Counts reported exceptions by class and the line in your code that
 * threw them, split into handled (report() or rescue()) and unhandled.
 *
 * Laravel logs every exception it reports, whichever handler wraps it, so
 * this listens to the log rather than to the exception handler.
 */
class Exceptions extends Recorder
{
    protected array $listen = [MessageLogged::class];

    public function record(MessageLogged $event): void
    {
        $exception = $event->context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $this->recordException($exception, $this->wasHandled());
        }
    }

    public function recordException(Throwable $e, bool $handled): void
    {
        $class = $e::class;

        if ($this->shouldIgnore($class)) {
            return;
        }

        $location = Location::fromTrace($e->getTrace(), $e->getFile(), $e->getLine()) ?? Location::relative($e->getFile()).':'.$e->getLine();
        $key = (string) json_encode([$class, $location]);

        $this->laralyze->record('exception', $key, time())->count()->max();
        $this->laralyze->record($handled ? 'exception_handled' : 'exception_unhandled', $key)->count();
        $this->laralyze->set('exception_message', $key, Str::limit($this->message($e), 500));
    }

    /**
     * Database errors repeat the values involved ("Duplicate entry
     * 'sara@example.com'"), in the SQL and in every driver's own wording.
     * Keep the SQLSTATE code, a fixed description and the SQL with its
     * placeholders instead.
     */
    protected function message(Throwable $e): string
    {
        if (! $e instanceof QueryException) {
            return $e->getMessage();
        }

        $state = preg_match('/SQLSTATE\[(\w{5})\]/', $e->getMessage(), $matches) ? $matches[1] : null;

        $kind = match (substr((string) $state, 0, 2)) {
            '08' => 'Connection error',
            '22' => 'Invalid data',
            '23' => 'Integrity constraint violation',
            '40' => 'Transaction rolled back',
            '42' => 'Syntax error or access violation',
            default => 'Database error',
        };

        $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $e->getSql());

        return ($state === null ? $kind : "SQLSTATE[{$state}] {$kind}")." ({$sql})";
    }

    /**
     * report() and rescue() are how an app says "I dealt with this".
     */
    protected function wasHandled(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $frame) {
            if (! isset($frame['class']) && in_array($frame['function'], ['report', 'rescue'], true)) {
                return true;
            }
        }

        return false;
    }
}
