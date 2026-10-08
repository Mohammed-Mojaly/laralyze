<?php

namespace MohammedMojaly\Laralyze\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the cache before Laralyze's own middleware, like Livewire's
 * rate limit check on every update.
 */
class ReadsCache
{
    public function handle(Request $request, Closure $next): mixed
    {
        Cache::get('probe');

        return $next($request);
    }
}
