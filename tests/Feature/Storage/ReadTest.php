<?php

use Illuminate\Support\Carbon;
use Laralyze\Facades\Laralyze;
use Laralyze\Storage\DatabaseStorage;

beforeEach(function () {
    $this->now = Carbon::parse('2026-09-30 12:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->storage = app(DatabaseStorage::class);
});

function requestAt(int $timestamp, string $route, float $duration): void
{
    Laralyze::record('request', $route, $duration, $timestamp)->count()->avg()->min()->max()->histogram();
}

it('summarises each key over the window', function () {
    $now = $this->now->getTimestamp();
    requestAt($now - 60, 'GET /', 100);
    requestAt($now - 120, 'GET /', 300);
    requestAt($now - 60, 'GET /about', 50);
    Laralyze::flush();

    $rows = $this->storage->aggregate('request', ['count', 'avg', 'min', 'max'], 3_600)->keyBy('key');

    expect($rows['GET /'])->toMatchArray(['count' => 2.0, 'avg' => 200.0, 'min' => 100.0, 'max' => 300.0])
        ->and($rows['GET /about'])->toMatchArray(['count' => 1.0, 'avg' => 50.0]);
});

it('leaves out data older than the window', function () {
    $now = $this->now->getTimestamp();
    requestAt($now - 60, 'GET /', 100);
    requestAt($now - 7_200, 'GET /old', 100);
    Laralyze::flush();

    expect($this->storage->aggregate('request', ['count'], 3_600)->pluck('key')->all())->toBe(['GET /']);
});

it('orders by an aggregate and limits the result', function () {
    $now = $this->now->getTimestamp();
    foreach (['GET /a' => 1, 'GET /b' => 3, 'GET /c' => 2] as $route => $times) {
        foreach (range(1, $times) as $i) {
            requestAt($now - 60, $route, 10);
        }
    }
    Laralyze::flush();

    $rows = $this->storage->aggregate('request', ['count'], 3_600, orderBy: 'count', limit: 2);

    expect($rows->pluck('key')->all())->toBe(['GET /b', 'GET /c']);
});

it('estimates percentiles per key', function () {
    $now = $this->now->getTimestamp();
    foreach (range(1, 100) as $duration) {
        requestAt($now - 60, 'GET /', $duration * 10);
    }
    Laralyze::flush();

    $p95 = $this->storage->aggregate('request', ['p95'], 3_600)->first()->p95;

    expect($p95)->toBeGreaterThan(950 * 0.88)->toBeLessThan(950 * 1.12);
});

it('totals every key together', function () {
    $now = $this->now->getTimestamp();
    requestAt($now - 60, 'GET /', 100);
    requestAt($now - 60, 'GET /about', 300);
    Laralyze::flush();

    $total = $this->storage->total('request', ['count', 'avg', 'max'], 3_600);

    expect($total)->toMatchArray(['count' => 2.0, 'avg' => 200.0, 'max' => 300.0]);
});

it('returns nulls for a window with no data', function () {
    $total = $this->storage->total('request', ['count', 'avg', 'p95'], 3_600);

    expect($total)->toMatchArray(['count' => null, 'avg' => null, 'p95' => null]);
});

it('draws a graph with one point per slot and gaps as null', function () {
    $now = $this->now->getTimestamp();
    requestAt($now - 60, 'GET /', 100);
    requestAt($now - 60, 'GET /', 100);
    requestAt($now - 1_800, 'GET /', 100);
    Laralyze::flush();

    $graph = $this->storage->graph('request', 'count', 3_600);

    expect($graph)->toHaveCount(61)
        ->and($graph->filter()->values()->all())->toBe([1.0, 2.0])
        ->and($graph->keys()->first())->toBe($now - 3_600)
        ->and($graph->keys()->last())->toBe($now);
});

it('graphs a percentile over time', function () {
    $now = $this->now->getTimestamp();
    foreach (range(1, 20) as $duration) {
        requestAt($now - 60, 'GET /', $duration * 10);
    }
    Laralyze::flush();

    $graph = $this->storage->graph('request', 'p95', 3_600);

    expect($graph->filter()->first())->toBeGreaterThan(150)->toBeLessThan(230);
});

it('switches to hour buckets for windows longer than a day', function () {
    $now = $this->now->getTimestamp();
    requestAt($now - 3 * 86_400, 'GET /', 100);
    Laralyze::flush();

    expect($this->storage->total('request', ['count'], 7 * 86_400)->count)->toBe(1.0)
        ->and($this->storage->graph('request', 'count', 7 * 86_400))->toHaveCount(57);
});

it('reads the latest values', function () {
    Laralyze::set('server', 'web-1', '{"cpu":10}');
    Laralyze::set('server', 'web-2', '{"cpu":20}');
    Laralyze::flush();

    expect($this->storage->values('server')->pluck('value', 'key')->all())
        ->toBe(['web-1' => '{"cpu":10}', 'web-2' => '{"cpu":20}'])
        ->and($this->storage->values('server', ['web-2'])->pluck('key')->all())->toBe(['web-2']);
});

it('rejects aggregates it does not know', function () {
    $this->storage->aggregate('request', ['median'], 3_600);
})->throws(InvalidArgumentException::class);
