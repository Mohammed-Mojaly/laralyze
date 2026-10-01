<?php

namespace Laralyze\Recorders;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Date;
use Laralyze\Laralyze;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which signed-in users make the most requests, hit the most slow ones,
 * and queue the most jobs.
 *
 * Only users the app already loaded are counted, so this never adds a
 * query. Change how users are shown with Laralyze::user().
 */
class Users extends Recorder
{
    protected array $listen = [JobQueued::class];

    /**
     * Users already described during this execution.
     *
     * @var array<string, true>
     */
    protected array $described = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(Laralyze $laralyze, array $config, protected Auth $auth)
    {
        parent::__construct($laralyze, $config);
    }

    public function register(Application $app): void
    {
        $this->afterEachRequest($app, $this->recordRequest(...));
    }

    public function recordRequest(CarbonInterface $startedAt, Request $request, Response $response): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        $id = $this->describe($user);
        $this->laralyze->record('user_request', $id)->count();

        if ($startedAt->diffInMilliseconds(Date::now()) >= $this->threshold($id)) {
            $this->laralyze->record('user_slow_request', $id)->count();
        }
    }

    public function record(JobQueued $event): void
    {
        $user = $this->user();

        if ($user !== null) {
            $this->laralyze->record('user_job', $this->describe($user))->count();
        }
    }

    public function digest(): void
    {
        $this->described = [];
    }

    protected function user(): ?Authenticatable
    {
        $guard = $this->auth->guard();

        return $guard->hasUser() ? $guard->user() : null;
    }

    /**
     * Store how the user is shown on the dashboard, once per execution.
     */
    protected function describe(Authenticatable $user): string
    {
        $id = (string) $user->getAuthIdentifier();

        if (! isset($this->described[$id])) {
            $this->described[$id] = true;
            $this->laralyze->set('user', $id, (string) json_encode($this->laralyze->describeUser($user)));
        }

        return $id;
    }
}
