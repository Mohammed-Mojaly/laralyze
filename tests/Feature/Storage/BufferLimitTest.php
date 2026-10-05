<?php

use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Metrics\Period;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
    Laralyze::flush();
});

function bufferedCount(string $type, string $key, int $period = Period::MINUTE): ?float
{
    $value = laralyzeRows('laralyze_aggregates')->where('type', $type)->where('key', $key)->where('aggregate', 'count')->where('period', $period)->value('value');

    return $value === null ? null : (float) $value;
}

function bufferDrops(): float
{
    return (float) laralyzeRows('laralyze_aggregates')->where('type', 'laralyze_dropped')->where('period', Period::MINUTE)->sum('value');
}

it('writes early in a command when the buffer fills, counting each value once', function () {
    Laralyze::buffer()->limitTo(3);

    Laralyze::record('import', 'a')->count();
    Laralyze::record('import', 'b')->count();
    Laralyze::flush();

    expect(bufferedCount('import', 'a'))->toBe(1.0)
        ->and(bufferedCount('import', 'b'))->toBe(1.0)
        ->and(bufferedCount('import', 'b', Period::HOUR))->toBe(1.0);
});

it('drops a value a full web request has no room for, in both periods', function () {
    Laralyze::buffer()->limitTo(3);

    asWebRequest(function () {
        Laralyze::record('import', 'a')->count();
        Laralyze::record('import', 'b')->count();
    });
    Laralyze::flush();

    expect(bufferedCount('import', 'a'))->toBe(1.0)
        ->and(bufferedCount('import', 'b'))->toBeNull()
        ->and(bufferedCount('import', 'b', Period::HOUR))->toBeNull()
        ->and(bufferDrops())->toBe(1.0);
});

it('makes room for a set value in a command, and drops it in a full web request', function () {
    Laralyze::buffer()->limitTo(2);

    Laralyze::record('import', 'a')->count();
    Laralyze::set('plan', 'pro', 'yearly');
    Laralyze::flush();

    expect(laralyzeRows('laralyze_values')->where('type', 'plan')->count())->toBe(1);

    asWebRequest(function () {
        Laralyze::record('import', 'a')->count();
        Laralyze::set('plan', 'team', 'monthly');
    });
    Laralyze::flush();

    expect(laralyzeRows('laralyze_values')->where('type', 'plan')->pluck('key')->all())->toBe(['pro'])
        ->and(bufferDrops())->toBe(1.0);
});

it('writes early without running the digesters again when one fills the buffer', function () {
    Laralyze::buffer()->limitTo(4);
    $pending = ['a', 'b', 'c', 'd', 'e'];
    $runs = 0;

    // Like a recorder that turns what it gathered into metrics, then forgets it.
    Laralyze::digestUsing(function () use (&$pending, &$runs) {
        $runs++;

        foreach ($pending as $key) {
            Laralyze::record('import', $key)->count();
        }

        $pending = [];
    });

    Laralyze::flush();

    expect($runs)->toBe(1)
        ->and(array_map(fn (string $key) => bufferedCount('import', $key), ['a', 'b', 'c', 'd', 'e']))->toBe([1.0, 1.0, 1.0, 1.0, 1.0])
        ->and(bufferDrops())->toBe(0.0);
});
