<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Support\Collection;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Recorders;
use stdClass;

/**
 * Minutes since each server's last snapshot: the one metric where missing
 * data is the signal.
 */
class StoppedReporting extends Metric
{
    public function __construct()
    {
        parent::__construct('servers.stopped', 'Servers', 'Stopped reporting', 'min', Recorders\Servers::class);
    }

    public function kind(): string
    {
        return self::NOW;
    }

    public function dimensions(): array
    {
        return ['server' => new Dimension('server', 'Server')];
    }

    public function hint(): ?string
    {
        return 'Minutes since a server last reported. It needs a second server: on a single server, its own scheduler is what stopped, so set LARALYZE_HEARTBEAT_URL for that. Servers silent for more than a day are left out.';
    }

    public function read(Storage $storage, Rule $rule, int $now): array
    {
        $items = [];

        foreach ($storage->values('server') as $row) {
            if ($row->timestamp >= $now - 86_400) {
                $items[(string) $row->key] = ['value' => max(0, $now - $row->timestamp) / 60];
            }
        }

        return $this->matching($rule, $items);
    }

    public function combine(array $raws): array
    {
        return ['value' => max(array_map(fn (array $raw) => (float) $raw['value'], $raws))];
    }

    protected function seen(Storage $storage): Collection
    {
        return $storage->values('server')->map(fn (stdClass $row) => (string) $row->key);
    }
}
