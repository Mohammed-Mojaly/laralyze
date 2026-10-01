<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use MohammedMojaly\Laralyze\Http\Middleware\Authorize;
use MohammedMojaly\Laralyze\Laralyze;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base for everything that watches the app. A recorder lists the events it
 * cares about in $listen and handles them in record(), or hooks in however
 * it likes from register().
 *
 * Options come from its entry in config('laralyze.recorders'):
 *   enabled, sample_rate, threshold (number or regex map with 'default'),
 *   ignore (list of regexes) and groups (regex => replacement).
 *
 * A recorder that collects data during the request and turns it into
 * metrics later adds a digest() method; it runs before every flush.
 *
 * @method void record(object $event)
 * @method void register(\Illuminate\Contracts\Foundation\Application $app)
 * @method void digest()
 */
abstract class Recorder
{
    /**
     * @var list<class-string>
     */
    protected array $listen = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected Laralyze $laralyze, protected array $config = []) {}

    /**
     * @return list<class-string>
     */
    public function listensTo(): array
    {
        return $this->listen;
    }

    /**
     * Run the callback for every web request once the response has been
     * sent. Laralyze's own pages are left out.
     *
     * @param  Closure(CarbonInterface, Request, Response): void  $callback
     */
    protected function afterEachRequest(Application $app, Closure $callback): void
    {
        $hook = function (HttpKernel $kernel) use ($callback) {
            if (! method_exists($kernel, 'whenRequestLifecycleIsLongerThan')) {
                return;
            }

            $kernel->whenRequestLifecycleIsLongerThan(-1, function (CarbonInterface $startedAt, Request $request, Response $response) use ($callback) {
                if ($this->laralyze->isRecording() && $request->attributes->get(Authorize::SKIP_RECORDING) !== true) {
                    $this->laralyze->rescue(fn () => $callback($startedAt, $request, $response));
                }
            });
        };

        // During a web request the kernel already exists by the time providers boot.
        if ($app->resolved(HttpKernel::class)) {
            $hook($app->make(HttpKernel::class));
        } else {
            $app->afterResolving(HttpKernel::class, $hook);
        }
    }

    protected function sampleRate(): float
    {
        return (float) ($this->config['sample_rate'] ?? 1);
    }

    protected function shouldSample(): bool
    {
        $rate = $this->sampleRate();

        return $rate >= 1 || ($rate > 0 && mt_rand() / mt_getrandmax() < $rate);
    }

    public function threshold(string $key): float
    {
        $threshold = $this->config['threshold'] ?? 1_000;

        if (! is_array($threshold)) {
            return (float) $threshold;
        }

        foreach ($threshold as $pattern => $value) {
            if ($pattern !== 'default' && preg_match($pattern, $key)) {
                return (float) $value;
            }
        }

        return (float) ($threshold['default'] ?? 1_000);
    }

    protected function shouldIgnore(string $key): bool
    {
        foreach ($this->config['ignore'] ?? [] as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    protected function group(string $key): string
    {
        foreach ($this->config['groups'] ?? [] as $pattern => $replacement) {
            $grouped = preg_replace($pattern, $replacement, $key, count: $count);

            if ($count > 0 && $grouped !== null) {
                return $grouped;
            }
        }

        return $key;
    }
}
