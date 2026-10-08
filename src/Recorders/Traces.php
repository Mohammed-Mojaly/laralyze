<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Carbon\CarbonInterface;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Assistant\Assistant;
use MohammedMojaly\Laralyze\Laralyze;
use MohammedMojaly\Laralyze\Support\AiCall;
use MohammedMojaly\Laralyze\Support\Location;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Keeps single requests, jobs and commands with what happened inside them,
 * in order: queries, cache calls, outgoing requests, mail, notifications,
 * queued jobs, logs, exceptions and AI calls with the tools they used. Jobs link back to the request, job or
 * command that queued them.
 *
 * Slow and failed ones are always kept; the rest are sampled.
 *
 * @phpstan-type Execution array{uuid: string, trace: string, type: string, name: string, sampled: bool, start: float, events: list<array<int, mixed>>, counts: array<string, int>, exceptions: list<string>, queries: array<string, int>, binds: array<string, list<array<mixed>>>, kept: int, where: array<string, string|null>, begun: float, ms: array<string, float>, stages: list<array{0: string, 1: float, 2: float|null}>, meta: array<string, mixed>}
 */
class Traces extends Recorder
{
    protected array $listen = [
        QueryExecuted::class,
        CacheHit::class,
        CacheMissed::class,
        KeyWritten::class,
        KeyForgotten::class,
        ResponseReceived::class,
        ConnectionFailed::class,
        MessageSent::class,
        NotificationSent::class,
        JobQueued::class,
        MessageLogged::class,
        RouteMatched::class,
        JobProcessing::class,
        JobProcessed::class,
        JobFailed::class,
        JobReleasedAfterException::class,
        CommandStarting::class,
        CommandFinished::class,
        RequestHandled::class,
        Looping::class,
    ];

    /**
     * Commands that run for hours and do their work in jobs, requests or
     * other commands, which are traced on their own.
     */
    public const LONG_RUNNING = [
        'queue:work', 'queue:listen', 'horizon', 'horizon:work', 'horizon:supervisor',
        'schedule:run', 'schedule:work', 'octane:start', 'octane:swoole', 'octane:frankenphp',
        'octane:roadrunner', 'reverb:start', 'pulse:work', 'pulse:check', 'serve', 'tinker',
    ];

    /**
     * What's running now, innermost last: a sync job runs inside the request.
     *
     * @var list<Execution>
     */
    protected array $stack = [];

    /**
     * A job that just failed. Laravel reports its exception after the
     * failed event, so it's written on the next flush instead.
     *
     * @var array{0: Execution, 1: string, 2: float}|null
     */
    protected ?array $failing = null;

    protected Requests $requests;

    /**
     * The same read this many times in one execution, with more than one
     * set of values, is an N+1.
     */
    public const REPEATS = 5;

    /**
     * Query values kept per execution to find repeats, so a long job can't grow memory.
     */
    protected const KEEP_BINDINGS = 2_000;

    protected int $maxEvents;

    protected ?string $server = null;

    /**
     * Events before anything started, with their time: the app booting
     * before a request or command, or a worker reserving the next job.
     *
     * @var list<array{0: string, 1: float, 2: float|null, 3: string, 4: string|null, 5: string|null}>
     */
    protected array $early = [];

    /**
     * Reads among those events, checked for repeats once something starts.
     *
     * @var array{queries: array<string, int>, binds: array<string, list<array<mixed>>>, kept: int, where: array<string, string|null>}
     */
    protected array $earlyReads = self::NO_READS;

    protected const NO_READS = ['queries' => [], 'binds' => [], 'kept' => 0, 'where' => []];

    /**
     * When AI calls and their tools started, by invocation id.
     *
     * @var array<string, float>
     */
    protected array $aiStarted = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(Laralyze $laralyze, array $config, protected Application $app, protected Auth $auth)
    {
        parent::__construct($laralyze, $config);

        $this->maxEvents = (int) ($config['max_events'] ?? 500);
        $this->requests = new Requests($laralyze, (array) $app->make('config')->get('laralyze.recorders.'.Requests::class, []));

        $this->listen = [
            ...$this->listen,
            ...array_keys(AiCall::STARTS),
            ...array_keys(AiCall::ENDS),
            AiCall::FAILED,
            AiCall::TOOL_STARTS,
            ...AiCall::TOOL_ENDS,
        ];
    }

