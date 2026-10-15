<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Recorders;
use stdClass;

/**
 * Space used on each server's directories, from its latest snapshot.
 */
class Disk extends Metric
{
    public function __construct()
    {
        parent::__construct('servers.disk', 'Servers', 'Disk', '%', Recorders\Servers::class);
    }

    public function kind(): string
    {
        return self::NOW;
    }

    public function dimensions(): array
    {
        return ['server' => new Dimension('server', 'Server', part: fn (string $item) => Str::before($item, ' · '))];
    }

    public function hint(): ?string
    {
        return 'Space used, from the latest snapshot of each server that reported in the last day. All together, it\'s the fullest disk.';
    }

    public function read(Storage $storage, Rule $rule, int $now): array
    {
        $items = [];

        foreach ($storage->values('server') as $row) {
            if ($row->timestamp < $now - 86_400) {
                continue;
            }

            foreach ((array) (json_decode((string) $row->value, true)['disks'] ?? []) as $disk) {
                if ((float) ($disk['total'] ?? 0) > 0) {
                    $items[$row->key.' · '.$disk['directory']] = ['value' => (float) $disk['used'] * 100 / (float) $disk['total']];
                }
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
