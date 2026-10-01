<?php

use MohammedMojaly\Laralyze\Support\Format;

it('formats counts', function (int|float|null $value, string $expected) {
    expect(Format::number($value))->toBe($expected);
})->with([
    [null, '—'],
    [0, '0'],
    [950, '950'],
    [1_234, '1,234'],
    [2.5, '2.5'],
    [12_400, '12.4K'],
    [3_400_000, '3.4M'],
    [2_000_000, '2M'],
]);

it('formats durations in milliseconds', function (int|float|null $value, string $expected) {
    expect(Format::duration($value))->toBe($expected);
})->with([
    [null, '—'],
    [0.42, '0.4 ms'],
    [4, '4 ms'],
    [86.4, '86 ms'],
    [1_240, '1.24 s'],
    [150_000, '2.5 min'],
]);

it('formats shares of a total', function () {
    expect(Format::percent(1, 4))->toBe('25%')
        ->and(Format::percent(1, 3))->toBe('33.3%')
        ->and(Format::percent(0, 10))->toBe('0%')
        ->and(Format::percent(1, 5_000))->toBe('<0.1%')
        ->and(Format::percent(1, 0))->toBe('—');
});
