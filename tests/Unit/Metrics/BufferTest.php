<?php

use Laralyze\Facades\Laralyze;
use Laralyze\Metrics\Buffer;
use Laralyze\Metrics\Histogram;
use Laralyze\Metrics\Period;

const AT = 1_727_700_030; // 2024-09-30 12:40:30 UTC

function rowsFor(string $aggregate, int $period = Period::MINUTE): array
{
    return array_values(array_filter(
        Laralyze::buffer()->rows(),
        fn (array $row) => $row['aggregate'] === $aggregate && $row['period'] === $period,
    ));
}

it('adds counts for the same key in minute and hour buckets', function () {
    Laralyze::record('request', 'GET /', timestamp: AT)->count();
    Laralyze::record('request', 'GET /', timestamp: AT + 10)->count();

    expect(rowsFor('count'))->toHaveCount(1)
        ->and(rowsFor('count')[0])->toMatchArray([
            'bucket' => Period::bucket(AT, Period::MINUTE),
            'type' => 'request',
            'key' => 'GET /',
            'value' => 2.0,
        ])
        ->and(rowsFor('count', Period::HOUR)[0]['value'])->toBe(2.0);
});

it('keeps the smallest and largest values', function () {
    foreach ([120, 40, 300] as $duration) {
        Laralyze::record('request', 'GET /', $duration, AT)->min()->max();
    }

    expect(rowsFor('min')[0]['value'])->toBe(40.0)
        ->and(rowsFor('max')[0]['value'])->toBe(300.0);
});

it('stores averages as a sum and a count', function () {
    Laralyze::record('request', 'GET /', 100, AT)->avg();
    Laralyze::record('request', 'GET /', 50, AT)->avg();

    expect(rowsFor('sum')[0]['value'])->toBe(150.0)
        ->and(rowsFor('count')[0]['value'])->toBe(2.0);
});

it('counts once when both count and avg are asked for', function () {
    Laralyze::record('request', 'GET /', 100, AT)->count()->avg();

    expect(rowsFor('count')[0]['value'])->toBe(1.0);
});

it('counts a histogram sample in its bin', function () {
    Laralyze::record('request', 'GET /', 250, AT)->histogram();

    expect(rowsFor('h'.Histogram::bin(250))[0]['value'])->toBe(1.0);
});

it('separates keys, types and buckets', function () {
    Laralyze::record('request', 'GET /', timestamp: AT)->count();
    Laralyze::record('request', 'GET /about', timestamp: AT)->count();
    Laralyze::record('job', 'GET /', timestamp: AT)->count();
    Laralyze::record('request', 'GET /', timestamp: AT + 60)->count();

    expect(rowsFor('count'))->toHaveCount(4)
        ->and(rowsFor('count', Period::HOUR))->toHaveCount(3);
});

it('scales sampled counts back up', function () {
    Laralyze::record('request', 'GET /', 80, AT)->sample(0.1)->count()->sum()->max();

    expect(rowsFor('count')[0]['value'])->toBe(10.0)
        ->and(rowsFor('sum')[0]['value'])->toBe(800.0)
        ->and(rowsFor('max')[0]['value'])->toBe(80.0);
});

it('records nothing while recording is stopped', function () {
    Laralyze::stopRecording();

    Laralyze::record('request', 'GET /', timestamp: AT)->count()->max();

    expect(Laralyze::buffer()->isEmpty())->toBeTrue();
});

it('records nothing inside ignore', function () {
    Laralyze::ignore(fn () => Laralyze::record('request', 'GET /', timestamp: AT)->count());

    expect(Laralyze::buffer()->isEmpty())->toBeTrue();
});

it('keeps only the latest value for a set key', function () {
    Laralyze::set('server', 'web-1', '{"cpu":10}', AT);
    Laralyze::set('server', 'web-1', '{"cpu":55}', AT + 5);

    expect(Laralyze::buffer()->values())->toBe([
        ['timestamp' => AT + 5, 'type' => 'server', 'key' => 'web-1', 'value' => '{"cpu":55}'],
    ]);
});

it('empties the buffer when it is drained', function () {
    Laralyze::record('request', 'GET /', timestamp: AT)->count();
    Laralyze::set('server', 'web-1', '{}', AT);

    [$rows, $values] = Laralyze::buffer()->drain();

    expect($rows)->not->toBeEmpty()
        ->and($values)->toHaveCount(1)
        ->and(Laralyze::buffer()->isEmpty())->toBeTrue();
});

it('refuses new rows once full but still merges existing ones', function () {
    // One key fills a minute row and an hour row.
    $buffer = new Buffer(limit: 2);

    $first = $buffer->add('request', 'GET /', 'count', 1, AT);
    $other = $buffer->add('request', 'GET /other', 'count', 1, AT);
    $again = $buffer->add('request', 'GET /', 'count', 1, AT);

    expect([$first, $other, $again])->toBe([true, false, true])
        ->and($buffer->size())->toBe(2)
        ->and($buffer->rows()[0]['value'])->toBe(2.0);
});
