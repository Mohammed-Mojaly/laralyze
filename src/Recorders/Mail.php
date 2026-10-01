<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;

/**
 * Mail sent per mailable, how long sending took, and sends that failed.
 *
 * Laravel has no "mail failed" event, so a message that started sending
 * but never finished by the end of the request or job counts as failed.
 */
class Mail extends Recorder
{
    protected array $listen = [MessageSending::class, MessageSent::class];

    /**
     * @var array<string, list<float>>
     */
    protected array $sending = [];

    public function record(MessageSending|MessageSent $event): void
    {
        $name = $this->name($event->data);

        if ($this->shouldIgnore($name)) {
            return;
        }

        if ($event instanceof MessageSending) {
            $this->sending[$name][] = microtime(true);

            return;
        }

        $startedAt = isset($this->sending[$name]) ? array_pop($this->sending[$name]) : null;

        $this->laralyze->record('mail', $name, $startedAt === null ? 0 : (microtime(true) - $startedAt) * 1_000)->avg()->max();
    }

    public function digest(): void
    {
        foreach ($this->sending as $name => $unfinished) {
            if ($unfinished !== []) {
                $this->laralyze->merge('mail_failed', (string) $name, ['count' => count($unfinished)]);
            }
        }

        $this->sending = [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function name(array $data): string
    {
        $name = $data['__laravel_mailable'] ?? $data['__laravel_notification'] ?? 'Mail';

        return is_string($name) ? $name : 'Mail';
    }
}
