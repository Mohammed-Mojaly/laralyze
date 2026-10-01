<?php

namespace Laralyze\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Laralyze\Laralyze;
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
                // Some of these fire thousands of times per request, so this
                // stays lean: no extra closures, just a guarded call.
                $this->events->listen($event, function (object $payload) use ($recorder) {
                    if (! $this->laralyze->isRecording()) {
                        return;
                    }

                    try {
                        $recorder->record($payload);
                    } catch (Throwable $e) {
                        $this->laralyze->report($e);
                    }
                });
            }
        }
    }
}
