<?php

namespace MohammedMojaly\Laralyze\Dashboard;

use Illuminate\Contracts\Config\Repository;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Laralyze;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Client;
use MohammedMojaly\Laralyze\Support\Format;
use Throwable;

/**
 * Things that keep Laralyze from recording properly, shown at the top of
 * the dashboard and in `php artisan about`.
 */
final class Health
{
    /**
     * @var list<array{level: 'bad'|'warn', title: string, hint: string}>|null
     */
    private ?array $problems = null;

    private bool $blocking = false;

    public function __construct(private Storage $storage, private Laralyze $laralyze, private Repository $config) {}

    /**
     * @return list<array{level: 'bad'|'warn', title: string, hint: string}>
     */
    public function problems(): array
    {
        return $this->problems ??= $this->check();
    }

    /**
     * True when the dashboard has nothing to read from.
     */
    public function blocking(): bool
    {
        $this->problems();

        return $this->blocking;
    }

    /**
     * @return list<array{level: 'bad'|'warn', title: string, hint: string}>
     */
    private function check(): array
    {
        $clickhouse = $this->config->get('laralyze.storage.driver', 'database') === 'clickhouse';
        // Where the data lives, never with credentials.
        $where = $clickhouse
            ? 'ClickHouse at '.Client::displayUrl((string) $this->config->get('laralyze.storage.clickhouse.url', 'http://127.0.0.1:8123'))
            : 'its database ['.($this->config->get('laralyze.storage.connection') ?? $this->config->get('database.default')).']';

        try {
            if (! $this->storage->installed()) {
                $this->blocking = true;

                return [$this->bad("Laralyze's tables are missing.", $clickhouse ? 'Run `php artisan laralyze:install`.' : 'Run `php artisan migrate`.')];
            }
        } catch (Throwable $e) {
            $this->blocking = true;

            return [$this->bad("Laralyze can't reach {$where}.", $e->getMessage())];
        }

        $problems = [];
        $now = time();

        $failure = $this->laralyze->lastFailure();

        if ($failure !== null && $failure['at'] > $now - 3_600) {
            $problems[] = $this->bad(
                'Laralyze couldn\'t save data '.now()->setTimestamp($failure['at'])->diffForHumans().'.',
                $failure['message'].' Recording pauses for a minute after a failure, then tries again.',
            );
        }

        if (($this->storage->lastTrimmedAt() ?? 0) < $now - 2 * 3_600 && ($this->storage->oldestBucket() ?? $now) < $now - 2 * 3_600) {
            $problems[] = $this->warn(
                'The scheduler doesn\'t seem to run.',
                'Laralyze removes old data every hour from the scheduler. Add `* * * * * php artisan schedule:run` to cron, or run `php artisan schedule:work`.',
            );
        }

        // Jobs went onto the queue a while ago, yet no worker ran one with Laralyze loaded.
        $queued = $this->count('queue_queued', 3_600) - $this->count('queue_queued', 600);

        if ($queued > 0 && $this->count('queue_processing', 3_600) == 0) {
            $problems[] = $this->warn(
                'Queued jobs aren\'t being recorded.',
                'Jobs were queued in the last hour, but no worker recorded running one. Workers load Laralyze when they start: run `php artisan queue:restart` (or `php artisan horizon:terminate`). If no worker runs at all, start one.',
            );
        }

        $dropped = $this->count('laralyze_dropped', 86_400);

        if ($dropped > 0) {
            $problems[] = $this->warn(
                Format::number($dropped).' '.($dropped == 1 ? 'metric was' : 'metrics were').' dropped in the last 24 hours.',
                'A request recorded more than '.Format::number((float) $this->config->get('laralyze.buffer', 5_000)).' distinct metrics. Raise LARALYZE_BUFFER.',
            );
        }

        $contention = $this->laralyze->contention();

        if ($contention > 0) {
            $problems[] = $this->warn(
                Format::number($contention).' '.($contention == 1 ? 'write' : 'writes').' failed because of lock contention in the last hour.',
                'Concurrent writes to Laralyze\'s tables kept deadlocking, even after retries, and their metrics were lost. Recording carries on. With this much traffic, keep Laralyze\'s data in ClickHouse (LARALYZE_STORAGE=clickhouse).',
            );
        }

        return $problems;
    }

    private function count(string $type, int $window): float
    {
        return (float) ($this->storage->total($type, ['count'], $window)->count ?? 0);
    }

    /**
     * @return array{level: 'bad', title: string, hint: string}
     */
    private function bad(string $title, string $hint): array
    {
        return ['level' => 'bad', 'title' => $title, 'hint' => $hint];
    }

    /**
     * @return array{level: 'warn', title: string, hint: string}
     */
    private function warn(string $title, string $hint): array
    {
        return ['level' => 'warn', 'title' => $title, 'hint' => $hint];
    }
}
