<?php

namespace MohammedMojaly\Laralyze\Livewire\Concerns;

use Illuminate\Support\Facades\Gate;
use MohammedMojaly\Laralyze\Http\Middleware\Authorize;

/**
 * Cards check the gate themselves instead of trusting Livewire's persistent
 * middleware alone: a custom update route can skip it, it remembers routes
 * for the life of a long-running worker, and a lazy card can render without
 * booting. So the check runs on boot and again before every render.
 */
trait AuthorizesAccess
{
    public function bootAuthorizesAccess(): void
    {
        $this->authorizeLaralyze();
    }

    public function renderingAuthorizesAccess(): void
    {
        $this->authorizeLaralyze();
    }

    protected function authorizeLaralyze(): void
    {
        request()->attributes->set(Authorize::SKIP_RECORDING, true);

        if (in_array(Authorize::class, (array) config('laralyze.middleware', []), true)) {
            Gate::authorize('viewLaralyze');
        }
    }
}
