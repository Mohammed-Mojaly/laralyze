<?php

namespace MohammedMojaly\Laralyze\Alerts\Metrics;

use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Database\Incidents;
use MohammedMojaly\Laralyze\Recorders;
use stdClass;

/**
 * An incident the Database health page kept, told once.
 */
class DatabaseProblems extends EventMetric
{
    /**
     * The kinds that are problems. Long-running queries and open
     * transactions are only warnings, routine in some apps.
     */
    public const DEFAULT_KINDS = ['blocking', 'connections', 'deadlock', 'restart'];

    public function __construct()
    {
        parent::__construct('database.problem', 'Database health', 'A problem', 'count', Recorders\Database::class);
    }

    public function dimensions(): array
    {
        return [
            'connection' => new Dimension('connection', 'Connection'),
            'kind' => new Dimension('kind', 'Kind', 'list', Incidents::KINDS),
        ];
    }

    public function hint(): ?string
    {
        return 'Each incident is told once. Long-running queries and open transactions are only warnings, routine in some apps.';
    }

    public function events(Storage $storage, Rule $rule, int $now): array
    {
        $since = $now - self::LOOKBACK;
        $filter = $rule->filter;
        $events = [];

        // New incidents are counted per ["connection","kind"]: only connections with one are read.
        $connections = $storage->aggregate('db_incident', ['count'], self::LOOKBACK, 'count', 100)
            ->map(fn (stdClass $row) => (string) (json_decode((string) $row->key, true)[0] ?? ''))
            ->filter()
            ->unique();

        foreach ($connections as $connection) {
            foreach ($storage->values('db_incident', keyPrefix: $connection.'|', limit: 50) as $row) {
                $incident = json_decode((string) $row->value, true);

                if (! is_array($incident) || $row->timestamp < $since) {
                    continue;
                }

                $kind = (string) ($incident['kind'] ?? '');

                if ($filter !== null && ! $filter->matches($filter->by === 'kind' ? $kind : $connection)) {
                    continue;
                }

                $events[] = [
                    'id' => (string) $row->key,
                    'item' => $connection.' · '.(Incidents::KINDS[$kind] ?? $kind),
                    'text' => Str::limit((string) ($incident['summary'] ?? ''), 300),
                    'url' => route('laralyze.page', ['page' => 'database']),
                ];
            }
        }

        return $events;
    }
}