    /**
     * The share of fine, fast executions that are kept, for the dashboard.
     */
    public function rate(): float
    {
        return $this->sampleRate();
    }

    public function register(Application $app): void
    {
        $this->afterEachRequest($app, $this->finishRequest(...));

        // Log entries link to what they were written in.
        $this->laralyze->runningUsing(function () {
            $execution = $this->current() ?? $this->failing[0] ?? null;

            return $execution === null ? null : ['uuid' => $execution['uuid'], 'type' => $execution['type'], 'name' => $execution['name']];
        });

        // Jobs carry the trace they were queued from.
        Queue::createPayloadUsing(function () {
            $current = $this->current();

            return $current === null ? [] : ['laralyze' => ['trace' => $current['trace'], 'sampled' => $current['sampled']]];
        });
    }

    public function record(object $event): void
    {
        // By far the most frequent, so checked first on its own.
        if ($event instanceof QueryExecuted) {
            $this->query($event);

            return;
        }

        match (true) {
            $event instanceof CacheHit => $this->add('cache', $event->key, null, 'hit'),
            $event instanceof CacheMissed => $this->add('cache', $event->key, null, 'miss'),
            $event instanceof KeyWritten => $this->add('cache', $event->key, null, 'write'),
            $event instanceof KeyForgotten => $this->add('cache', $event->key, null, 'forget'),
            $event instanceof ResponseReceived => $this->outgoing($event),
            $event instanceof ConnectionFailed => $this->add('http', $this->url($event->request), null, 'failed'),
            $event instanceof MessageSent => $this->add('mail', (string) ($event->data['__laravel_mailable'] ?? $event->message->getSubject() ?? 'Mail'), null, null),
            $event instanceof NotificationSent => $this->add('notification', $event->notification::class, null, (string) $event->channel),
            $event instanceof JobQueued => $this->add('job', is_object($event->job) ? $event->job::class : (string) $event->job, null, (string) $event->queue),
            $event instanceof MessageLogged => $this->logged($event),
            $event instanceof RouteMatched => $this->startRequest(),
            $event instanceof RequestHandled => $this->stage('request', 'terminating'),
            $event instanceof Looping => [$this->early, $this->earlyReads] = [[], self::NO_READS],
            $event instanceof JobProcessing => $this->startJob($event),
            $event instanceof CommandStarting => $this->startCommand($event),
            $event instanceof JobProcessed, $event instanceof JobFailed, $event instanceof JobReleasedAfterException => $this->finishJob($event),
            $event instanceof CommandFinished => $this->finishCommand($event),
            default => $this->ai($event),
        };
    }

    protected function startRequest(): void
    {
        // A new request, in a fresh process or on Octane: nothing before it belongs to it.
        $this->stack = array_values(array_filter($this->stack, fn (array $execution) => $execution['type'] !== 'request'));

        $now = microtime(true);
        $kernel = $this->app->bound(HttpKernel::class) ? $this->app->make(HttpKernel::class) : null;
        $handled = $kernel instanceof FoundationKernel ? $kernel->requestStartedAt() : null;
        $handled = $handled instanceof CarbonInterface ? (float) $handled->format('U.u') : $now;

        // LARAVEL_START is when PHP started on this request; on Octane it's long gone.
        $started = $this->laravelStart();
        $booted = $started !== null && $started <= $handled && $started > $handled - 30 ? $started : $handled;

        $this->start('request', '', null, $booted);

        if ($booted < $handled) {
            $this->stage('request', 'bootstrap', $booted);
        }

        $this->stage('request', 'middleware', $handled);
        $this->stage('request', 'handle', $now);
    }

    public function finishRequest(CarbonInterface $startedAt, Request $request, Response $response): void
    {
        foreach ($this->stack as $i => $execution) {
            if ($execution['type'] === 'request') {
                array_splice($this->stack, $i, 1);

                $status = $response->getStatusCode();

                $this->finish($execution, $this->requests->key($request), (string) $status, $status >= 500, $startedAt->diffInMilliseconds(now()), $this->userId());

                return;
            }
        }
    }

    protected function startJob(JobProcessing $event): void
    {
        $this->digest();

        $job = $event->job;
        $payload = $job->payload();
        $parent = $payload['laralyze'] ?? null;

        // The worker reserving this job belongs to it.
        $this->start('job', $job->resolveName(), is_array($parent) ? $parent : null, $this->early[0][1] ?? microtime(true), [
            'connection' => $event->connectionName,
            'queue' => $job->getQueue(),
            'attempt' => $job->attempts(),
            'queued_at' => is_numeric($payload['createdAt'] ?? null) ? (int) $payload['createdAt'] : null,
            'job_uuid' => is_string($uuid = $job->uuid()) ? $uuid : null,
        ]);
    }

