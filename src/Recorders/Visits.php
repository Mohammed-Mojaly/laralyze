<?php

namespace Laralyze\Recorders;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laralyze\Laralyze;
use Laralyze\Support\UserAgent;
use Symfony\Component\HttpFoundation\Response;

/**
 * A quick look at who visits the app: page views, unique visitors, who is
 * here right now, devices, systems, browsers and bots.
 *
 * Only successful GET requests for pages count. Raw IP addresses and user
 * agents are never stored; guests are told apart by a hash that changes
 * every day, so no cookie is needed.
 */
class Visits extends Recorder
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(Laralyze $laralyze, array $config, protected Auth $auth, protected Cache $cache)
    {
        parent::__construct($laralyze, $config);
    }

    public function register(Application $app): void
    {
        $this->afterEachRequest($app, $this->recordVisit(...));
    }

    public function recordVisit(CarbonInterface $startedAt, Request $request, Response $response): void
    {
        if (! $this->isPageView($request, $response)) {
            return;
        }

        $agent = UserAgent::parse((string) $request->userAgent());

        if ($agent['bot'] !== null) {
            $this->laralyze->record('visit_bot', $agent['bot'])->count();

            return;
        }

        $visitor = $this->visitor($request);

        $this->laralyze->record('visit', $this->page($request))->count();
        $this->laralyze->set('visitor_seen', $visitor, (string) time());

        if ($this->firstVisitToday($visitor)) {
            $this->laralyze->record('visitor', 'all')->count();
            $this->laralyze->record('visitor_device', $agent['device'])->count();
            $this->laralyze->record('visitor_os', $agent['os'])->count();
            $this->laralyze->record('visitor_browser', $agent['browser'])->count();
        }
    }

    /**
     * Pages are counted by route, "/posts/{post}", so tokens in URLs are
     * never stored and the table stays small however many URLs there are.
     */
    protected function page(Request $request): string
    {
        $route = $request->route();
        $path = $route instanceof Route ? $route->uri() : $request->path();

        return $this->group('/'.ltrim($path, '/'));
    }

    protected function isPageView(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || ! $response->isSuccessful()) {
            return false;
        }

        if ($request->is(...(array) ($this->config['except'] ?? []))) {
            return false;
        }

        // Link prefetches aren't visits.
        if (str_contains((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose')), 'prefetch')) {
            return false;
        }

        if ($request->header('X-Inertia')) {
            return true;
        }

        return ! $request->expectsJson()
            && str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');
    }

    /**
     * The signed-in user when the app already loaded one, otherwise a
     * hash that only lives for today.
     */
    protected function visitor(Request $request): string
    {
        $guard = $this->auth->guard();

        if ($guard->hasUser() && $guard->id() !== null) {
            return 'user:'.$guard->id();
        }

        return 'guest:'.substr(hash('xxh128', date('Y-m-d').'|'.config('app.key').'|'.$request->ip().'|'.$request->userAgent()), 0, 20);
    }

    protected function firstVisitToday(string $visitor): bool
    {
        $secondsLeftToday = 86_400 - (time() % 86_400);

        return (bool) $this->laralyze->ignore(
            fn () => $this->cache->store()->add('laralyze:visitor:'.date('Y-m-d').':'.$visitor, 1, $secondsLeftToday),
        );
    }
}
