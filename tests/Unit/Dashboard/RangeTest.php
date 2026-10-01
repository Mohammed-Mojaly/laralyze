<?php

use MohammedMojaly\Laralyze\Dashboard\Range;

it('reads the period from the query string', function (mixed $value, Range $expected) {
    expect(Range::fromQuery($value))->toBe($expected);
})->with([
    ['15m', Range::FifteenMinutes],
    ['24h', Range::Day],
    ['30d', Range::Month],
    ['2y', Range::Hour],
    [null, Range::Hour],
    [['1h'], Range::Hour],
]);

it('knows how long each period is', function () {
    expect(Range::FifteenMinutes->seconds())->toBe(900)
        ->and(Range::Hour->seconds())->toBe(3_600)
        ->and(Range::Week->seconds())->toBe(7 * 86_400)
        ->and(Range::Month->seconds())->toBe(30 * 86_400);
});

it('labels points with a date once the window spans days', function () {
    expect(Range::Hour->timeFormat())->toBe('H:i')
        ->and(Range::Day->timeFormat())->toBe('M j, H:i');
});
