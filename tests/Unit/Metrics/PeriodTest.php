<?php

use MohammedMojaly\Laralyze\Metrics\Period;

it('floors a timestamp to the start of its bucket', function (int $timestamp, int $period, int $expected) {
    expect(Period::bucket($timestamp, $period))->toBe($expected);
})->with([
    'start of a minute' => [1_727_700_000, Period::MINUTE, 1_727_700_000],
    'middle of a minute' => [1_727_700_059, Period::MINUTE, 1_727_700_000],
    'middle of an hour' => [1_727_701_799, Period::HOUR, 1_727_701_200],
]);

it('reads windows up to a day from minute buckets', function () {
    expect(Period::forWindow(15 * 60))->toBe(Period::MINUTE)
        ->and(Period::forWindow(86_400))->toBe(Period::MINUTE);
});

it('reads windows longer than a day from hour buckets', function () {
    expect(Period::forWindow(86_401))->toBe(Period::HOUR)
        ->and(Period::forWindow(30 * 86_400))->toBe(Period::HOUR);
});
