<?php

namespace MohammedMojaly\Laralyze\Dashboard;

use Illuminate\Support\Facades\Cache;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Laralyze;
use stdClass;

/**
 * Counts next to sidebar links, so problems show before their page is opened.
 */
final class Badges
{
    public function __construct(private Storage $storage, private Issues $issues, private Laralyze $laralyze) {}

    /**
     * @return array<string, int> page key => count
     */
    public function for(Range $range): array
    {
        return (array) $this->laralyze->rescue(fn () => $this->laralyze->ignore(fn () => Cache::remember(
            'laralyze:badges:'.$range->value,
            30,
            fn () => array_filter([
                'exceptions' => $this->openExceptions($range->seconds()),
                'findings' => $this->storage->countKeys('n_plus_one', $range->seconds()) + $this->storage->countKeys('duplicate_query', $range->seconds()),
            ]),
        )), []);
    }

    private function openExceptions(int $window): int
    {
        $lastSeen = $this->storage->aggregate('exception', ['max'], $window, limit: 1_000)
            ->mapWithKeys(fn (stdClass $row) => [(string) $row->key => $row->max])
            ->all();

        return count(array_filter($this->issues->statuses($lastSeen), fn (string $status) => $status === Issues::OPEN || $status === Issues::REOPENED));
    }
}
