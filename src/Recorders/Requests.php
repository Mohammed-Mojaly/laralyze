<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Date;
use MohammedMojaly\Laralyze\Http\Middleware\Authorize;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts every request and times it, grouped by route. Runs after the
 * response has been sent, so the user never waits for it.
 */
class Requests extends Recorder
{
    public const STATUS_CLASSES = ['2xx', '3xx', '4xx', '5xx'];

    public const UNMATCHED = '(unmatched)';

    public function register(Application $app): void
    {
        $this->afterEachRequest($app, $this->recordRequest(...));
    }

    public function recordRequest(CarbonInterface $startedAt, Request $request, Response $response): void
    {
        if ($request->attributes->get(Authorize::SKIP_RECORDING) === true) {
            return;
        }

        if ($this->shouldIgnore('/'.ltrim($request->path(), '/')) || ! $this->shouldSample()) {
            return;
        }

        $key = $this->key($request);
        $duration = max(0.0, (float) $startedAt->diffInMilliseconds(Date::now()));
        $rate = $this->sampleRate();

        $this->laralyze->record('request', $key, $duration)->sample($rate)->avg()->max()->histogram();
        $this->laralyze->record('request_'.$this->statusClass($response->getStatusCode()), $key)->sample($rate)->count();

        if ($duration >= $this->threshold($key)) {
            $this->laralyze->record('slow_request', $key, $duration)->sample($rate)->count()->max();
        }
    }

    /**
     * "GET /users/{user}", so every user's page lands on the same row.
     * Livewire updates are grouped by component and method instead.
     */
    protected function key(Request $request): string
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return $request->method().' '.self::UNMATCHED;
        }

        if (str_ends_with((string) $route->getName(), 'livewire.update')) {
            return $request->method().' '.$this->livewireKey($request);
        }

        return $request->method().' '.$this->group('/'.ltrim($route->uri(), '/'));
    }

    /**
     * "livewire:counter@increment", from the first component in the update.
     */
    protected function livewireKey(Request $request): string
    {
        $component = $request->input('components.0');
        $snapshot = is_array($component) && is_string($component['snapshot'] ?? null)
            ? json_decode($component['snapshot'], true)
            : null;

        $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;
        $method = is_array($component) ? ($component['calls'][0]['method'] ?? null) : null;

        if (! is_string($name)) {
            return 'livewire:update';
        }

        return 'livewire:'.$name.(is_string($method) ? '@'.$method : '');
    }
}
