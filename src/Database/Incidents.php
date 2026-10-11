<?php

namespace MohammedMojaly\Laralyze\Database;

use MohammedMojaly\Laralyze\Support\Format;

/**
 * Problems a look at the database found, kept with what was going on, so
 * they can be read after they're over. One that lasts several minutes is
 * one incident, kept at its worst moment.
 */
final class Incidents
{
    public const KINDS = [
        'blocking' => 'Blocking',
        'long_running' => 'Long-running query',
        'open_transaction' => 'Open transaction',
        'connections' => 'Connections',
        'deadlock' => 'Deadlock',
        'restart' => 'Restart',
    ];

    /**
     * Sessions kept with an incident, longest first.
     */
    public const SESSIONS = 15;

    /**
     * Incidents kept with their details per connection each day. Past
     * that, new ones are only counted.
     */
    public const PER_DAY = 50;

    /**
     * The problems in one look, keyed by what makes them the same problem
     * from one minute to the next.
     *
     * @param  array<string, mixed>  $look  The reader's look, with started_at.
     * @param  array<string, mixed>  $before  What the last look saw: deadlocks, deadlock_at, started_at.
     * @return array<string, array<string, mixed>>
     */
    public static function found(array $look, array $before): array
    {
        $checks = collect((array) ($look['checks'] ?? []))->keyBy('key');
        $sessions = array_slice((array) ($look['sessions'] ?? []), 0, self::SESSIONS);
        $found = [];

        $blockers = [];

        foreach ((array) ($checks['blocking']['rows'] ?? []) as $row) {
            $blockers[$row['blocking']] ??= ['user' => $row['user'] ?? '', 'waiting' => 0, 'seconds' => 0];
            $blockers[$row['blocking']]['waiting']++;
            $blockers[$row['blocking']]['seconds'] = max($blockers[$row['blocking']]['seconds'], (int) $row['seconds']);
        }

        foreach ($blockers as $session => $blocker) {
            $found["blocking:{$session}"] = self::make('blocking', Check::PROBLEM, $blocker['seconds'],
                "Session {$session}".($blocker['user'] !== '' ? " ({$blocker['user']})" : '')." blocked {$blocker['waiting']} ".($blocker['waiting'] === 1 ? 'session' : 'sessions').', waiting up to '.self::seconds($blocker['seconds']),
                $sessions);
        }

        foreach ((array) ($checks['long_running']['rows'] ?? []) as $row) {
            $found['long_running:'.$row['id'].':'.md5((string) $row['query'])] = self::make('long_running', Check::WARNING, (int) $row['seconds'],
                'Ran for '.self::seconds((int) $row['seconds']).': '.mb_strimwidth((string) $row['query'], 0, 120, '…'),
                $sessions);
        }

        foreach ((array) ($checks['open_transactions']['rows'] ?? []) as $row) {
            $found['open_transaction:'.$row['id']] = self::make('open_transaction', Check::WARNING, (int) $row['seconds'],
                "Session {$row['id']}".(($row['user'] ?? '') !== '' ? " ({$row['user']})" : '').' kept a transaction open, idle for '.self::seconds((int) $row['seconds']).', '.Format::number($row['rows_locked']).' rows locked',
                $sessions);
        }

        if (($checks['connections']['status'] ?? null) === Check::PROBLEM) {
            $found['connections'] = self::make('connections', Check::PROBLEM, (float) $checks['connections']['percent'],
                "{$checks['connections']['value']} connections in use", $sessions);
        }

        // A first look has nothing to compare with: what it sees may be days old.
        if ($before !== []) {
            $deadlock = $look['deadlock'] ?? null;

            if (is_array($deadlock) && $deadlock['at'] !== ($before['deadlock_at'] ?? null)) {
                $tables = array_unique(array_filter(array_map(fn (array $transaction) => explode(' ', (string) $transaction['holds'])[0], $deadlock['transactions'])));
                $victim = null;

                foreach ($deadlock['transactions'] as $transaction) {
                    $victim = $transaction['rolled_back'] ? $transaction : $victim;
                }

                // The count says how many happened since the last look; only the last one has details.
                $count = ($look['deadlocks'] ?? null) !== null && ($before['deadlocks'] ?? null) !== null ? (int) $look['deadlocks'] - (int) $before['deadlocks'] : 1;
                $where = ($tables === [] ? '' : ' on '.implode(', ', $tables)).($victim === null ? '' : ": transaction {$victim['number']} was rolled back");

                $found["deadlock:{$deadlock['at']}"] = [
                    ...self::make('deadlock', Check::PROBLEM, max(1, $count), $count > 1 ? "{$count} deadlocks, the last{$where}" : "Deadlock{$where}", []),
                    'deadlock' => $deadlock,
                ];
            } elseif (($look['deadlocks'] ?? null) !== null && ($before['deadlocks'] ?? null) !== null && $look['deadlocks'] > $before['deadlocks']) {
                $count = $look['deadlocks'] - $before['deadlocks'];
                $found['deadlocks:'.$look['deadlocks']] = self::make('deadlock', Check::PROBLEM, $count, $count === 1 ? 'A deadlock' : "{$count} deadlocks", []);
            }

            $startedAt = $look['started_at'] ?? null;

            if ($startedAt !== null && ($before['started_at'] ?? null) !== null && abs($startedAt - $before['started_at']) > 120) {
                $found["restart:{$startedAt}"] = self::make('restart', Check::WARNING, 0, 'The database restarted', []);
            }
        }

        return $found;
    }

    /**
     * Carry the open incidents forward with what this look found: a new one
     * starts, one still there is seen again and kept at its worst, and one
     * no longer there is over. Only `$room` new ones are kept with their
     * details; the rest are counted, and stay without details while they
     * last. Returns the incidents open now, the ones to save, and the new
     * ones among them.
     *
     * @param  array<string, array<string, mixed>>  $open
     * @param  array<string, array<string, mixed>>  $found
     * @return array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    public static function track(array $open, array $found, int $at, int $room = self::PER_DAY): array
    {
        [$now, $save, $new] = [[], [], []];

        foreach ($found as $key => $incident) {
            $before = $open[$key] ?? null;

            if ($before === null) {
                $incident = [...$incident, 'id' => "{$at}:{$key}", 'started_at' => $at, 'last_seen' => $at, 'kept' => count($new) < $room];
                $new[] = $incident;
            } else {
                $incident = $incident['peak'] > $before['peak']
                    ? [...$incident, 'id' => $before['id'], 'started_at' => $before['started_at'], 'last_seen' => $at, 'kept' => $before['kept'] ?? true]
                    : [...$before, 'last_seen' => $at];
            }

            $now[$key] = $incident;

            if ($incident['kept']) {
                $save[] = $incident;
            }
        }

        return [$now, $save, $new];
    }

    /**
     * @param  array<mixed>  $sessions
     * @return array<string, mixed>
     */
    protected static function make(string $kind, string $status, float|int $peak, string $summary, array $sessions): array
    {
        return ['kind' => $kind, 'status' => $status, 'peak' => $peak, 'summary' => $summary, 'sessions' => $sessions];
    }

    protected static function seconds(int $seconds): string
    {
        return Format::duration($seconds * 1_000);
    }
}
