<?php

namespace MohammedMojaly\Laralyze;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Metrics\Buffer;
use MohammedMojaly\Laralyze\Metrics\PendingMetric;
use MohammedMojaly\Laralyze\Metrics\Period;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;
use MohammedMojaly\Laralyze\Support\Outage;
use Throwable;

class Laralyze
{
    public const FAILURE_CACHE_KEY = 'laralyze:last_failure';

    /**
     * How many ignore() calls are currently running.
     */
    protected int $ignoreDepth = 0;

    protected ?Closure $exceptionHandler = null;

    protected Buffer $buffer;

    /**
     * Read once at boot: record() runs thousands of times per request and
     * a config lookup on each call would add up.
     */
    protected bool $enabled;

    protected bool $paused = false;

    /**
     * Values a web request couldn't fit in the buffer.
     */
    protected int $dropped = 0;

    /**
     * Callbacks that turn what recorders collected into metrics, run
     * before each flush.
     *
     * @var list<Closure(): void>
     */
    protected array $digesters = [];

    /**
     * @var list<Closure(string, string): bool>
     */
    protected array $filters = [];

    /**
     * @var (Closure(Authenticatable): array{name?: string, extra?: string})|null
     */
    protected ?Closure $userResolver = null;

    public function __construct(protected Repository $config, protected Application $app)
    {
        $this->enabled = (bool) $config->get('laralyze.enabled', true);
        $this->buffer = new Buffer((int) $config->get('laralyze.buffer', 5_000));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isRecording(): bool
    {
        return $this->enabled && ! $this->paused && $this->ignoreDepth === 0;
    }

    /**
     * Pause recording until startRecording() is called, e.g. for a noisy
     * route or a bulk import you don't want in the numbers.
     */
    public function stopRecording(): void
    {
        $this->paused = true;
    }

    public function startRecording(): void
    {
        $this->paused = false;
    }

    /**
     * Start recording a metric. Chain the aggregations you need:
     *
     *     Laralyze::record('checkout', $plan, $total)->sum()->count();
     */
    public function record(string $type, string $key, int|float $value = 1, ?int $timestamp = null): PendingMetric
    {
        // time() instead of now(): Carbon costs ~3µs per call, too much here.
        return new PendingMetric($this, $this->isRecording() ? $this->buffer : null, $type, $key, (float) $value, $timestamp ?? time());
    }

    /**
     * Add values that were already aggregated, e.g. a recorder that summed
     * durations itself during the request:
     *
     *     Laralyze::merge('query', $sql, ['count' => 12, 'sum' => 84.2, 'max' => 20.1, 'h14' => 12]);
     *
     * @param  array<string, int|float>  $aggregates  count, sum, min, max and histogram bins (h0 … h63).
     */
    public function merge(string $type, string $key, array $aggregates, ?int $timestamp = null): void
    {
        if (! $this->isRecording()) {
            return;
        }

        $timestamp ??= time();

        foreach ($aggregates as $aggregate => $value) {
            if (! $this->buffer->add($type, $key, (string) $aggregate, (float) $value, $timestamp)) {
                $this->bufferFull($type, $key, (string) $aggregate, (float) $value, $timestamp);
            }
        }
    }

    /**
     * Store the latest value for a key, replacing any earlier one.
     */
    public function set(string $type, string $key, string $value, ?int $timestamp = null): void
    {
        if ($this->isRecording()) {
            $this->buffer->set($type, $key, $value, $timestamp ?? time());
        }
    }

    /**
     * @internal Called by PendingMetric when the buffer has no room left.
     */
    public function bufferFull(string $type, string $key, string $aggregate, float $value, int $timestamp): void
    {
        // A web request drops new keys rather than doing I/O before the
        // response is sent. Commands and workers can afford to write early.
        if ($this->app->runningInConsole()) {
            $this->flush();
            $this->buffer->add($type, $key, $aggregate, $value, $timestamp);
        } else {
            $this->dropped++;
        }
    }

    public function buffer(): Buffer
    {
        return $this->buffer;
    }

    /**
     * @internal Recorders that collect cheaply during a request use this to
     *           turn their data into metrics once the response is sent.
     *
     * @param  callable(): void  $digester
     */
    public function digestUsing(callable $digester): void
    {
        $this->digesters[] = Closure::fromCallable($digester);
    }

    /**
     * Keep only the metrics the callback accepts, e.g. to drop keys that
     * contain customer data:
     *
     *     Laralyze::filter(fn (string $type, string $key) => ! str_contains($key, '@'));
     *
     * @param  callable(string $type, string $key): bool  $filter
     */
    public function filter(callable $filter): static
    {
        $this->filters[] = Closure::fromCallable($filter);

        return $this;
    }

    /**
     * Decide how users are shown on the dashboard:
     *
     *     Laralyze::user(fn (User $user) => ['name' => $user->full_name, 'extra' => $user->team->name]);
     *
     * @param  callable(Authenticatable): array{name?: string, extra?: string}  $resolver
     */
    public function user(callable $resolver): static
    {
        $this->userResolver = Closure::fromCallable($resolver);

        return $this;
    }

    /**
     * @return array{name: string, extra: string}
     */
    public function describeUser(Authenticatable $user): array
    {
        $id = (string) $user->getAuthIdentifier();

        if ($this->userResolver !== null) {
            $details = ($this->userResolver)($user);

            return ['name' => (string) ($details['name'] ?? $id), 'extra' => (string) ($details['extra'] ?? '')];
        }

        $email = (string) data_get($user, 'email', '');
        $name = (string) (data_get($user, 'name') ?? ($email !== '' ? $email : $id));

        return ['name' => $name, 'extra' => $email === $name ? '' : $email];
    }

    /**
     * Write everything recorded so far to storage.
     */
    public function flush(): void
    {
        foreach ($this->digesters as $digester) {
            $this->rescue($digester);
        }

        if ($this->buffer->isEmpty()) {
            return;
        }

        if (Outage::active()) {
            $this->buffer->clear();

            return;
        }

        try {
            $this->ignore(function () {
                $storage = $this->app->make(DatabaseStorage::class);

                // Never write into a transaction the app still has open.
                if ($storage->inTransaction()) {
                    return;
                }

                [$rows, $values] = $this->buffer->drain();

                $storage->store([...$this->filtered($rows), ...$this->droppedRows()], $this->filtered($values));

                $this->trimWhenOverdue($storage);
            });
        } catch (Throwable $e) {
            $this->buffer->clear();
            $this->failed($e);
        }
    }

    /**
     * Pause writing for a while, and leave a note for the dashboard.
     */
    protected function failed(Throwable $e): void
    {
        Outage::start();

        $this->rescue(fn () => $this->ignore(fn () => $this->app->make('cache')->store()->put(
            self::FAILURE_CACHE_KEY,
            ['at' => time(), 'message' => Str::limit($e->getMessage(), 500)],
            86_400,
        )));

        $this->report($e);
    }

    /**
     * When the last write failed, and why. Null when none failed in the last day.
     *
     * @return array{at: int, message: string}|null
     */
    public function lastFailure(): ?array
    {
        $failure = $this->rescue(fn () => $this->app->make('cache')->store()->get(self::FAILURE_CACHE_KEY));

        return is_array($failure) && isset($failure['at'], $failure['message'])
            ? ['at' => (int) $failure['at'], 'message' => (string) $failure['message']]
            : null;
    }

    /**
     * Rows that count what was dropped, so the dashboard can say so.
     *
     * @return list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>
     */
    protected function droppedRows(): array
    {
        if ($this->dropped === 0) {
            return [];
        }

        $now = time();
        $rows = array_map(fn (int $period) => [
            'bucket' => Period::bucket($now, $period),
            'period' => $period,
            'type' => 'laralyze_dropped',
            'aggregate' => 'count',
            'key' => 'buffer',
            'value' => (float) $this->dropped,
        ], Period::ALL);

        $this->dropped = 0;

        return $rows;
    }

    /**
     * @template TRow of array{type: string, key: string}
     *
     * @param  list<TRow>  $rows
     * @return list<TRow>
     */
    protected function filtered(array $rows): array
    {
        if ($this->filters === []) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) {
            foreach ($this->filters as $filter) {
                if (! $filter($row['type'], $row['key'])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Remove data past the retention period. Runs hourly from the scheduler.
     */
    public function trim(): void
    {
        $this->rescue(fn () => $this->ignore(
            fn () => $this->app->make(DatabaseStorage::class)->trim((int) $this->config->get('laralyze.retention', 30)),
        ));
    }

    /**
     * Apps without a running scheduler still need old data removed, so now
     * and then a flush checks whether the hourly cleanup has gone missing.
     */
    protected function trimWhenOverdue(DatabaseStorage $storage): void
    {
        [$chances, $outOf] = $this->config->get('laralyze.trim_lottery', [1, 1_000]);

        if (! Lottery::odds($chances, $outOf)->choose()) {
            return;
        }

        if (($storage->lastTrimmedAt() ?? 0) < time() - 2 * 3_600) {
            $storage->trim((int) $this->config->get('laralyze.retention', 30));
        }
    }

    /**
     * Run the callback without recording anything it does.
     *
     * Laralyze wraps its own storage and dashboard work in this, so it never
     * ends up monitoring itself.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function ignore(callable $callback): mixed
    {
        $this->ignoreDepth++;

        try {
            return $callback();
        } finally {
            $this->ignoreDepth--;
        }
    }

    /**
     * Run the callback and make sure nothing it throws reaches the host app.
     *
     * @template TReturn
     * @template TDefault
     *
     * @param  callable(): TReturn  $callback
     * @param  TDefault|(callable(): TDefault)  $default
     * @return TReturn|TDefault
     */
    public function rescue(callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            $this->report($e);

            return value($default);
        }
    }

    /**
     * Decide what happens to exceptions Laralyze swallows. Silent by default.
     *
     * @param  (callable(Throwable): mixed)|null  $handler
     */
    public function handleExceptionsUsing(?callable $handler): static
    {
        $this->exceptionHandler = $handler === null ? null : Closure::fromCallable($handler);

        return $this;
    }

    /**
     * Pass an exception Laralyze swallowed to the handler, if there is one.
     */
    public function report(Throwable $e): void
    {
        if ($this->exceptionHandler === null) {
            return;
        }

        try {
            ($this->exceptionHandler)($e);
        } catch (Throwable) {
            // A broken handler must not break the app either.
        }
    }
}
