<?php

namespace MohammedMojaly\Laralyze;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Contracts\Ingest;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Ingest\DatabaseIngest;
use MohammedMojaly\Laralyze\Metrics\Buffer;
use MohammedMojaly\Laralyze\Metrics\PendingMetric;
use MohammedMojaly\Laralyze\Metrics\Period;
use MohammedMojaly\Laralyze\Support\Contention;
use MohammedMojaly\Laralyze\Support\Outage;
use Throwable;

class Laralyze
{
    public const FAILURE_CACHE_KEY = 'laralyze:last_failure';

    public const CONTENTION_CACHE_KEY = 'laralyze:contention';

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

    protected bool $flushing = false;

    /**
     * Finished requests, jobs and commands waiting to be written.
     *
     * @var list<array<string, mixed>>
     */
    protected array $executions = [];

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

    /**
     * The installed version, e.g. "v0.1.0".
     */
    public static function version(): ?string
    {
        return InstalledVersions::isInstalled('mohammed-mojaly/laralyze') ? InstalledVersions::getPrettyVersion('mohammed-mojaly/laralyze') : null;
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
        if (! $this->isRecording()) {
            return;
        }

        $timestamp ??= time();

        if (! $this->buffer->set($type, $key, $value, $timestamp)) {
            $this->makeRoom(fn () => $this->buffer->set($type, $key, $value, $timestamp));
        }
    }

    /**
     * @internal Called by PendingMetric when the buffer has no room left.
     */
    public function bufferFull(string $type, string $key, string $aggregate, float $value, int $timestamp): void
    {
        $this->makeRoom(fn () => $this->buffer->add($type, $key, $aggregate, $value, $timestamp));
    }

    /**
     * The buffer is full. A web request drops the value rather than doing I/O
     * before the response is sent. Commands and workers can afford to write
     * early; inside a flush, without running the digesters again.
     *
     * @param  Closure(): bool  $retry
     */
    protected function makeRoom(Closure $retry): void
    {
        if ($this->app->runningInConsole()) {
            $this->flushing ? $this->write() : $this->flush();

            if ($retry()) {
                return;
            }
        }

        $this->dropped++;
    }

    public function buffer(): Buffer
    {
        return $this->buffer;
    }

    /**
     * @internal Keep a finished execution until the next flush.
     *
     * @param  array<string, mixed>  $execution
     */
    public function addExecution(array $execution): void
    {
        // A worker that can't write keeps only the latest few.
        if (count($this->executions) >= 100) {
            array_shift($this->executions);
        }

        $this->executions[] = $execution;
    }

