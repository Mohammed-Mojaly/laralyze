<?php

namespace Laralyze\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Laralyze\Laralyze;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the dashboard and its Livewire updates, and keeps Laralyze from
 * measuring its own pages.
 */
class Authorize
{
    public const SKIP_RECORDING = 'laralyze.skip_recording';

    public function __construct(protected Gate $gate, protected Laralyze $laralyze) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::SKIP_RECORDING, true);

        // On Livewire updates this runs against a copy of the request.
        request()->attributes->set(self::SKIP_RECORDING, true);

        $this->gate->authorize('viewLaralyze');

        return $this->laralyze->ignore(fn () => $next($request));
    }
}
