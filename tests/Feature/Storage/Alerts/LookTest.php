<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MohammedMojaly\Laralyze\Alerts\Alerts;
use MohammedMojaly\Laralyze\Alerts\History;
use MohammedMojaly\Laralyze\Alerts\Look;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Alerts\Rules;
use MohammedMojaly\Laralyze\Alerts\States;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
    config(['laralyze.alerts.slack' => 'https://hooks.slack.test/abc']);
});

function failJobs(int $count, string $job = 'App\Jobs\Import'): void
{
    foreach (range(1, $count) as $i) {
        Laralyze::record('job_failed', $job, timestamp: now()->getTimestamp())->count();
    }

    Laralyze::flush();
}

/**
 * @param  array<string, int>  $used  Percent used on "/" per server.
 */
function disks(array $used, ?int $at = null): void
{
    $at ??= now()->getTimestamp();

    app(Storage::class)->store([], array_map(fn (string $server, int $percent) => [
        'timestamp' => $at,
        'type' => 'server',
        'key' => $server,
        'value' => (string) json_encode(['disks' => [['directory' => '/', 'used' => $percent, 'total' => 100]]]),
    ], array_keys($used), $used));
}

it('fires once, stays quiet while firing, and recovers after two clean looks', function () {
    alertRule(['name' => 'Failed jobs', 'metric' => 'jobs.failed', 'operator' => '>=', 'threshold' => 10]);
    failJobs(12);

    look();
    $this->travel(1)->minutes();
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['fired'])
        ->and(alertHistory()[0]['title'])->toBe('Failed jobs: 12 (>= 10)')
        ->and(alertHistory()[0]['deliveries'])->toHaveKey('slack');

    // The window empties: one clean look isn't enough.
    $this->travel(6)->minutes();
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['fired']);

    $this->travel(1)->minutes();
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['recovered', 'fired'])
        ->and(app(States::class)->firing())->toBe([]);
});

it('starts over when the condition comes back after one clean look', function () {
    alertRule(['metric' => 'servers.disk', 'operator' => '>=', 'threshold' => 90]);
    disks(['web-1' => 95]);
    look();

    disks(['web-1' => 50]);
    look();
    disks(['web-1' => 95]);
    look();
    disks(['web-1' => 50]);
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['fired']);
});

it('counts a look below the minimum sample as clean, so a firing rule recovers when traffic drops', function () {
    alertRule(['metric' => 'requests.error_rate', 'operator' => '>', 'threshold' => 5]);
    $at = now()->getTimestamp();

    foreach (range(1, 20) as $i) {
        Laralyze::record('request', 'GET /a', 10, $at)->count();
        Laralyze::record('request_5xx', 'GET /a', 1, $at)->count();
    }
    Laralyze::flush();
    look();

    $this->travel(6)->minutes();
    foreach (range(1, 2) as $i) {
        Laralyze::record('request', 'GET /a', 10, now()->getTimestamp())->count();
        Laralyze::record('request_5xx', 'GET /a', 1, now()->getTimestamp())->count();
    }
    Laralyze::flush();
    look();
    $this->travel(1)->minutes();
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['recovered', 'fired']);
});

it('keeps each item apart, lists five per message, and recovers items with no data', function () {
    alertRule(['name' => 'Disk', 'metric' => 'servers.disk', 'operator' => '>=', 'threshold' => 90, 'mode' => 'each']);
    disks(['web-1' => 95, 'web-2' => 95, 'web-3' => 95, 'web-4' => 95, 'web-5' => 95, 'web-6' => 95, 'web-7' => 95]);

    look();

    $fired = alertHistory()[0];

    expect($fired['items'])->toHaveCount(5)
        ->and($fired['more'])->toBe(2)
        ->and($fired['items'][0])->toBe(['item' => 'web-1 · /', 'value' => '95%'])
        ->and($fired['title'])->toBe('Disk: web-1 · / 95% (>= 90%)');

    // web-1 frees space, and web-2 stops reporting for over a day.
    disks(['web-1' => 10]);
    disks(['web-2' => 95], now()->getTimestamp() - 90_000);
    look();
    look();

    $recovered = alertHistory()[0];

    expect($recovered['event'])->toBe('recovered')
        ->and($recovered['items'])->toBe([['item' => 'web-1 · /', 'value' => '10%'], ['item' => 'web-2 · /', 'value' => '—']])
        ->and(app(States::class)->firing())->toHaveCount(1)
        ->and(app(States::class)->firing()[array_key_first(app(States::class)->firing())])->toHaveCount(5);
});