    /**
     * Forget everything not written yet, e.g. between Octane requests.
     */
    public function reset(): void
    {
        $this->buffer->clear();
        $this->executions = [];
        $this->dropped = 0;
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
     * contain customer data. A request, job or command it turns away loses
     * its timelines too, by its route, class or name:
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
        // A digester that fills the buffer would otherwise flush again from inside this flush.
        if ($this->flushing) {
            return;
        }

        $this->flushing = true;

        try {
            foreach ($this->digesters as $digester) {
                $this->rescue($digester);
            }

            $this->write();
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Write what the buffer holds, without running the digesters.
     */
    protected function write(): void
    {
        if ($this->buffer->isEmpty() && $this->executions === []) {
            return;
        }

        if (Outage::active()) {
            $this->reset();

            return;
        }

        try {
            $this->ignore(function () {
                $storage = $this->app->make(Storage::class);

                // Never write into a transaction the app still has open.
                if ($storage->inTransaction()) {
                    return;
                }

                [$rows, $values] = $this->buffer->drain();
                $executions = $this->executions;
                $this->executions = [];

                $ingest = $this->app->make(Ingest::class);
                // A timeline goes with its metric: a request under its route, a job under its class.
                if ($this->filters !== []) {
                    $executions = array_values(array_filter($executions, fn (array $execution) => $this->accepts((string) $execution['type'], (string) $execution['name'])));
                }

                $ingest->write([...$this->filtered($rows), ...$this->droppedRows()], $this->filtered($values), $executions);

                $this->trimWhenOverdue($storage);
                $this->digestWhenOverdue($ingest);
            });
        } catch (Throwable $e) {
            $this->reset();

            // A deadlock that outlasted its retries: the database is busy, not down. Keep recording.
            Contention::causedBy($e) ? $this->contended($e) : $this->failed($e);
        }
    }

    /**
     * Count a write lost to lock contention, for the dashboard.
     */
    protected function contended(Throwable $e): void
    {
        $this->rescue(fn () => $this->ignore(function () {
            $cache = $this->app->make('cache')->store();
            $key = self::CONTENTION_CACHE_KEY.':'.intdiv(Date::now()->getTimestamp(), 600);

            $cache->add($key, 0, 3_600 + 600);
            $cache->increment($key);
        }));

        $this->report($e);
    }

    /**
     * Writes lost to lock contention over the last hour.
     */
    public function contention(): int
    {
        $slot = intdiv(Date::now()->getTimestamp(), 600);

        return (int) $this->rescue(fn () => $this->ignore(function () use ($slot) {
            $cache = $this->app->make('cache')->store();

            // Six ten-minute slots.
            return array_sum(array_map(fn (int $ago) => (int) $cache->get(self::CONTENTION_CACHE_KEY.':'.($slot - $ago), 0), range(0, 5)));
        }), 0);
    }

    /**
     * Pause writing for a while, and leave a note for the dashboard.
     */
    protected function failed(Throwable $e): void
    {
        Outage::start();

        // Before `migrate` creates the tables there is nothing to fix; the dashboard says so itself.
        if ($this->notInstalled()) {
            return;
        }

        $this->rescue(fn () => $this->ignore(fn () => $this->app->make('cache')->store()->put(
            self::FAILURE_CACHE_KEY,
            ['at' => time(), 'message' => Str::limit($e->getMessage(), 500)],
            86_400,
        )));

        $this->report($e);
    }

    protected function notInstalled(): bool
    {
        try {
            return $this->ignore(fn () => ! $this->app->make(Storage::class)->installed());
        } catch (Throwable) {
            return false;
        }
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

        return array_values(array_filter($rows, fn (array $row) => $this->accepts($row['type'], $row['key'])));
    }

    protected function accepts(string $type, string $key): bool
    {
        foreach ($this->filters as $filter) {
            if (! $filter($type, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove data past the retention period. Runs hourly from the scheduler.
     */
    public function trim(): void
    {
        $this->rescue(fn () => $this->ignore(function () {
            $this->app->make(Storage::class)->trim((int) $this->config->get('laralyze.retention', 30), $this->traceDays());
            $this->app->make(Ingest::class)->trim((int) $this->config->get('laralyze.retention', 30));
        }));
    }

    /**
     * Merge what flushes left in the ingest table into Laralyze's tables.
     * Runs every minute from the scheduler; returns how many batches it merged.
     */
    public function digest(int $seconds = 50): int
    {
        try {
            return $this->ignore(fn () => $this->app->make(Ingest::class)->digest($seconds));
        } catch (Throwable $e) {
            // The batches stay where they are, for the next digest. It's the only writer, so a duplicate key isn't a race.
            Contention::causedBy($e) && ! $e instanceof UniqueConstraintViolationException ? $this->contended($e) : $this->failed($e);

            return 0;
        }
    }

    /**
     * Apps without a running scheduler still need their batches merged, so
     * now and then a flush checks whether the digest has gone missing.
     */
    protected function digestWhenOverdue(Ingest $ingest): void
    {
        if (! $ingest instanceof DatabaseIngest) {
            return;
        }

        [$chances, $outOf] = $this->config->get('laralyze.ingest.lottery', [1, 500]);

        if (! Lottery::odds($chances, $outOf)->choose()) {
            return;
        }

        if (($ingest->digestedAt() ?? 0) < Date::now()->getTimestamp() - 180) {
            // Shorter than the scheduler's run: this one holds up a request's worker.
            $ingest->digest(10);
        }
    }

    /**
     * Apps without a running scheduler still need old data removed, so now
     * and then a flush checks whether the hourly cleanup has gone missing.
     */
    protected function trimWhenOverdue(Storage $storage): void
    {
        [$chances, $outOf] = $this->config->get('laralyze.trim_lottery', [1, 1_000]);

        if (! Lottery::odds($chances, $outOf)->choose()) {
            return;
        }

        if (($storage->lastTrimmedAt() ?? 0) < time() - 2 * 3_600) {
            $storage->trim((int) $this->config->get('laralyze.retention', 30), $this->traceDays());
        }
    }

    /**
     * How long single requests, jobs and commands are kept.
     */
    protected function traceDays(): int
    {
        return (int) ($this->config->get('laralyze.recorders.'.Recorders\Traces::class.'.keep_days') ?? 7);
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