    protected function finishJob(JobProcessed|JobFailed|JobReleasedAfterException $event): void
    {
        $execution = $this->pop('job', $event->job->resolveName());

        if ($execution === null) {
            return;
        }

        $status = match (true) {
            $event instanceof JobProcessed => 'processed',
            $event instanceof JobReleasedAfterException => 'released',
            default => 'failed',
        };

        $duration = (microtime(true) - $execution['begun']) * 1_000;

        if ($status === 'processed') {
            $this->finish($execution, $execution['name'], $status, false, $duration, null);
        } else {
            $this->digest();
            $this->failing = [$execution, $status, $duration];
        }
    }

    /**
     * Write the job that failed, now that its exception was reported.
     */
    public function digest(): void
    {
        if ($this->failing !== null) {
            [$execution, $status, $duration] = $this->failing;
            $this->failing = null;

            $this->finish($execution, $execution['name'], $status, true, $duration, null);
        }
    }

    protected function startCommand(CommandStarting $event): void
    {
        $name = (string) $event->command;

        if ($name === '' || in_array($name, self::LONG_RUNNING, true) || $this->shouldIgnore($name)) {
            return;
        }

        $now = microtime(true);

        // A command run from the terminal: booting the app is part of it.
        $started = $this->laravelStart();
        $booted = $this->stack === [] && $started !== null && $started <= $now ? $started : $now;

        $this->start('command', $name, null, $booted, ['line' => $this->commandLine($event)]);

        if ($booted < $now) {
            $this->stage('command', 'bootstrap', $booted);
        }

        $this->stage('command', 'handle', $now);
    }

    protected function laravelStart(): ?float
    {
        $start = defined('LARAVEL_START') ? constant('LARAVEL_START') : null;

        return is_numeric($start) ? (float) $start : null;
    }

    /**
     * The command as it was typed, with secret-looking values hidden.
     */
    protected function commandLine(CommandStarting $event): string
    {
        // Symfony quotes tokens with a colon: "books:import" --limit=5.
        $line = (string) preg_replace('/^(["\'])([^"\']+)\1/', '$2', (string) $event->input);
        $line = (string) preg_replace('/((?:^|\s)--?[\w-]*(?:pass|secret|token|key)[\w-]*[= ])(\S+)/i', '$1***', $line);

        return Str::limit($line === '' ? (string) $event->command : $line, 1_000);
    }

    protected function finishCommand(CommandFinished $event): void
    {
        $execution = $this->pop('command', (string) $event->command);

        if ($execution !== null) {
            $this->finish($execution, $execution['name'], (string) $event->exitCode, $event->exitCode !== 0, (microtime(true) - $execution['start']) * 1_000, null);
        }
    }

    /**
     * @param  array{trace?: mixed, sampled?: mixed}|null  $parent
     * @param  array<string, mixed>  $meta
     */
    protected function start(string $type, string $name, ?array $parent, ?float $from = null, array $meta = []): void
    {
        $uuid = (string) Str::ulid();
        $rate = $this->sampleRate();
        $now = microtime(true);
        $from ??= $now;
        $events = $this->earlyEvents($from);
        [$reads, $this->earlyReads] = [$events === [] ? self::NO_READS : $this->earlyReads, self::NO_READS];

        $this->stack[] = [
            'uuid' => $uuid,
            'trace' => is_string($parent['trace'] ?? null) ? $parent['trace'] : $uuid,
            'type' => $type,
            'name' => $name,
            // Decided once at the start, so a sampled request keeps its jobs too.
            'sampled' => is_bool($parent['sampled'] ?? null) ? $parent['sampled'] : ($rate >= 1 || ($rate > 0 && mt_rand() / mt_getrandmax() < $rate)),
            'start' => $from,
            'begun' => $now,
            'events' => $events,
            'ms' => [],
            'stages' => [],
            'meta' => $meta,
            'counts' => [],
            'exceptions' => [],
            ...$reads,
        ];
    }

