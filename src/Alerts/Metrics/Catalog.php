<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Contracts\Config\Repository;
use MohammedMojaly\Laralyze\Dashboard\Issues;
use MohammedMojaly\Laralyze\Dashboard\Pages;
use MohammedMojaly\Laralyze\Recorders;

/**
 * Every metric a rule can watch.
 */
final class Catalog
{
    /**
     * @var array<string, Metric>|null
     */
    private ?array $metrics = null;

    public function __construct(private Pages $pages) {}

    /**
     * @return array<string, Metric>
     */
    public function all(): array
    {
        if ($this->metrics === null) {
            $this->metrics = [];

            foreach ($this->build() as $metric) {
                $this->metrics[$metric->key] = $metric;
            }
        }

        return $this->metrics;
    }

    public function find(string $key): ?Metric
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The metrics whose recorder is on, for the form.
     *
     * @return array<string, Metric>
     */
    public function available(): array
    {
        return array_filter($this->all(), fn (Metric $metric) => $metric->recorder === null || $this->pages->isRecorderEnabled($metric->recorder));
    }

    /**
     * @return list<Metric>
     */
    private function build(): array
    {
        $route = ['route' => new Dimension('route', 'Route')];
        $job = ['job' => new Dimension('job', 'Job class')];
        $queue = ['queue' => new Dimension('queue', 'Queue')];
        $server = ['server' => new Dimension('server', 'Server')];
        $exceptionClass = fn (string $key) => (string) (json_decode($key, true)[0] ?? $key);

        return [
            new NewExceptions(app(Issues::class), app(Repository::class)),
            new Counted('exceptions.count', 'Exceptions', 'Occurrences', 'exception', ['class' => new Dimension('class', 'Exception class', part: $exceptionClass)], Recorders\Exceptions::class,
                labels: fn (string $key) => implode(' at ', array_map(fn (mixed $part) => (string) $part, (array) (json_decode($key, true) ?? [$key])))),
            new ErrorRate,
            new Counted('requests.5xx', 'Requests', '5xx responses', 'request_5xx', $route, Recorders\Requests::class),
            new Counted('requests.4xx', 'Requests', '4xx responses', 'request_4xx', $route, Recorders\Requests::class),
            new Highest('requests.p95', 'Requests', 'Duration p95', 'ms', 'request', 'p95', $route, Recorders\Requests::class),
            new Counted('jobs.failed', 'Jobs', 'Failed', 'job_failed', $job, Recorders\Jobs::class),
            new Highest('jobs.p95', 'Jobs', 'Duration p95', 'ms', 'job', 'p95', $job, Recorders\Jobs::class),
            new Highest('queues.wait', 'Queues', 'Wait time', 's', 'queue_wait', 'avg', $queue, Recorders\Jobs::class, 1_000,
                'Recorded when a worker picks a job up, so it can\'t see a stopped worker: use Waiting for that.'),
            new QueueSize,
            new FailedTasks,
            new OverdueTasks,
            new LogEntries,
            ...(interface_exists('Laravel\Ai\Contracts\Agent') ? [
                new AiUsage('ai.cost', 'Cost', '$', ['model' => ['ai_model_cost'], 'agent' => ['ai_cost'], 'user' => ['ai_user_cost']], 'sum', Recorders\Ai::MICRO,
                    'Estimated from published prices. Use a 24-hour window for a daily budget.'),
                new AiUsage('ai.tokens', 'Tokens', 'count', ['model' => ['ai_model_input', 'ai_model_output'], 'agent' => ['ai_input', 'ai_output'], 'user' => ['ai_user']], 'sum'),
                new AiUsage('ai.failed', 'Failed calls', 'count', ['model' => ['ai_model_failed'], 'agent' => ['ai_failed']], 'count'),
            ] : []),
            new DatabaseProblems,
            new Highest('servers.cpu', 'Servers', 'CPU', '%', 'server_cpu', 'avg', $server, Recorders\Servers::class),
            new Highest('servers.memory', 'Servers', 'Memory', '%', 'server_memory', 'avg', $server, Recorders\Servers::class),
            new Disk,
            new StoppedReporting,
        ];
    }
}
