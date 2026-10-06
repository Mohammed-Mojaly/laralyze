<?php

namespace MohammedMojaly\Laralyze\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;

class AdminsOnly
{
    public static bool $deny = false;

    public function handle(Request $request, Closure $next): mixed
    {
        abort_if(static::$deny, 403);

        return $next($request);
    }
}
