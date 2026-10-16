<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Recorders;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Jobs waiting in each queue now, asked from the queue itself, so it also
 * sees a worker that stopped.
 */
class QueueSize extends Metric
{
    /**
     * Queues asked per look.
     */
    public const MAX = 50;

    public function __construct()
    {
        parent::__construct('queues.waiting', 'Queues', 'Waiting', 'count', Recorders\Jobs::class);
    }

    public function kind(): string
    {
        return self::NOW;
    }

    public function dimensions(): array
    {
        return ['queue' => new Dimension('queue', 'Queue')];
    }

    public function hint(): ?string
    {
        return 'Jobs in the queue now, asked from the queue itself, so it also sees a stopped worker. For the queues seen in the last 24 hours, up to 50.';
    }

    public function read(Storage $storage, Rule $rule, int $now): array
    {
        $items = [];

        foreach (array_keys($this->matching($rule, array_fill_keys($this->queues($storage), true))) as $queue) {
            [$connection, $name] = array_pad(explode(':', (string) $queue, 2), 2, 'default');

            if (in_array(config("queue.connections.{$connection}.driver"), ['sync', 'null', null], true)) {
                continue;
            }

            try {
                $items[(string) $queue] = ['value' => (float) Queue::connection($connection)->size($name)];
            } catch (Throwable $e) {
                // Not read means not known: the look skips the rule instead of counting it as clean.
                throw new RuntimeException("Couldn't read the queue [{$queue}]: ".$e->getMessage(), previous: $e);
            }
        }

        return $items;
    }

    protected function seen(Storage $storage): Collection
    {
        return collect($this->queues($storage));
    }

    /**
     * "connection:queue" for the queues seen in the last 24 hours.
     *
     * @return array<int, string>
     */
    protected function queues(Storage $storage): array
    {
        return collect(['queue_queued', 'queue_processing'])
            ->flatMap(fn (string $type) => $storage->aggregate($type, ['count'], 86_400, 'count', self::MAX)->map(fn (stdClass $row) => (string) $row->key))
            ->unique()
            ->take(self::MAX)
            ->values()
            ->all();
    }
}
