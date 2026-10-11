<?php

namespace MohammedMojaly\Laralyze\Database;

use Illuminate\Support\Carbon;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Support\Format;

/**
 * What the Database health page shows, as Markdown for Ask AI: per
 * connection, the last look, the period's incidents with both sides of
 * each deadlock, errors by kind, size and ids running out.
 */
final class Report
{
    /**
     * Incidents told in full; the rest are counted.
     */
    public const INCIDENTS = 20;

    protected const COLUMNS = ['id' => 'Session', 'waiting' => 'Session', 'blocking' => 'Blocked by session', 'blocker' => 'Its user', 'user' => 'User', 'seconds' => 'For', 'rows_locked' => 'Rows locked', 'query' => 'Query'];

    public static function markdown(Storage $storage, int $window): string
    {
        $looks = $storage->values('database');

        if ($looks->isEmpty()) {
            return 'No database looked at yet. Laralyze looks once a minute from the scheduler, at the connections in its config.';
        }

        return "Tables named laralyze_* are Laralyze's own: locks and deadlocks on them come from Laralyze recording, not from the app's code.\n\n"
            .$looks->map(fn ($row) => self::connection($storage, (string) $row->key, (array) json_decode((string) $row->value, true), $window))->implode("\n\n");
    }

    /**
     * @param  array<string, mixed>  $look
     */
    protected static function connection(Storage $storage, string $name, array $look, int $window): string
    {
        $lines = ["### {$name} · ".($look['engine'] ?? 'unknown engine'), ''];
        $at = (int) ($look['at'] ?? 0);
        $lines[] = '- Last looked at '.self::time($at).($at < now()->getTimestamp() - 300 ? ' (over 5 minutes ago: the scheduler may have stopped)' : '');

        if (isset($look['started_at'])) {
            $lines[] = '- Running since '.self::time((int) $look['started_at']);
        }

        $needs = [];
        $tables = [];

        foreach ((array) ($look['checks'] ?? []) as $check) {
            if (isset($check['needs'])) {
                $needs[$check['needs']][] = $check['key'];

                continue;
            }

            $lines[] = "- {$check['label']}: {$check['status']}, {$check['value']}".($check['advice'] !== '' ? ". {$check['advice']}" : '');

            if (($check['rows'] ?? []) !== []) {
                $tables[] = "{$check['label']}:\n\n".self::rows($check['rows']);
            }
        }

        foreach ($needs as $privilege => $keys) {
            $lines[] = "- Not seen without the {$privilege} privilege: ".implode(', ', $keys);
        }

        $lines = [...$lines, ...self::history($storage, $name, $window), ...self::size($storage, $name)];

        $sessions = array_slice((array) ($look['sessions'] ?? []), 0, 10);

        if ($sessions !== []) {
            $tables[] = "Active sessions, longest first:\n\n".self::table(
                ['Session', 'User', 'For', 'State', 'Blocked by', 'Query'],
                array_map(fn (array $session) => [$session['id'], $session['user'], self::seconds($session['seconds'] ?? 0), $session['state'] ?? '', $session['blocked_by'] ?? '', $session['query'] ?? ''], $sessions),
            );
        }

        return implode("\n", [...$lines, '', ...array_map(fn (string $table) => $table."\n", $tables), ...self::incidents($storage, $name, $window)]);
    }