    /**
     * Events from before it started that belong to it, as offsets.
     *
     * @return list<array<int, mixed>>
     */
    protected function earlyEvents(float $from): array
    {
        $events = [];

        foreach ($this->early as [$kind, $time, $duration, $label, $detail, $link]) {
            if ($time >= $from) {
                $events[] = [$kind, round(max(0, ($time - $from) * 1_000), 2), $duration, $label, $detail, $link];
            }
        }

        $this->early = [];

        return $events;
    }

    /**
     * Begin a stage of what's running, ending the one before.
     */
    protected function stage(string $type, string $name, ?float $at = null): void
    {
        $last = array_key_last($this->stack);

        if ($last === null || $this->stack[$last]['type'] !== $type) {
            return;
        }

        $execution = &$this->stack[$last];
        $offset = round((($at ?? microtime(true)) - $execution['start']) * 1_000, 2);
        $previous = array_key_last($execution['stages']);

        if ($previous !== null) {
            $execution['stages'][$previous][2] = $offset;
        }

        $execution['stages'][] = [$name, $offset, null];
    }

    /**
     * @return Execution|null
     */
    protected function pop(string $type, string $name): ?array
    {
        $last = array_key_last($this->stack);

        if ($last === null || $this->stack[$last]['type'] !== $type || $this->stack[$last]['name'] !== $name) {
            return null;
        }

        return array_pop($this->stack);
    }

    /**
     * @return Execution|null
     */
    protected function current(): ?array
    {
        $last = array_key_last($this->stack);

        return $last === null ? null : $this->stack[$last];
    }

    /**
     * Add an event to what's running: [kind, ms since start, duration, label, detail, link].
     */
    protected function add(string $kind, string $label, ?float $duration, ?string $detail, ?string $link = null): void
    {
        // Runs for every query and cache call, so it stays plain.
        $last = array_key_last($this->stack);

        if ($last === null) {
            if (count($this->early) < 300) {
                $this->early[] = [$kind, microtime(true) - ($duration ?? 0) / 1_000, $duration, strlen($label) > 2_000 ? substr($label, 0, 2_000).'...' : $label, $detail, $link];
            }

            return;
        }

        $execution = &$this->stack[$last];

        // Past the cap only the totals go on; the rest are added up at the end.
        if (count($execution['events']) >= $this->maxEvents) {
            $execution['counts'][$kind] = ($execution['counts'][$kind] ?? 0) + 1;

            if ($duration !== null) {
                $execution['ms'][$kind] = ($execution['ms'][$kind] ?? 0) + $duration;
            }

            return;
        }

        $at = (microtime(true) - $execution['start']) * 1_000 - ($duration ?? 0);

        $execution['events'][] = [$kind, $at > 0 ? $at : 0.0, $duration, strlen($label) > 2_000 ? substr($label, 0, 2_000).'...' : $label, $detail, $link];
    }

    /**
     * Add the query, and count reads that repeat: the same SQL with other
     * values is an N+1, with the same values a duplicate.
     */
    protected function query(QueryExecuted $event): void
    {
        $this->add('query', $event->sql, (float) $event->time, $event->connectionName);

        $last = array_key_last($this->stack);
        $sql = $event->sql;

        if (! (str_starts_with($sql, 'select') || str_starts_with($sql, 'SELECT') || $this->isRead($sql))) {
            return;
        }

        // Reads while the app boots belong to what starts next.
        if ($last === null) {
            $execution = &$this->earlyReads;
        } else {
            $execution = &$this->stack[$last];
        }
        $count = $execution['queries'][$sql] = ($execution['queries'][$sql] ?? 0) + 1;

        // The call stack only exists now, so a read that repeats notes where it
        // came from: at once when its values repeat too, else when it looks like an N+1.
        if ($count === self::REPEATS || ($count > 1 && $count < self::REPEATS && in_array($event->bindings, $execution['binds'][$sql] ?? [], true))) {
            $execution['where'][$sql] ??= Location::here();
        }

        // Values are compared after the response is sent; here they are only kept.
        if ($execution['kept'] < self::KEEP_BINDINGS) {
            $execution['kept']++;
            $execution['binds'][$sql][] = $event->bindings;
        }
    }

    /**
     * Reads written by hand: leading spaces or comments, any case, or a
     * WITH that ends in a select. Only reached when the quick check fails.
     */
    protected function isRead(string $sql): bool
    {
        $sql = ltrim((string) preg_replace('~^\s*(?:/\*.*?\*/\s*|--[^\n]*\n\s*)*~s', '', $sql));

        return stripos($sql, 'select') === 0 || stripos($sql, 'with') === 0;
    }

