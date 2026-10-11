<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Database\Check;

/**
 * The database right now: connections, long-running queries, locks, open
 * transactions and restarts, as of the scheduler's last look.
 */
#[Lazy]
class DatabaseNow extends DatabaseCard
{
    /**
     * A restart within this many seconds is worth a line.
     */
    public const RESTARTED_WITHIN = 86_400;

    public function render(): View
    {
        $snapshot = $this->latest('database');
        $checks = [];
        $needs = [];

        foreach ((array) ($snapshot['checks'] ?? []) as $check) {
            if (isset($check['needs'])) {
                $needs[$check['needs']] = ($needs[$check['needs']] ?? 0) + 1;
            } else {
                $checks[] = $check;
            }
        }

        $startedAt = isset($snapshot['started_at']) ? (int) $snapshot['started_at'] : null;

        if ($startedAt !== null && $startedAt > (int) ($snapshot['at'] ?? 0) - self::RESTARTED_WITHIN) {
            $checks[] = Check::make('restarted', 'Restarted', Check::WARNING, now()->setTimestamp($startedAt)->diffForHumans(), 'The database server started again. If nobody restarted it, check its logs.');
        }

        return view('laralyze::cards.database-now', [
            'snapshot' => $snapshot,
            'checks' => $checks,
            'needs' => $needs,
            'startedAt' => $startedAt,
            'stale' => $snapshot !== null && (int) $snapshot['at'] < now()->getTimestamp() - 300,
        ]);
    }
}