it('reminds while firing when the rule asks to', function () {
    alertRule(['metric' => 'servers.disk', 'operator' => '>=', 'threshold' => 90, 'remind' => 3_600]);
    disks(['web-1' => 95]);

    look();
    $this->travel(30)->minutes();
    look();
    $this->travel(31)->minutes();
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['reminder', 'fired']);
});

it('keeps state for items whose name is a number', function () {
    alertRule(['metric' => 'ai.cost', 'operator' => '>', 'threshold' => 1, 'window' => 3_600, 'mode' => 'each', 'filter' => ['by' => 'user', 'operator' => 'equals', 'values' => ['7']]]);
    Laralyze::record('ai_user_cost', '7', 2_000_000, now()->getTimestamp())->sum();
    Laralyze::flush();

    look();
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['fired'])
        ->and(alertHistory()[0]['items'])->toBe([['item' => '7', 'value' => '$2.00']]);
});

it('tells each new exception once, however many looks see it', function () {
    alertRule(['name' => 'Exceptions', 'metric' => 'exceptions.new']);
    report(new RuntimeException('Card declined'));
    Laralyze::flush();

    look();
    look();

    $history = alertHistory();

    expect($history)->toHaveCount(1)
        ->and($history[0]['title'])->toBe('Exceptions: New exception: RuntimeException')
        ->and($history[0]['text'])->toBe('Card declined')
        ->and($history[0]['url'])->toContain('/laralyze/exceptions/');
});

it('tells an exception again for a second rule that watches it', function () {
    alertRule(['name' => 'All', 'metric' => 'exceptions.new']);
    alertRule(['name' => 'Runtime', 'metric' => 'exceptions.new', 'filter' => ['by' => 'class', 'operator' => 'contains', 'values' => ['Runtime']]]);
    report(new RuntimeException('Card declined'));
    Laralyze::flush();

    look();

    expect(array_column(alertHistory(), 'rule_name'))->toEqualCanonicalizing(['All', 'Runtime']);
});

it('skips rules that are off or muted', function () {
    alertRule(['metric' => 'jobs.failed', 'threshold' => 1, 'enabled' => false]);
    alertRule(['metric' => 'jobs.failed', 'threshold' => 1, 'muted_until' => now()->getTimestamp() + 600]);
    alertRule(['metric' => 'jobs.failed', 'threshold' => 1, 'muted_until' => -1]);
    failJobs(3);

    look();

    expect(alertHistory())->toBe([]);
});

it('never counts a queue it cannot read as recovered', function () {
    config(['queue.connections.database' => ['driver' => 'database', 'table' => 'missing_jobs', 'queue' => 'default']]);
    $rule = alertRule(['name' => 'Waiting', 'metric' => 'queues.waiting', 'operator' => '>=', 'threshold' => 1, 'mode' => 'each']);
    Laralyze::record('queue_queued', 'database:default')->count();
    Laralyze::flush();

    Queue::fake();
    Queue::push('SendInvoice', [], 'default');
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['fired']);

    // The queue goes down: the real connection can't be read.
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');
    look();
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['fired'])
        ->and(app(States::class)->firing()[$rule->id]['database:default']['clean'])->toBe(0);
});

it('forgets the state of a rule that no longer exists', function () {
    app(States::class)->put('GONE', '*', ['since' => 1, 'clean' => 0, 'reminded' => 1, 'value' => 1.0]);
    alertRule(['metric' => 'jobs.failed', 'threshold' => 1]);

    look();

    expect(app(States::class)->firing())->not->toHaveKey('GONE');
});

