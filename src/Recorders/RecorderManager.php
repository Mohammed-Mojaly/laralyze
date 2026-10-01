<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use MohammedMojaly\Laralyze\Laralyze;
use Throwable;

class RecorderManager
{
    public function __construct(
        protected Application $app,
        protected Dispatcher $events,
        protected Laralyze $laralyze,
    ) {}

    /**
     * Wire up enabled recorders. Disabled ones are never even built, so
     * they cost nothing at runtime.
     *
     * @param  array<class-string<Recorder>, array<string, mixed>>  $recorders
     */
    public function register(array $recorders): void
    {
        foreach ($recorders as $class => $config) {
            if (($config['enabled'] ?? true) === false) {
                continue;
            }

            $recorder = $this->app->make($class, ['laralyze' => $this->laralyze, 'config' => $config]);

            if (! $recorder instanceof Recorder) {
                continue;
            }

            if (method_exists($recorder, 'register')) {
                $this->laralyze->rescue(fn () => $recorder->register($this->app));
            }

            if (method_exists($recorder, 'digest')) {
                $this->laralyze->digestUsing(fn () => $recorder->digest());
            }

            foreach ($recorder->listensTo() as $event) {
                // Some of these fire thousands of times per request. Laravel
                // rebuilds a plain listener on every dispatch (about 1 µs),
                // but prepares a wildcard one once and caches it. The name
                // check keeps the pattern to this one event.
                $this->events->listen($event.'*', function (string $name, array $payload) use ($event, $recorder) {
                    if ($name !== $event || ! $this->laralyze->isRecording()) {
                        return;
                    }

                    try {
                        $recorder->record($payload[0]);
                    } catch (Throwable $e) {
                        $this->laralyze->report($e);
                    }
                });
            }
        }
    }
}
