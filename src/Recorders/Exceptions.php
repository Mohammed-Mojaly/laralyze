<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Laralyze;
use MohammedMojaly\Laralyze\Support\Location;
use MohammedMojaly\Laralyze\Support\Trace;
use Throwable;

/**
 * Counts reported exceptions by class and the line in your code that
 * threw them, split into handled (report() or rescue()) and unhandled.
 *
 * Laravel logs every exception it reports, whichever handler wraps it, so
 * this listens to the log rather than to the exception handler.
 *
 * The latest occurrence of each is kept too: its stack trace with the code
 * around your lines, where it happened, and on which server and versions.
 */
class Exceptions extends Recorder
{
    protected array $listen = [
        MessageLogged::class,
        CommandStarting::class,
        JobProcessing::class,
        JobProcessed::class,
        JobFailed::class,
        JobReleasedAfterException::class,
    ];

    protected ?string $command = null;

    protected ?string $job = null;

    /**
     * Traces this process already read, so a flood of the same exception
     * doesn't read the same files over and over.
     *
     * @var array<string, array{code: string|null, frames: list<array<string, mixed>>}>
     */
    protected array $traces = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(Laralyze $laralyze, array $config, protected Application $app, protected Auth $auth)
    {
        parent::__construct($laralyze, $config);
    }

    public function record(MessageLogged|CommandStarting|JobProcessing|JobProcessed|JobFailed|JobReleasedAfterException $event): void
    {
        match (true) {
            $event instanceof CommandStarting => $this->command = $event->command,
            $event instanceof JobProcessing => $this->job = $event->job->resolveName(),
            $event instanceof MessageLogged => $this->logged($event),
            // The job's exception is reported before these, so it still knows its job.
            default => $this->job = null,
        };
    }

    protected function logged(MessageLogged $event): void
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

        $user = $this->userId();

        // The value is when it happened, so min and max are first and last seen.
        $this->laralyze->record('exception', $key, time())->count()->min()->max();
        $this->laralyze->record($handled ? 'exception_handled' : 'exception_unhandled', $key)->count();
        $this->laralyze->set('exception_message', $key, Str::limit($this->message($e), 500));
        $this->laralyze->set('exception_details', $key, (string) json_encode([
            ...$this->trace($e, $key),
            'handled' => $handled,
            'source' => $this->source(),
            'user' => $user,
            'server' => $this->server(),
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
        ], JSON_INVALID_UTF8_SUBSTITUTE));

        if ($user !== null) {
            $this->laralyze->record('exception_user', hash('xxh128', $key).':'.$user)->count();
        }
    }

    /**
     * @return array{code: string|null, frames: list<array<string, mixed>>}
     */
    protected function trace(Throwable $e, string $key): array
    {
        if (count($this->traces) >= 100) {
            $this->traces = [];
        }

        $code = $e instanceof QueryException ? ($e->errorInfo[1] ?? $e->getCode()) : $e->getCode();

        return $this->traces[$key] ??= [
            'code' => in_array($code, [0, '0', '', null], true) ? null : (string) $code,
            'frames' => Trace::frames($e),
        ];
    }

    /**
     * What was running: a route, a job or a command.
     *
     * @return array{type: string, name: string}|null
     */
    protected function source(): ?array
    {
        if ($this->job !== null) {
            return ['type' => 'job', 'name' => $this->job];
        }

        if (! $this->app->runningInConsole() && $this->app->bound('request')) {
            $request = $this->app->make('request');
            $route = $request->route();
            $path = $route instanceof Route ? $route->uri() : $request->path();

            return ['type' => 'request', 'name' => $request->method().' /'.ltrim($path, '/')];
        }

        return $this->command === null ? null : ['type' => 'command', 'name' => $this->command];
    }

    protected function userId(): ?string
    {
        try {
            $guard = $this->auth->guard();

            return $guard->hasUser() ? (string) $guard->id() : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function server(): string
    {
        $name = $this->app->make('config')->get('laralyze.recorders.'.Servers::class.'.server_name');

        return (string) ($name ?: gethostname() ?: 'server');
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