    /**
     * How many times a read ran again with values it already had.
     *
     * @param  list<array<mixed>>  $bindings
     */
    protected function duplicates(array $bindings): int
    {
        $keys = array_map(fn (array $values) => json_encode($values, JSON_PARTIAL_OUTPUT_ON_ERROR), $bindings);

        return count($keys) - count(array_unique($keys));
    }

    /**
     * Problems in how it queried: [type, sql, location, times].
     *
     * @param  Execution  $execution
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    protected function findings(array $execution): array
    {
        $found = [];

        foreach ($execution['queries'] as $sql => $count) {
            $where = (string) ($execution['where'][$sql] ?? '');

            // Only the framework ran it: nothing in the app to fix.
            if ($where === '') {
                continue;
            }

            $extra = $this->duplicates($execution['binds'][$sql] ?? []);

            // Rows often share a parent, so some values repeat in an N+1 too.
            if ($count >= self::REPEATS && $count - $extra >= 2) {
                $found[] = ['n_plus_one', (string) $sql, $where, $count];
            } elseif ($extra > 0) {
                $found[] = ['duplicate_query', (string) $sql, $where, $extra + 1];
            }
        }

        return $found;
    }

    protected function outgoing(ResponseReceived $event): void
    {
        $seconds = $event->response->handlerStats()['total_time'] ?? null;

        $this->add('http', $this->url($event->request), is_numeric($seconds) ? (float) $seconds * 1_000 : null, (string) $event->response->status());
    }

    /**
     * Method, host and path. The query string is left out: it often holds tokens.
     */
    protected function url(\Illuminate\Http\Client\Request $request): string
    {
        $uri = $request->toPsrRequest()->getUri();

        return $request->method().' '.$uri->getHost().($uri->getPath() === '' ? '/' : $uri->getPath());
    }

    /**
     * An AI call or a tool it used, placed where it started: both end
     * after what happened inside them.
     */
    protected function ai(object $event): void
    {
        $class = $event::class;

        // Laralyze's own assistant and the tools it uses aren't part of the app.
        if ((isset($event->agent) && $event->agent instanceof Assistant) || AiCall::name($event) === AiCall::LARALYZE) {
            return;
        }

        $tool = $class === AiCall::TOOL_STARTS || in_array($class, AiCall::TOOL_ENDS, true);
        $id = AiCall::id($event, $tool ? 'toolInvocationId' : 'invocationId') ?? '';

        if ($class === AiCall::TOOL_STARTS || array_key_exists($class, AiCall::STARTS)) {
            if (count($this->aiStarted) >= 100) {
                array_shift($this->aiStarted);
            }

            $this->aiStarted[$id] ??= microtime(true);

            return;
        }

        $started = $this->aiStarted[$id] ?? null;
        unset($this->aiStarted[$id]);
        $duration = $started === null ? null : (microtime(true) - $started) * 1_000;

        if ($tool) {
            // Agents and MCP tools used as tools name themselves.
            $instance = $event->tool ?? null;
            $name = is_object($instance) ? (method_exists($instance, 'name') ? (string) $instance->name() : $instance::class) : 'Tool';

            $this->add('tool', $name, $duration, $class === AiCall::TOOL_ENDS[1] ? 'failed' : null);
        } else {
            [$in, $out] = AiCall::tokens($event);

            $this->add('ai', AiCall::name($event), $duration, (string) json_encode([AiCall::provider($event), AiCall::model($event), $in, $out, $class === AiCall::FAILED], JSON_UNESCAPED_SLASHES));
        }

        $last = array_key_last($this->stack);

        if ($last === null) {
            return;
        }

        $events = &$this->stack[$last]['events'];

        for ($i = array_key_last($events); $i > 0 && $events[$i - 1][1] > $events[$i][1]; $i--) {
            [$events[$i - 1], $events[$i]] = [$events[$i], $events[$i - 1]];
        }
    }

