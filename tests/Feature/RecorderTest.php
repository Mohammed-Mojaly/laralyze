<?php

use Illuminate\Support\Facades\Event;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Recorders\RecorderManager;
use MohammedMojaly\Laralyze\Tests\Fixtures\OrderPlaced;
use MohammedMojaly\Laralyze\Tests\Fixtures\OrdersRecorder;

function useOrdersRecorder(array $options = []): void
{
    app(RecorderManager::class)->register([
        OrdersRecorder::class => ['enabled' => true, ...$options],
    ]);
}

function bufferedTotal(string $type, string $aggregate = 'count'): float
{
    return array_sum(array_column(array_filter(
        Laralyze::buffer()->rows(),
        fn (array $row) => $row['type'] === $type && $row['aggregate'] === $aggregate && $row['period'] === 60,
    ), 'value'));
}

it('does not listen at all when a recorder is disabled', function () {
    app(RecorderManager::class)->register([OrdersRecorder::class => ['enabled' => false]]);

    expect(Event::hasListeners(OrderPlaced::class))->toBeFalse();
});

it('records from the events it listens to', function () {
    useOrdersRecorder();

    event(new OrderPlaced('pro', 49));
    event(new OrderPlaced('pro', 51));

    expect(bufferedTotal('order'))->toBe(2.0)
        ->and(bufferedTotal('order', 'sum'))->toBe(100.0);
});

it('keeps the app running when a recorder throws', function () {
    useOrdersRecorder();

    expect(fn () => event(new OrderPlaced('explode', 1)))->not->toThrow(Throwable::class);
});

it('skips events while Laralyze is not recording', function () {
    useOrdersRecorder();

    Laralyze::ignore(fn () => event(new OrderPlaced('pro', 10)));

    expect(Laralyze::buffer()->isEmpty())->toBeTrue();
});

it('applies a threshold per key with a default', function () {
    useOrdersRecorder(['threshold' => ['#^enterprise$#' => 1_000, 'default' => 100]]);

    event(new OrderPlaced('pro', 150));
    event(new OrderPlaced('enterprise', 150));
    event(new OrderPlaced('enterprise', 1_500));

    expect(bufferedTotal('large_order'))->toBe(2.0);
});

it('accepts a single number as the threshold', function () {
    useOrdersRecorder(['threshold' => 100]);

    event(new OrderPlaced('pro', 150));

    expect(bufferedTotal('large_order'))->toBe(1.0);
});

it('ignores keys matching the ignore patterns', function () {
    useOrdersRecorder(['ignore' => ['#^test-#']]);

    event(new OrderPlaced('test-plan', 10));
    event(new OrderPlaced('pro', 10));

    expect(bufferedTotal('order'))->toBe(1.0);
});

it('groups keys with the first matching pattern', function () {
    useOrdersRecorder(['groups' => ['#^pro-.*#' => 'pro', '#^.*$#' => 'other']]);

    event(new OrderPlaced('pro-monthly', 10));
    event(new OrderPlaced('pro-yearly', 10));

    expect(array_values(array_unique(array_column(Laralyze::buffer()->rows(), 'key'))))->toBe(['pro']);
});

it('scales sampled events back up', function () {
    mt_srand(1);
    useOrdersRecorder(['sample_rate' => 0.5]);

    foreach (range(1, 1_000) as $i) {
        event(new OrderPlaced('pro', 1));
    }

    expect(bufferedTotal('order'))->toBeGreaterThan(900)->toBeLessThan(1_100);
});
