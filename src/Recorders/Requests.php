<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Date;
use Livewire\Component;
use MohammedMojaly\Laralyze\Http\Middleware\Authorize;
use Symfony\Component\HttpFoundation\Response;

use function Livewire\on;

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

        // Livewire 3 and 4 fire it for each component of an update, after checking its checksum.
        if (function_exists('Livewire\on')) {
            on('hydrate', function (Component $component) use ($app) {
                $request = $app->make('request');

                if (! $request->attributes->has(self::LIVEWIRE)) {
                    $request->attributes->set(self::LIVEWIRE, $component);
                }
            });
        }
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
    public function key(Request $request): string
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
     * Request attribute holding the first component Livewire hydrated.
     */
    public const LIVEWIRE = 'laralyze.livewire';

    /**
     * Livewire's own actions that are worth a row of their own.
     */
    protected const LIVEWIRE_ACTIONS = ['$refresh', '$set', '$toggle', '$sync', '$commit'];

    /**
     * "livewire:counter@increment", from the first component Livewire
     * hydrated. Livewire only does that once the snapshot's checksum
     * matches, so nothing here comes from a payload anyone could make up.
     */
    protected function livewireKey(Request $request): string
    {
        $component = $request->attributes->get(self::LIVEWIRE);

        if (! $component instanceof Component) {
            return 'livewire:update';
        }

        $method = $request->input('components.0.calls.0.method');
        $known = is_string($method) && (in_array($method, self::LIVEWIRE_ACTIONS, true)
            || (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method) === 1 && method_exists($component, $method)));

        return 'livewire:'.$component->getName().($known ? '@'.$method : '');
    }
}
