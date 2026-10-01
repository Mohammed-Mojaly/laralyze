<?php

namespace Laralyze\Recorders;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;

/**
 * Follows jobs from being queued to finishing: counts per queue, how long
 * they waited, how long they ran, and which ones failed.
 */
class Jobs extends Recorder
{
    protected array $listen = [
        JobQueued::class,
        JobProcessing::class,
        JobProcessed::class,
        JobReleasedAfterException::class,
        JobFailed::class,
    ];

    /**
     * When each running job started, by job id.
     *
     * @var array<string, float>
     */
    protected array $started = [];

    public function record(JobQueued|JobProcessing|JobProcessed|JobReleasedAfterException|JobFailed $event): void
    {
        if ($event instanceof JobQueued) {
            $this->queued($event);

            return;
        }

        $name = $this->name($event->job);

        if ($this->shouldIgnore($name)) {
            return;
        }

        $queue = $event->connectionName.':'.$event->job->getQueue();
        $id = (string) $event->job->getJobId();

        if ($event instanceof JobProcessing) {
            $this->started[$id] = microtime(true);
            $this->laralyze->record('queue_processing', $queue)->count();
            $this->recordWait($event->job, $queue);

            return;
        }

        $duration = isset($this->started[$id]) ? (microtime(true) - $this->started[$id]) * 1_000 : null;
        unset($this->started[$id]);

        $outcome = match (true) {
            $event instanceof JobProcessed => 'processed',
            $event instanceof JobReleasedAfterException => 'released',
            default => 'failed',
        };

        $this->laralyze->record("queue_{$outcome}", $queue)->count();

        if ($outcome === 'failed') {
            $this->laralyze->record('job_failed', $name)->count();
        }

        if ($duration !== null && $outcome !== 'released') {
            $this->laralyze->record('job', $name, $duration)->avg()->max()->histogram();

            if ($duration >= $this->threshold($name)) {
                $this->laralyze->record('slow_job', $name, $duration)->count()->max();
            }
        }
    }

    protected function queued(JobQueued $event): void
    {
        $name = is_object($event->job) ? $event->job::class : (string) $event->job;

        if ($this->shouldIgnore($name)) {
            return;
        }

        $queue = $event->connectionName.':'.($event->queue ?? config("queue.connections.{$event->connectionName}.queue", 'default'));

        $this->laralyze->record('queue_queued', $queue)->count();
    }

    /**
     * Time between being pushed (plus any delay) and a worker picking it up.
     */
    protected function recordWait(Job $job, string $queue): void
    {
        $payload = $job->payload();
        $createdAt = $payload['createdAt'] ?? null;

        // Retries would count the earlier attempts as waiting.
        if (! is_int($createdAt) || $job->attempts() > 1) {
            return;
        }

        $delay = is_int($payload['delay'] ?? null) ? $payload['delay'] : 0;
        $wait = max(0, (microtime(true) - $createdAt - $delay) * 1_000);

        $this->laralyze->record('queue_wait', $queue, $wait)->avg()->max();
    }

    protected function name(Job $job): string
    {
        return $job->resolveName();
    }
}
