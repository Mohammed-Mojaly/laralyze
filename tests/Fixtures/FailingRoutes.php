<?php

namespace Laralyze\Tests\Fixtures;

use Illuminate\Support\Collection;
use Laralyze\Cards\Routes;

/**
 * A card swapped in through config('laralyze.cards'): only routes with errors.
 */
class FailingRoutes extends Routes
{
    protected function routes(): Collection
    {
        return parent::routes()->filter(fn ($route) => $route->{'5xx'} > 0)->values();
    }
}
