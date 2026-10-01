<?php

namespace Laralyze\Recorders;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Notifications sent per class and channel, how long they took, and
 * failures: reported by the channel, or started and never finished.
 */
class Notifications extends Recorder
{
    protected array $listen = [NotificationSending::class, NotificationSent::class, NotificationFailed::class];

    /**
     * @var array<string, list<float>>
     */
    protected array $sending = [];

    public function record(NotificationSending|NotificationSent|NotificationFailed $event): void
    {
        $class = $event->notification::class;

        if ($this->shouldIgnore($class)) {
            return;
        }

        $key = (string) json_encode([$class, (string) $event->channel]);

        if ($event instanceof NotificationSending) {
            $this->sending[$key][] = microtime(true);

            return;
        }

        $startedAt = isset($this->sending[$key]) ? array_pop($this->sending[$key]) : null;

        if ($event instanceof NotificationFailed) {
            $this->laralyze->record('notification_failed', $key)->count();

            return;
        }

        $this->laralyze->record('notification', $key, $startedAt === null ? 0 : (microtime(true) - $startedAt) * 1_000)->avg()->max();
    }

    public function digest(): void
    {
        foreach ($this->sending as $key => $unfinished) {
            if ($unfinished !== []) {
                $this->laralyze->merge('notification_failed', (string) $key, ['count' => count($unfinished)]);
            }
        }

        $this->sending = [];
    }
}
