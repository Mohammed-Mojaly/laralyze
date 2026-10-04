<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Contracts\Auth\Factory as Auth;
use MohammedMojaly\Laralyze\Laralyze;
use MohammedMojaly\Laralyze\Support\AiCall;
use MohammedMojaly\Laralyze\Support\AiPrices;
use Throwable;

/**
 * Calls your app makes with laravel/ai: agents, embeddings, images, audio,
 * transcriptions and reranking, by agent, model and user, with tokens,
 * estimated cost, duration and failures. Prompts and responses are never
 * recorded.
 *
 * Costs are kept in millionths of a dollar, so cheap calls still add up.
 */
class Ai extends Recorder
{
    public const MICRO = 1_000_000;

    /**
     * Calls under way: invocation id => [started, name, provider, model].
     *
     * @var array<string, array{0: float, 1: string, 2: string, 3: string}>
     */
    protected array $pending = [];

    /**
     * Users already described in this execution.
     *
     * @var array<string, true>
     */
    protected array $described = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(Laralyze $laralyze, array $config, protected Auth $auth, protected AiPrices $prices)
    {
        parent::__construct($laralyze, $config);

        $this->listen = [...array_keys(AiCall::STARTS), ...array_keys(AiCall::ENDS), AiCall::FAILED];
    }

    public function record(object $event): void
    {
        $id = AiCall::id($event) ?? '';
        $name = AiCall::name($event);

        if (array_key_exists($event::class, AiCall::STARTS)) {
            // A provider that failed over to the next keeps the time it started.
            $this->pending[$id] = [$this->pending[$id][0] ?? microtime(true), $name, AiCall::provider($event), AiCall::model($event)];

            return;
        }

        $started = $this->pending[$id][0] ?? null;
        unset($this->pending[$id]);

        if ($this->shouldIgnore($name) || $name === AiCall::LARALYZE) {
            return;
        }

        if ($event::class === AiCall::FAILED) {
            $this->failed($name, AiCall::provider($event), AiCall::model($event));

            return;
        }

        $duration = $started === null ? 0.0 : (microtime(true) - $started) * 1_000;

        $this->succeeded($name, AiCall::provider($event), AiCall::model($event), $duration, AiCall::tokens($event));
    }

    /**
     * Calls that started and never ended threw. Before laravel/ai 0.11
     * there was no event for that.
     */
    public function digest(): void
    {
        foreach ($this->pending as [, $name, $provider, $model]) {
            if (! $this->shouldIgnore($name) && $name !== AiCall::LARALYZE) {
                $this->failed($name, $provider, $model);
            }
        }

        $this->pending = [];
        $this->described = [];
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int}  $tokens
     */
    protected function succeeded(string $name, string $provider, string $model, float $duration, array $tokens): void
    {
        $key = self::modelKey($provider, $model);
        [$in, $out] = $tokens;
        $cost = $this->prices->cost($provider, $model, $tokens);

        foreach (['ai' => $name, 'ai_model' => $key] as $type => $by) {
            $this->laralyze->record($type, $by, $duration)->avg()->max()->histogram();
            $this->laralyze->record("{$type}_input", $by, $in)->sum();
            $this->laralyze->record("{$type}_output", $by, $out)->sum();

            if ($cost !== null) {
                $this->laralyze->record("{$type}_cost", $by, $cost * self::MICRO)->sum();
            }
        }

        $user = $this->user();

        if ($user !== null) {
            $this->laralyze->record('ai_user', $user, $in + $out)->count()->sum();

            if ($cost !== null) {
                $this->laralyze->record('ai_user_cost', $user, $cost * self::MICRO)->sum();
            }
        }
    }

    protected function failed(string $name, string $provider, string $model): void
    {
        $this->laralyze->record('ai_failed', $name)->count();
        $this->laralyze->record('ai_model_failed', self::modelKey($provider, $model))->count();
    }

    /**
     * Provider and model, as one key: ["openai","gpt-4o-mini"].
     */
    public static function modelKey(string $provider, string $model): string
    {
        return (string) json_encode([$provider, $model], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The signed-in user's id, stored with how they're shown on the
     * dashboard. Only users the app already loaded, so no query is added.
     */
    protected function user(): ?string
    {
        try {
            $guard = $this->auth->guard();

            if (! $guard->hasUser() || ($user = $guard->user()) === null) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $id = (string) $user->getAuthIdentifier();

        if (! isset($this->described[$id])) {
            $this->described[$id] = true;
            $this->laralyze->set('user', $id, (string) json_encode($this->laralyze->describeUser($user)));
        }

        return $id;
    }
}
