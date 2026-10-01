<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;

/**
 * Requests your app makes with Laravel's HTTP client: status, duration,
 * and connections that never got a response.
 */
class OutgoingRequests extends Recorder
{
    protected array $listen = [RequestSending::class, ResponseReceived::class, ConnectionFailed::class];

    /**
     * Send times by request, for responses without transfer stats (fakes).
     *
     * @var array<string, list<float>>
     */
    protected array $sending = [];

    public function record(RequestSending|ResponseReceived|ConnectionFailed $event): void
    {
        $key = $this->key($event->request);

        if ($this->shouldIgnore($key)) {
            return;
        }

        if ($event instanceof RequestSending) {
            $this->sending[$key][] = microtime(true);

            return;
        }

        $sentAt = isset($this->sending[$key]) ? array_pop($this->sending[$key]) : null;

        if ($event instanceof ConnectionFailed) {
            $this->laralyze->record('http_failed', $key)->count();

            return;
        }

        $seconds = $event->response->handlerStats()['total_time'] ?? null;
        $duration = is_numeric($seconds) ? (float) $seconds * 1_000 : ($sentAt === null ? 0.0 : (microtime(true) - $sentAt) * 1_000);

        $this->laralyze->record('http', $key, $duration)->avg()->max()->histogram();
        $this->laralyze->record('http_'.$this->statusClass($event->response->status()), $key)->count();
    }

    /**
     * Method, host and path, with ids grouped by the config rules. The
     * query string is left out.
     */
    protected function key(Request $request): string
    {
        $uri = $request->toPsrRequest()->getUri();

        return $request->method().' '.$this->group($uri->getHost().($uri->getPath() === '' ? '/' : $uri->getPath()));
    }

    protected function statusClass(int $status): string
    {
        return match (true) {
            $status >= 500 => '5xx',
            $status >= 400 => '4xx',
            $status >= 300 => '3xx',
            default => '2xx',
        };
    }
}