it('checks 24-hour rules every five minutes', function () {
    $this->travelTo(Date::createFromTimestamp(intdiv(now()->getTimestamp(), 300) * 300 + 60));
    alertRule(['metric' => 'ai.cost', 'operator' => '>', 'threshold' => 1, 'window' => 86_400]);
    Laralyze::record('ai_model_cost', '["openai","gpt-4o"]', 2_000_000, now()->getTimestamp())->sum();
    Laralyze::flush();

    look();

    expect(alertHistory())->toBe([]);

    $this->travel(4)->minutes();
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['fired']);
});

it('changes nothing when storage can\'t be reached', function () {
    Http::fake();
    config(['laralyze.alerts.heartbeat' => 'https://hc.test/ping']);
    $rule = alertRule(['metric' => 'jobs.failed', 'operator' => '>=', 'threshold' => 10]);
    failJobs(12);
    look();

    $real = app(Storage::class);
    // Everything else passes through, so without the skip the look would really run on an empty window.
    $down = Mockery::mock($real)->makePartial();
    $down->shouldReceive('installed')->andThrow(new RuntimeException('Connection refused'));
    app()->instance(Storage::class, $down);

    $this->travel(10)->minutes();
    app(Alerts::class)->run();
    app(Alerts::class)->run();

    app()->instance(Storage::class, $real);

    expect(array_column(alertHistory(), 'event'))->toBe(['fired'])
        ->and(app(States::class)->firing()[$rule->id]['*']['clean'])->toBe(0);

    Http::assertNothingSent();
});

it('tells what fired on the next look when writing the history failed', function () {
    alertRule(['metric' => 'jobs.failed', 'operator' => '>=', 'threshold' => 10]);
    failJobs(12);

    $real = app(Storage::class);
    $failing = Mockery::mock($real)->makePartial();
    $failing->shouldReceive('put')->andReturnUsing(function (string $type, string $key, string $value) use ($real) {
        $type === History::TYPE ? throw new RuntimeException('Deadlock') : $real->put($type, $key, $value);
    });
    app()->instance(Storage::class, $failing);

    look();

    app()->instance(Storage::class, $real);

    expect(alertHistory())->toBe([]);

    $this->travel(1)->minutes();
    look();

    expect(array_column(alertHistory(), 'event'))->toBe(['fired']);
});

it('makes the default rules, sends what is pending, and pings the heartbeat', function () {
    Http::fake();
    config(['laralyze.alerts.heartbeat' => 'https://hc.test/ping']);
    failJobs(12);

    app(Alerts::class)->run();

    expect(app(Rules::class)->all())->toHaveCount(13)
        ->and(alertHistory()[0]['title'])->toBe('Failed jobs: 12 (>= 10)')
        ->and(alertHistory()[0]['deliveries']['slack']['status'])->toBe('sent');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.test/ping' && $request->method() === 'GET');
});

it('never lets the heartbeat stop the check', function () {
    config(['laralyze.alerts.heartbeat' => 'https://hc.test/ping']);
    Http::fake(['hc.test/*' => fn () => throw new RuntimeException('Timed out')]);

    app(Alerts::class)->run();

    expect(app(Rules::class)->all())->toHaveCount(13);
});

it('runs every minute from the scheduler on one server, even without a channel', function () {
    config(['laralyze.alerts.slack' => null]);

    $alerts = collect(app(Schedule::class)->events())->firstWhere('description', 'laralyze:alerts');

    expect($alerts)->not->toBeNull()
        ->and($alerts->expression)->toBe('* * * * *')
        ->and($alerts->onOneServer)->toBeTrue()
        ->and($alerts->withoutOverlapping)->toBeTrue();
});

it('shows the current value while a rule is being made', function () {
    failJobs(12);

    $preview = fn (array $rule) => app(Look::class)->preview(Rule::fromArray('r', $rule), now()->getTimestamp());

    expect($preview(['metric' => 'jobs.failed']))->toBe('Now: 12')
        ->and($preview(['metric' => 'jobs.failed', 'mode' => 'each']))->toBe('Highest now: 12 (App\Jobs\Import)')
        ->and($preview(['metric' => 'requests.error_rate']))->toBe('No data in this window yet.');
});
