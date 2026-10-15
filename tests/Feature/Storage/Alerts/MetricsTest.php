<?php

use Illuminate\Support\Facades\Log;
use MohammedMojaly\Laralyze\Alerts\Metrics\Catalog;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Recorders;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

it('counts per key, adds them up all together, and filters on one dimension', function () {
    foreach (['App\Jobs\Import' => 3, 'App\Jobs\Mail' => 2] as $job => $times) {
        foreach (range(1, $times) as $i) {
            Laralyze::record('job_failed', $job)->count();
        }
    }
    Laralyze::flush();

    expect(measured(['metric' => 'jobs.failed']))->toBe(['*' => 5.0])
        ->and(measured(['metric' => 'jobs.failed', 'mode' => 'each']))->toBe(['App\Jobs\Import' => 3.0, 'App\Jobs\Mail' => 2.0])
        ->and(measured(['metric' => 'jobs.failed', 'filter' => ['by' => 'job', 'operator' => 'contains', 'values' => ['import']]]))->toBe(['*' => 3.0])
        ->and(measured(['metric' => 'jobs.failed', 'filter' => ['by' => 'job', 'operator' => 'equals', 'values' => ['App\Jobs\Nothing']]]))->toBe(['*' => null]);
});

it('measures the error rate only with at least 20 requests', function () {
    foreach (range(1, 19) as $i) {
        Laralyze::record('request', 'GET /checkout', 10)->count();
        Laralyze::record($i <= 5 ? 'request_5xx' : 'request_2xx', 'GET /checkout')->count();
    }
    Laralyze::flush();

    expect(measured(['metric' => 'requests.error_rate']))->toBe(['*' => null]);

    Laralyze::record('request', 'GET /checkout', 10)->count();
    Laralyze::flush();

    expect(measured(['metric' => 'requests.error_rate']))->toBe(['*' => 25.0]);
});

it('judges each route on its own sample', function () {
    foreach (range(1, 20) as $i) {
        Laralyze::record('request', 'GET /a', 10)->count();
        Laralyze::record($i <= 2 ? 'request_5xx' : 'request_2xx', 'GET /a')->count();
    }
    foreach (range(1, 5) as $i) {
        Laralyze::record('request', 'GET /b', 10)->count();
        Laralyze::record('request_5xx', 'GET /b')->count();
    }
    Laralyze::flush();

    expect(measured(['metric' => 'requests.error_rate', 'mode' => 'each']))->toBe(['GET /a' => 10.0, 'GET /b' => null])
        ->and(measured(['metric' => 'requests.error_rate']))->toBe(['*' => 28.0]);
});

it('takes the slowest item all together for a percentile', function () {
    foreach (range(1, 20) as $i) {
        Laralyze::record('request', 'GET /fast', 10)->avg()->max()->histogram();
        Laralyze::record('request', 'GET /slow', 900)->avg()->max()->histogram();
    }
    Laralyze::flush();

    $each = measured(['metric' => 'requests.p95', 'mode' => 'each']);

    expect($each['GET /slow'])->toBeGreaterThan(500)
        ->and($each['GET /fast'])->toBeLessThan(50)
        ->and(measured(['metric' => 'requests.p95']))->toBe(['*' => $each['GET /slow']]);
});

it('reads the wait time in seconds', function () {
    Laralyze::record('queue_wait', 'redis:default', 4_000)->avg()->max();
    Laralyze::record('queue_wait', 'redis:default', 2_000)->avg()->max();
    Laralyze::flush();

    expect(measured(['metric' => 'queues.wait'])['*'])->toEqualWithDelta(3.0, 0.001);
});

it('counts log entries at error and above, by level, or by message', function () {
    $this->rebootWith(['laralyze.recorders' => [Recorders\Logs::class => ['enabled' => true]]]);
    app()->detectEnvironment(fn () => 'local');

    Log::error('Payment gateway timed out');
    Log::error('Cache miss');
    Log::warning('Payment is slow');
    Laralyze::flush();

    expect(measured(['metric' => 'logs.entries']))->toBe(['*' => 2.0])
        ->and(measured(['metric' => 'logs.entries', 'mode' => 'each', 'filter' => ['by' => 'level', 'operator' => 'in', 'values' => ['warning']]]))->toBe(['warning' => 1.0])
        ->and(measured(['metric' => 'logs.entries', 'filter' => ['by' => 'message', 'operator' => 'contains', 'values' => ['payment']]]))->toBe(['*' => 1.0]);
});

it('adds up AI cost by model, agent or user', function () {
    Laralyze::record('ai_model_cost', '["openai","gpt-4o"]', 2_000_000)->sum();
    Laralyze::record('ai_model_cost', '["anthropic","claude"]', 500_000)->sum();
    Laralyze::record('ai_cost', 'App\Ai\Support', 2_500_000)->sum();
    Laralyze::record('ai_user_cost', '7', 1_000_000)->sum();
    Laralyze::flush();

    expect(measured(['metric' => 'ai.cost', 'window' => 86_400]))->toBe(['*' => 2.5])
        ->and(measured(['metric' => 'ai.cost', 'window' => 86_400, 'filter' => ['by' => 'model', 'operator' => 'equals', 'values' => ['gpt-4o']]]))->toBe(['*' => 2.0])
        ->and(measured(['metric' => 'ai.cost', 'window' => 86_400, 'mode' => 'each', 'filter' => ['by' => 'user', 'operator' => 'equals', 'values' => ['7']]]))->toBe(['7' => 1.0])
        ->and(app(Catalog::class)->find('ai.cost')->itemLabel('["openai","gpt-4o"]'))->toBe('openai · gpt-4o');
});

it('formats values in their unit', function () {
    $catalog = app(Catalog::class);

    expect($catalog->find('requests.error_rate')->format(7.456))->toBe('7.5%')
        ->and($catalog->find('ai.cost')->format(12.5))->toBe('$12.50')
        ->and($catalog->find('jobs.failed')->format(null))->toBe('—');
});

it('suggests values seen in the last day, and fixed choices for lists', function () {
    Laralyze::record('job_failed', 'App\Jobs\Import')->count();
    Laralyze::flush();

    $catalog = app(Catalog::class);

    expect($catalog->find('jobs.failed')->suggestions(app(Storage::class), 'job'))->toBe(['App\Jobs\Import' => 'App\Jobs\Import'])
        ->and($catalog->find('logs.entries')->suggestions(app(Storage::class), 'level'))->toHaveKey('error', 'Error');
});

it('lists only the metrics whose recorder is on', function () {
    $this->rebootWith(['laralyze.recorders' => [Recorders\Requests::class => ['enabled' => true], Recorders\Jobs::class => ['enabled' => false]]]);

    $available = array_keys(app(Catalog::class)->available());

    expect($available)->toContain('requests.error_rate')->not->toContain('jobs.failed');
});
