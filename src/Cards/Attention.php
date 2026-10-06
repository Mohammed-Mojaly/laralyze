<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Dashboard\Issues;
use MohammedMojaly\Laralyze\Dashboard\Pages;
use MohammedMojaly\Laralyze\Dashboard\Range;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Recorders;
use MohammedMojaly\Laralyze\Support\Format;
use stdClass;

/**
 * The few things worth looking at first, worst first, one line each:
 * a server in trouble, an unhandled exception, failing jobs, tasks and
 * services, then a slow route and a possible N+1. Nothing when all is well.
 */
#[Lazy]
class Attention extends Card
{
    public const LINES = 5;

    /**
     * A server's CPU, memory or a disk at this percentage or more.
     */
    public const FULL = 90;

    /**
     * Failed calls to one host before it's worth a line.
     */
    public const HOST_FAILURES = 5;

    public function render(): View
    {
        $pages = app(Pages::class);
        $lines = [];

        foreach ([
            Recorders\Servers::class => fn () => $this->server(),
            Recorders\Exceptions::class => fn () => $this->exception(),
            Recorders\Jobs::class => fn () => $this->failedJobs(),
            Recorders\ScheduledTasks::class => fn () => $this->failedTask(),
            Recorders\OutgoingRequests::class => fn () => $this->failingHost(),
            Recorders\Requests::class => fn () => $this->slowRoute(),
            Recorders\Traces::class => fn () => $this->nPlusOne(),
        ] as $recorder => $check) {
            if (count($lines) < self::LINES && $pages->isRecorderEnabled($recorder) && ($line = $check()) !== null) {
                $lines[] = $line;
            }
        }

        return view('laralyze::cards.attention', ['lines' => $lines]);
    }

    public function placeholder(): View
    {
        // Most of the time there's nothing to show, so nothing stands in for it either.
        return view('laralyze::components.hidden');
    }

    /**
     * @return array{level: string, text: string, url: string}|null
     */
    protected function server(): ?array
    {
        $since = time() - $this->range()->seconds();
        $worst = null;

        foreach ($this->values('server') as $server) {
            $name = (string) $server->key;
            $seenAt = (int) $server->timestamp;

            // One that went away before the period is someone else's old server.
            if ($seenAt < $since) {
                continue;
            }

            if ($seenAt < time() - 300) {
                return $this->line('bad', "Server {$name} stopped reporting ".now()->setTimestamp($seenAt)->diffForHumans().'.', $this->pageUrl('servers'));
            }

            $data = json_decode((string) $server->value, true) ?: [];
            $usage = ['CPU' => (float) ($data['cpu'] ?? 0)];

            if ((int) ($data['memory_total'] ?? 0) > 0) {
                $usage['memory'] = $data['memory_used'] / $data['memory_total'] * 100;
            }

            foreach ((array) ($data['disks'] ?? []) as $disk) {
                if ((int) ($disk['total'] ?? 0) > 0) {
                    $usage['disk '.$disk['directory']] = $disk['used'] / $disk['total'] * 100;
                }
            }

            $what = (string) array_search(max($usage), $usage, true);

            if ($usage[$what] >= self::FULL && $usage[$what] > ($worst[1] ?? 0)) {
                $worst = ["Server {$name}: {$what} at ".round($usage[$what]).'%.', $usage[$what]];
            }
        }

        return $worst === null ? null : $this->line('bad', $worst[0], $this->pageUrl('servers'));
    }

    /**
     * @return array{level: string, text: string, url: string}|null
     */
    protected function exception(): ?array
    {
        $exceptions = $this->aggregate('exception', ['count', 'max'], orderBy: 'count');
        $unhandled = $this->counts('exception_unhandled');
        $statuses = app(Issues::class)->statuses($exceptions->mapWithKeys(fn (stdClass $row) => [(string) $row->key => $row->max])->all());

        foreach ($exceptions as $exception) {
            $key = (string) $exception->key;

            if (($unhandled[$key] ?? 0) > 0 && in_array($statuses[$key] ?? Issues::OPEN, [Issues::OPEN, Issues::REOPENED], true)) {
                return $this->line('bad', 'Unhandled '.$this->parts($key)[0].', '.$this->times($exception->count).'.', $this->groupUrl('exceptions', $key));
            }
        }

        return null;
    }

    /**
     * @return array{level: string, text: string, url: string}|null
     */
    protected function failedJobs(): ?array
    {
        $failed = (float) ($this->total('job_failed', ['count'])->count ?? 0);

        return $failed > 0
            ? $this->line('bad', Format::number($failed).' '.($failed == 1 ? 'job' : 'jobs').' failed.', $this->pageUrl('jobs'))
            : null;
    }

    /**
     * @return array{level: string, text: string, url: string}|null
     */
    protected function failedTask(): ?array
    {
        $failed = $this->counts('scheduled_failed');
        arsort($failed);
        $task = array_key_first($failed);

        return $task === null ? null : $this->line('bad', "Scheduled task {$task} failed ".$this->times($failed[$task], 'once').'.', $this->pageUrl('scheduled'));
    }

    /**
     * @return array{level: string, text: string, url: string}|null
     */
    protected function failingHost(): ?array
    {
        $hosts = [];

        foreach (['http_5xx', 'http_failed'] as $type) {
            foreach ($this->counts($type) as $key => $count) {
                // Keys are "METHOD host/path".
                $host = Str::before(Str::after((string) $key, ' '), '/');
                $hosts[$host] = ($hosts[$host] ?? 0) + $count;
            }
        }

        arsort($hosts);
        $host = array_key_first($hosts);

        return $host !== null && $hosts[$host] >= self::HOST_FAILURES
            ? $this->line('bad', "Calls to {$host} failed ".$this->times($hosts[$host]).'.', $this->pageUrl('outgoing-requests'))
            : null;
    }

    /**
     * @return array{level: string, text: string, url: string}|null
     */
    protected function slowRoute(): ?array
    {
        $route = $this->aggregate('slow_request', ['count', 'max'], orderBy: 'max', limit: 1)->first();

        if ($route === null) {
            return null;
        }

        $threshold = $this->recorder(Recorders\Requests::class)?->threshold((string) $route->key);

        return $this->line(
            'warn',
            "{$route->key} took up to ".Format::duration($route->max).($threshold === null ? '' : ', over its '.Format::duration($threshold).' threshold').'.',
            $this->groupUrl('requests', (string) $route->key),
        );
    }

    /**
     * @return array{level: string, text: string, url: string}|null
     */
    protected function nPlusOne(): ?array
    {
        $finding = $this->aggregate('n_plus_one', ['count', 'max'], orderBy: 'max', limit: 1)->first();

        if ($finding === null) {
            return null;
        }

        $location = $this->parts((string) $finding->key)[1] ?? '';

        return $this->line(
            'warn',
            'Possible N+1'.($location === '' ? '' : " at {$location}").', up to '.Format::number($finding->max).'× in one execution.',
            $this->pageUrl('findings'),
        );
    }

    protected function times(float|int $count, string $one = '1 time'): string
    {
        return $count == 1 ? $one : Format::number($count).' times';
    }

    protected function pageUrl(string $page): string
    {
        return route('laralyze.page', ['page' => $page, ...($this->range() === Range::Hour ? [] : ['period' => $this->range()->value])]);
    }

    /**
     * @return array{level: string, text: string, url: string}
     */
    protected function line(string $level, string $text, string $url): array
    {
        return ['level' => $level, 'text' => $text, 'url' => $url];
    }
}