    /**
     * Deadlocks, errors and connections over the period.
     *
     * @return list<string>
     */
    protected static function history(Storage $storage, string $name, int $window): array
    {
        $errors = [];

        foreach ($storage->aggregate('db_error', ['count'], $window, keyPrefix: Errors::prefix($name)) as $row) {
            $errors[(string) (json_decode((string) $row->key, true)[1] ?? '')] = (float) $row->count;
        }

        $lines = [];
        $deadlocks = (float) ($storage->total('db_deadlocks', ['count'], $window, $name)->count ?? 0);

        if ($deadlocks > 0) {
            $lines[] = '- Deadlocks counted by the database: '.Format::number($deadlocks).', of which '.Format::number($errors['deadlock'] ?? 0).' reached the app';
        }

        $kinds = array_filter(array_map(fn (string $label, string $kind) => isset($errors[$kind]) ? "{$label} ".Format::number($errors[$kind]) : null, Errors::KINDS, array_keys(Errors::KINDS)));
        $lines[] = '- Database errors: '.($kinds === [] ? 'none' : implode(', ', $kinds));

        $connections = $storage->total('db_connections', ['avg', 'max'], $window, $name);

        if (($connections->max ?? null) !== null) {
            $lines[] = '- Connections in use: '.round((float) $connections->avg).'% on average, '.round((float) $connections->max).'% at most';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected static function size(Storage $storage, string $name): array
    {
        $size = (array) json_decode((string) ($storage->values('database_size', [$name])->first()->value ?? ''), true);

        if ($size === []) {
            return [];
        }

        $history = array_values((array) ($size['history'] ?? []));
        [$first, $last] = [$history[0] ?? null, $history[count($history) - 1] ?? null];
        $days = $first === null || $last === null ? 0 : (int) round((strtotime((string) $last['day']) - strtotime((string) $first['day'])) / 86_400);
        $growth = $days > 0 ? ((int) $last['total'] - (int) $first['total']) / $days : null;

        $lines = ['- Size: '.Format::bytes($size['total'] ?? 0).($growth === null ? '' : ', '.($growth >= 0 ? '+' : '-').Format::bytes(abs($growth))." a day over the last {$days} days")];

        $largest = array_map(fn (array $table) => $table['name'].' '.Format::bytes($table['size'] ?? 0).($table['own'] ? ' (Laralyze\'s)' : ''), array_slice((array) ($size['tables'] ?? []), 0, 5));

        if ($largest !== []) {
            $lines[] = '- Largest tables: '.implode(', ', $largest);
        }

        foreach ((array) ($size['ids'] ?? []) as $id) {
            if (($id['status'] ?? Check::OK) !== Check::OK) {
                $lines[] = "- Ids running out: {$id['table']}.{$id['column']} ({$id['type']}) has used ".round((float) $id['percent']).'% of its ids';
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected static function incidents(Storage $storage, string $name, int $window): array
    {
        $since = now()->getTimestamp() - $window;
        $incidents = $storage->values('db_incident', keyPrefix: "{$name}|", limit: self::INCIDENTS)
            ->map(fn ($row) => json_decode((string) $row->value, true))
            ->filter(fn ($incident) => is_array($incident) && (int) ($incident['last_seen'] ?? 0) >= $since)
            ->values();

        $counts = [];

        foreach ($storage->aggregate('db_incident', ['count'], $window, keyPrefix: Errors::prefix($name)) as $row) {
            $kind = (string) (json_decode((string) $row->key, true)[1] ?? '');
            $counts[] = (Incidents::KINDS[$kind] ?? $kind).' '.Format::number($row->count);
        }

        if ($incidents->isEmpty()) {
            return ['No incidents in this period.'];
        }

        $lines = ['Incidents, newest first'.($counts === [] ? '' : ' (in all: '.implode(', ', $counts).')').":\n", self::table(
            ['Started', 'Lasted', 'Kind', 'Summary'],
            $incidents->map(fn (array $incident) => [
                self::time((int) $incident['started_at']),
                // A look a minute: one seen once lasted under a minute.
                $incident['last_seen'] > $incident['started_at'] ? self::seconds((int) $incident['last_seen'] - (int) $incident['started_at']) : 'under a minute',
                Incidents::KINDS[$incident['kind']] ?? $incident['kind'],
                $incident['summary'],
            ])->all(),
        )];

        foreach ($incidents as $incident) {
            if (isset($incident['deadlock']['transactions'])) {
                $own = array_filter((array) $incident['deadlock']['transactions'], fn (array $transaction) => ! str_starts_with((string) $transaction['holds'], 'laralyze_')) === [];
                $lines[] = "\nThe deadlock at {$incident['deadlock']['at']} (the database's time), {$incident['summary']}"
                    .($own ? ". It's between Laralyze's own writes to its tables, not the app's code: nothing in the app to change" : '').":\n";
                $lines[] = self::table(
                    ['#', 'Session', 'Query', 'Held', 'Waited for', 'Rolled back'],
                    array_map(fn (array $transaction) => [$transaction['number'], $transaction['session'] ?? '', $transaction['query'], $transaction['holds'], $transaction['waits'], $transaction['rolled_back'] ? 'yes' : 'no'], $incident['deadlock']['transactions']),
                );
            } elseif (($incident['sessions'] ?? []) !== []) {
                $lines[] = "\nSessions when {$incident['summary']}:\n";
                $lines[] = self::table(
                    ['Session', 'User', 'For', 'State', 'Blocked by', 'Query'],
                    array_map(fn (array $session) => [$session['id'], $session['user'], self::seconds($session['seconds'] ?? 0), $session['state'] ?? '', $session['blocked_by'] ?? '', $session['query'] ?? ''], array_slice($incident['sessions'], 0, 5)),
                );
            }
        }

        return $lines;
    }

    /**
     * A check's rows, with its own columns.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected static function rows(array $rows): string
    {
        $columns = array_keys($rows[0]);

        return self::table(
            array_map(fn (string $column) => self::COLUMNS[$column] ?? $column, $columns),
            array_map(fn (array $row) => array_map(fn (string $column) => $column === 'seconds' ? self::seconds($row[$column] ?? 0) : ($row[$column] ?? ''), $columns), $rows),
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected static function table(array $headers, array $rows): string
    {
        $line = fn (array $cells) => '| '.implode(' | ', array_map(fn ($cell) => str_replace(['|', "\n"], ['\|', ' '], (string) $cell), $cells)).' |';

        return implode("\n", [$line($headers), $line(array_fill(0, count($headers), '---')), ...array_map($line, $rows)]);
    }

    protected static function time(int $at): string
    {
        return Carbon::createFromTimestamp($at, (string) config('app.timezone', 'UTC'))->format('Y-m-d H:i');
    }

    protected static function seconds(mixed $seconds): string
    {
        return Format::duration((int) $seconds * 1_000);
    }
}