    protected function logged(MessageLogged $event): void
    {
        $exception = $event->context['exception'] ?? null;

        if (! $exception instanceof Throwable) {
            $this->add('log', $event->message, null, $event->level);

            return;
        }

        $hash = hash('xxh128', Exceptions::keyFor($exception));
        $message = Str::limit(Exceptions::messageFor($exception), 2_000);

        // The exception of a job that just failed, reported after the fact.
        if ($this->failing !== null && $this->stack === []) {
            $this->failing[0]['events'][] = ['exception', (microtime(true) - $this->failing[0]['start']) * 1_000, null, $exception::class, $message, $hash];
            $this->failing[0]['exceptions'][] = $hash;

            return;
        }

        $this->add('exception', $exception::class, null, $message, $hash);

        $last = array_key_last($this->stack);

        if ($last !== null) {
            $this->stack[$last]['exceptions'][] = $hash;
        }
    }

    /**
     * Keep it when it failed, threw, was slow, or was picked by the sample.
     *
     * @param  Execution  $execution
     */
    protected function finish(array $execution, string $name, string $status, bool $failed, float $duration, ?string $user): void
    {
        // The first reason that holds is shown on its page.
        $kept = match (true) {
            $failed => 'failed',
            $execution['exceptions'] !== [] => 'exception',
            $duration >= $this->threshold($name) => 'slow',
            $execution['sampled'] => 'sampled',
            default => null,
        };
        $keep = $kept !== null;

        foreach ($this->findings($execution) as [$type, $sql, $where, $times]) {
            $key = (string) json_encode([Laralyze::cut($sql, Laralyze::MAX_SQL_IN_KEY), $where]);

            // Count is how many executions had it, max the most times in one.
            $this->laralyze->record($type, $key, $times)->count()->max();

            if ($keep) {
                $this->laralyze->set('finding_example', $key, $execution['uuid']);
            }
        }

        if (! $keep) {
            return;
        }

        [$counts, $ms] = $this->totals($execution);

        $this->laralyze->addExecution([
            'uuid' => $execution['uuid'],
            'trace' => $execution['trace'],
            'type' => $execution['type'],
            'name' => $name,
            'status' => $status,
            'failed' => $failed,
            'duration' => $duration,
            'user_id' => $user,
            'server' => $this->server(),
            'started_at' => (int) $execution['start'],
            'exceptions' => array_values(array_unique($execution['exceptions'])),
            'counts' => [...$counts, 'memory' => memory_get_peak_usage(true)],
            'meta' => [
                ...$execution['meta'], 'ms' => $ms, 'stages' => $this->closeStages($execution, $duration), 'error' => $this->firstError($execution),
                'kept' => $kept, ...($kept === 'sampled' ? ['sample_rate' => $this->sampleRate()] : []),
            ],
            'job_uuid' => $execution['meta']['job_uuid'] ?? null,
            'events' => array_map(fn (array $event) => [$event[0], round($event[1], 2), ...array_slice($event, 2)], $execution['events']),
        ]);
    }

    /**
     * How many of each kind and the time they took: the events, plus any past the cap.
     *
     * @param  Execution  $execution
     * @return array{0: array<string, int>, 1: array<string, float>}
     */
    protected function totals(array $execution): array
    {
        [$counts, $ms] = [$execution['counts'], $execution['ms']];

        foreach ($execution['events'] as [$kind, , $duration]) {
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;

            if ($duration !== null) {
                $ms[$kind] = ($ms[$kind] ?? 0) + $duration;
            }
        }

        return [$counts, array_map(fn (float $ms) => round($ms, 2), $ms)];
    }

    /**
     * @param  Execution  $execution
     * @return list<array{0: string, 1: float, 2: float}>
     */
    protected function closeStages(array $execution, float $duration): array
    {
        $end = round((microtime(true) - $execution['start']) * 1_000, 2);

        return array_map(fn (array $stage) => [$stage[0], $stage[1], $stage[2] ?? max($stage[1], $end)], $execution['stages']);
    }

    /**
     * The first exception's message, to show next to a failed run.
     *
     * @param  Execution  $execution
     */
    protected function firstError(array $execution): ?string
    {
        foreach ($execution['events'] as $event) {
            if ($event[0] === 'exception') {
                return Str::limit((string) $event[4], 300);
            }
        }

        return null;
    }

    protected function userId(): ?string
    {
        try {
            $guard = $this->auth->guard();

            return $guard->hasUser() ? (string) $guard->id() : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function server(): string
    {
        // gethostname() can take tens of µs, and the name never changes.
        return $this->server ??= (string) ($this->app->make('config')->get('laralyze.recorders.'.Servers::class.'.server_name') ?: gethostname() ?: 'server');
    }
}
