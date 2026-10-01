<?php

use MohammedMojaly\Laralyze\Dashboard\Range;
use MohammedMojaly\Laralyze\Support\Chart;

function chart(): Chart
{
    return new Chart([
        '2xx' => collect([0 => 4.0, 60 => null, 120 => 6.0]),
        '5xx' => collect([0 => 1.0, 60 => null, 120 => null]),
    ], Range::Hour);
}

it('uses the slots of the series', function () {
    expect(chart()->slots)->toBe([0, 60, 120])
        ->and(chart()->names())->toBe(['2xx', '5xx']);
});

it('finds the top of the y axis', function () {
    expect(chart()->max())->toBe(6.0)
        ->and(chart()->max(stacked: true))->toBe(6.0)
        ->and(chart()->stack(0))->toBe(5.0);
});

it('is empty when nothing was recorded', function () {
    $chart = new Chart(['2xx' => collect([0 => null, 60 => null])], Range::Hour);

    expect($chart->isEmpty())->toBeTrue()
        ->and(chart()->isEmpty())->toBeFalse();
});

it('breaks lines where there is no data', function () {
    expect(chart()->lines('2xx', max: 6.0, width: 600, height: 100))->toBe(['0,33.3 0,33.3', '600,0 600,0'])
        ->and(chart()->lines('5xx', max: 6.0))->toBe(['0,83.3 0,83.3']);
});

it('labels slots in the app timezone', function () {
    config(['app.timezone' => 'Asia/Riyadh']);

    expect(chart()->label(0))->toBe('03:00');
});
