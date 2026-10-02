<?php

namespace MohammedMojaly\Laralyze\Alerts;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Dashboard\Issues;
use MohammedMojaly\Laralyze\Laralyze;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;
use stdClass;

/**
 * Checks for trouble every minute and tells you by mail, Slack or Discord:
 * new or reopened exceptions, a high error rate, failing jobs.
 */
class Alerts
{
    public function __construct(
        protected DatabaseStorage $storage,
        protected Issues $issues,
        protected Laralyze $laralyze,
        protected Repository $config,
    ) {}

    public function enabled(): bool
    {
        return $this->mailTo() !== [] || (bool) $this->config->get('laralyze.alerts.slack') || (bool) $this->config->get('laralyze.alerts.discord');
    }

    /**
     * Check, then send what wasn't sent recently.
     */
    public function run(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->laralyze->rescue(fn () => $this->laralyze->ignore(function () {
            $alerts = $this->unsent($this->check());

            if ($alerts !== []) {
                $this->send($alerts);
            }
        }));
    }

    /**
     * @return list<Alert>
     */
    public function check(): array
    {
        $now = time();
        $since = (int) ($this->storage->values('laralyze', ['alerts_checked_at'])->first()->value ?? $now - 60);
        $rules = (array) $this->config->get('laralyze.alerts.rules', []);

        $this->storage->put('laralyze', 'alerts_checked_at', (string) $now);

        return [
            ...(($rules['exceptions'] ?? true) ? $this->exceptions($since) : []),
            ...$this->errorRate($rules['error_rate'] ?? null),
            ...$this->failedJobs($rules['failed_jobs'] ?? null),
        ];
    }

    /**
     * @return list<Alert>
     */
    protected function exceptions(int $since): array
    {
        $kept = (int) $this->config->get('laralyze.retention', 30) * 86_400;
        $recent = $this->storage->aggregate('exception', ['min', 'max'], $kept, 'max', 1_000)
            ->filter(fn (stdClass $row) => (float) $row->max >= $since);

        if ($recent->isEmpty()) {
            return [];
        }

        $keys = array_values($recent->map(fn (stdClass $row) => (string) $row->key)->all());
        $statuses = $this->issues->statuses($recent->mapWithKeys(fn (stdClass $row) => [(string) $row->key => $row->max])->all());
        $messages = $this->storage->values('exception_message', $keys)->pluck('value', 'key');
        $alerts = [];

        foreach ($recent as $row) {
            $key = (string) $row->key;
            $class = (string) (json_decode($key, true)[0] ?? $key);
            $message = Str::limit((string) ($messages[$key] ?? ''), 300);
            $url = $this->url('exceptions', $key);

            if ($statuses[$key] === Issues::REOPENED) {
                $alerts[] = new Alert('reopened:'.$key.':'.$this->issues->resolvedAt($key), "Reopened: {$class}", $message, $url);
            } elseif ((float) $row->min >= $since && $statuses[$key] === Issues::OPEN) {
                $alerts[] = new Alert('new:'.$key, "New exception: {$class}", $message, $url);
            }
        }

        return $alerts;
    }

    /**
     * @return list<Alert>
     */
    protected function errorRate(mixed $percent): array
    {
        if (! is_numeric($percent)) {
            return [];
        }

        $requests = (float) ($this->storage->total('request', ['count'], 300)->count ?? 0);
        $errors = (float) ($this->storage->total('request_5xx', ['count'], 300)->count ?? 0);

        if ($requests < 20 || $errors / $requests * 100 < (float) $percent) {
            return [];
        }

        $rate = round($errors / $requests * 100, 1);

        return [new Alert('error_rate', "{$rate}% of requests failed", "{$errors} of {$requests} requests in the last 5 minutes answered with a 5xx.", $this->url('requests'))];
    }

    /**
     * @return list<Alert>
     */
    protected function failedJobs(mixed $limit): array
    {
        if (! is_numeric($limit)) {
            return [];
        }

        $failed = (float) ($this->storage->total('job_failed', ['count'], 300)->count ?? 0);

        return $failed >= (float) $limit
            ? [new Alert('failed_jobs', "{$failed} jobs failed", "{$failed} jobs failed in the last 5 minutes.", $this->url('jobs'))]
            : [];
    }

    /**
     * @param  list<Alert>  $alerts
     * @return list<Alert>
     */
    protected function unsent(array $alerts): array
    {
        if ($alerts === []) {
            return [];
        }

        $every = (int) $this->config->get('laralyze.alerts.every', 60) * 60;
        $sent = $this->storage->values('alert_sent', array_map(fn (Alert $alert) => $alert->id, $alerts))->pluck('timestamp', 'key');

        return array_values(array_filter($alerts, fn (Alert $alert) => ($sent[$alert->id] ?? 0) < time() - $every));
    }

    /**
     * @param  list<Alert>  $alerts
     */
    protected function send(array $alerts): void
    {
        foreach ($alerts as $alert) {
            $this->storage->put('alert_sent', $alert->id, '1');
        }

        $text = implode("\n\n", array_map(fn (Alert $alert) => "*{$alert->title}*\n{$alert->body}\n{$alert->url}", $alerts));

        if (($to = $this->mailTo()) !== []) {
            $this->laralyze->rescue(fn () => Notification::route('mail', $to)->notifyNow(new AlertNotification($alerts)));
        }

        if ($slack = (string) $this->config->get('laralyze.alerts.slack')) {
            $this->laralyze->rescue(fn () => Http::timeout(5)->post($slack, ['text' => $text]));
        }

        if ($discord = (string) $this->config->get('laralyze.alerts.discord')) {
            $this->laralyze->rescue(fn () => Http::timeout(5)->post($discord, ['content' => Str::limit(str_replace('*', '**', $text), 1_900)]));
        }
    }

    /**
     * @return list<string>
     */
    protected function mailTo(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->config->get('laralyze.alerts.mail', '')))));
    }

    protected function url(string $page, ?string $key = null): string
    {
        return $key === null
            ? route('laralyze.page', ['page' => $page])
            : route('laralyze.group', ['page' => $page, 'group' => hash('xxh128', $key)]);
    }
}
