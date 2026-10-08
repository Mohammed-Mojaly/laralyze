<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Laralyze;
use Throwable;

/**
 * Counts log messages by level, and keeps those at or above the configured
 * level with their context and where they were written.
 */
class Logs extends Recorder
{
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /**
     * Bytes kept of a message, and of its context as JSON.
     */
    public const MAX_MESSAGE = 4_096;

    public const MAX_CONTEXT = 8_192;

    protected array $listen = [MessageLogged::class];

    /**
     * How many levels, from emergency down, are kept as entries.
     */
    protected int $keptLevels;

    protected ?string $server = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(Laralyze $laralyze, array $config, protected Application $app, protected Auth $auth)
    {
        parent::__construct($laralyze, $config);

        $level = array_search(strtolower((string) ($config['level'] ?? 'info')), self::LEVELS, true);
        $this->keptLevels = $level === false ? 7 : $level + 1;
    }

    public function record(MessageLogged $event): void
    {
        $level = strtolower((string) $event->level);

        if ($this->shouldIgnore($level)) {
            return;
        }

        $this->laralyze->record('log', $level)->count();

        $rank = array_search($level, self::LEVELS, true);

        if ($rank !== false && $rank < $this->keptLevels) {
            $this->keep($event, $level);
        }
    }

    protected function keep(MessageLogged $event, string $level): void
    {
        $exception = $event->context['exception'] ?? null;
        $exception = $exception instanceof Throwable ? $exception : null;
        $message = (string) $event->message;

        // Laravel logs a reported exception's own message, which for a query
        // carries its values; the exception's page shows it without them.
        if ($exception !== null && $message === $exception->getMessage()) {
            $message = Exceptions::messageFor($exception);
        }

        [$execution, $type, $name] = $this->source();

        $this->laralyze->addLog([
            'uuid' => (string) Str::ulid(),
            'logged_at' => Date::now()->getTimestamp(),
            'level' => $level,
            'message' => Laralyze::cut($message, self::MAX_MESSAGE),
            'context' => $this->context($event->context),
            'exception' => $exception !== null && $this->recordsExceptions() ? hash('xxh128', Exceptions::keyFor($exception)) : null,
            'execution' => $execution,
            'type' => $type,
            'name' => $name === null ? null : Laralyze::cut($name, 1_024),
            'user_id' => $this->userId(),
            'server' => $this->server(),
        ]);
    }

    /**
     * The request, job or command it was written in: [execution, type, name].
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    protected function source(): array
    {
        $running = $this->laralyze->running();

        if ($running !== null && $running['type'] !== 'request') {
            return [$running['uuid'], $running['type'], $running['name']];
        }

        // Without timelines, a request is still told apart from a command.
        if ($running === null && ($this->app->runningInConsole() || ! $this->app->bound('request'))) {
            return [null, null, null];
        }

        // A request is named once it's done; until then, by its route.
        $request = $this->app->make('request');
        $route = $request->route();
        $path = $route instanceof Route ? $route->uri() : $request->path();

        return [$running['uuid'] ?? null, 'request', $request->method().' /'.ltrim($path, '/')];
    }

    /**
     * The context as JSON, secrets hidden; left out when it's too big.
     *
     * @param  array<array-key, mixed>  $context
     */
    protected function context(array $context): ?string
    {
        // The exception has its own page.
        unset($context['exception']);

        if ($context === []) {
            return null;
        }

        $json = json_encode(Exceptions::masked($this->plain($context)), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false || strlen($json) > self::MAX_CONTEXT ? null : $json;
    }

    /**
     * Objects become their class name: a model or a request could carry far
     * more than anyone meant to log.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    protected function plain(array $values, int $depth = 0): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                is_array($value) => $depth < 5 ? $this->plain($value, $depth + 1) : '[array]',
                is_object($value) => '['.$value::class.']',
                is_resource($value) => '[resource]',
                default => $value,
            };
        }

        return $values;
    }

    protected function recordsExceptions(): bool
    {
        return (bool) $this->app->make('config')->get('laralyze.recorders.'.Exceptions::class.'.enabled', true);
    }

    protected function userId(): ?string
    {
        try {
            $guard = $this->auth->guard();

            return $guard->hasUser() ? Laralyze::cut((string) $guard->id(), 64) : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function server(): string
    {
        return $this->server ??= Laralyze::cut((string) ($this->app->make('config')->get('laralyze.recorders.'.Servers::class.'.server_name') ?: gethostname() ?: 'server'), 128);
    }
}
