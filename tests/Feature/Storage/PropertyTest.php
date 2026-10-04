<?php

use Illuminate\Support\Carbon;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;

it('reads back exactly what a brute-force count of the same events gives', function () {
    $now = Carbon::parse('2026-09-30 12:34:56', 'UTC')->getTimestamp();
    $this->travelTo(Carbon::createFromTimestamp($now));
    mt_srand(20260930);

    $events = [];

    foreach (range(1, 2_000) as $i) {
        $event = [
            'type' => ['orders', 'signups', 'imports'][mt_rand(0, 2)],
            'key' => 'key-'.mt_rand(1, 5),
            'value' => mt_rand(1, 1_000),
            // Bias towards recent events so short windows have data too.
            'timestamp' => $now - (mt_rand(0, 1) ? mt_rand(0, 7_200) : mt_rand(0, 30 * 86_400)),
        ];

        $events[] = $event;
        Laralyze::record($event['type'], $event['key'], $event['value'], $event['timestamp'])->count()->sum()->min()->max();
    }

    Laralyze::flush();

    $storage = app(Storage::class);

    foreach ([900, 3_600, 86_400, 7 * 86_400, 30 * 86_400] as $window) {
        // Windows start at the beginning of the bucket they fall in.
        $period = $window <= 86_400 ? 60 : 3_600;
        $since = ($now - $window) - (($now - $window) % $period);

        foreach (['orders', 'signups', 'imports'] as $type) {
            $expected = [];

            foreach ($events as $event) {
                if ($event['type'] !== $type || $event['timestamp'] < $since) {
                    continue;
                }

                $row = $expected[$event['key']] ?? ['count' => 0, 'sum' => 0, 'min' => PHP_INT_MAX, 'max' => 0];

                $expected[$event['key']] = [
                    'count' => $row['count'] + 1,
                    'sum' => $row['sum'] + $event['value'],
                    'min' => min($row['min'], $event['value']),
                    'max' => max($row['max'], $event['value']),
                ];
            }

            ksort($expected);

            $actual = $storage->aggregate($type, ['count', 'sum', 'min', 'max'], $window)
                ->sortBy('key')
                ->mapWithKeys(fn (object $row) => [$row->key => [
                    'count' => (int) $row->count,
                    'sum' => (int) $row->sum,
                    'min' => (int) $row->min,
                    'max' => (int) $row->max,
                ]])
                ->all();

            expect($actual)->toBe($expected, "{$type} over {$window}s");

            expect((int) $storage->total($type, ['count'], $window)->count)
                ->toBe(array_sum(array_column($expected, 'count')), "{$type} total over {$window}s");
        }
    }
});
