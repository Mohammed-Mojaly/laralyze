<?php

use MohammedMojaly\Laralyze\Metrics\Histogram;

it('puts small and negative values in the first bin', function (float $value) {
    expect(Histogram::bin($value))->toBe(0);
})->with([0.0, 0.5, 1.0, -3.0]);

it('never lowers the bin as values grow', function () {
    $bins = array_map(Histogram::bin(...), [2, 10, 100, 1_000, 10_000, 100_000]);

    expect($bins)->toBe(array_values(array_unique($bins)))
        ->and($bins)->toEqual(collect($bins)->sort()->values()->all());
});

it('keeps each value inside its bin bounds', function (float $value) {
    $bin = Histogram::bin($value);

    expect($value)->toBeLessThanOrEqual(Histogram::upperBound($bin))
        ->and($value)->toBeGreaterThan(Histogram::lowerBound($bin));
})->with([1.5, 42.0, 999.9, 12_345.0]);

it('caps huge values in the last bin', function () {
    expect(Histogram::bin(1e12))->toBe(Histogram::MAX_BIN);
});

it('estimates percentiles of a uniform set within 12 percent', function (float $percentile, float $exact) {
    $counts = [];

    foreach (range(1, 1_000) as $value) {
        $bin = Histogram::bin($value);
        $counts[$bin] = ($counts[$bin] ?? 0) + 1;
    }

    expect(Histogram::percentile($counts, $percentile))
        ->toBeGreaterThan($exact * 0.88)
        ->toBeLessThan($exact * 1.12);
})->with([
    'p50' => [0.50, 500.0],
    'p95' => [0.95, 950.0],
    'p99' => [0.99, 990.0],
]);

it('returns null when there is nothing to measure', function () {
    expect(Histogram::percentile([], 0.95))->toBeNull()
        ->and(Histogram::percentile([3 => 0], 0.95))->toBeNull();
});
