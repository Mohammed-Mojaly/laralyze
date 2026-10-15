<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Recorders\Logs;

/**
 * Log entries per level. A message filter reads the stored entries.
 */
class LogEntries extends Metric
{
    public const SERIOUS = ['emergency', 'alert', 'critical', 'error'];

    /**
     * Stored entries a message filter reads per look.
     */
    public const SCAN = 1_000;

    public function __construct()
    {
        parent::__construct('logs.entries', 'Logs', 'Entries', 'count', Logs::class);
    }

    public function dimensions(): array
    {
        return [
            'level' => new Dimension('level', 'Level', 'list', array_combine(Logs::LEVELS, array_map(ucfirst(...), Logs::LEVELS))),
            'message' => new Dimension('message', 'Message'),
        ];
    }

    public function hint(): ?string
    {
        return 'Without a filter, it counts error and above. A message filter reads the stored entries at error and above, up to 1,000 per look.';
    }

    public function read(Storage $storage, Rule $rule, int $now): array
    {
        $filter = $rule->filter;
        $items = [];

        if ($filter?->by === 'message') {
            $logs = $storage->logsInstalled() ? $storage->logs(['levels' => self::SERIOUS], $rule->window, self::SCAN) : collect();

            foreach ($logs as $log) {
                if ($filter->matches((string) $log->message)) {
                    $items[(string) $log->level]['value'] = ($items[(string) $log->level]['value'] ?? 0.0) + 1;
                }
            }

            return $items;
        }

        foreach ($storage->aggregate('log', ['count'], $rule->window, 'count', 20) as $row) {
            $items[(string) $row->key] = ['value' => (float) $row->count];
        }

        return $filter === null ? array_intersect_key($items, array_flip(self::SERIOUS)) : $this->matching($rule, $items);
    }
}
