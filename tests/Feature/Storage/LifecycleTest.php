<?php

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Metrics\Period;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function storedCount(?string $type = null): int
{
    return (int) laralyzeRows('laralyze_aggregates')
        ->where('aggregate', 'count')
        ->where('period', Period::MINUTE)
        ->when($type, fn ($rows) => $rows->where('type', $type))
        ->sum('value');
}

it('flushes after an HTTP request is sent', function () {
    Route::get('/checkout', function () {
        Laralyze::record('checkout', 'pro')->count();

        return 'ok';
    });

    $this->get('/checkout')->assertOk();

    expect(storedCount('checkout'))->toBe(1)
        ->and(Laralyze::buffer()->isEmpty())->toBeTrue();
});

it('flushes after a console command finishes', function () {
    Artisan::command('laralyze:test-command', function () {
        Laralyze::record('import', 'users')->count();
    });

    // Run it the way `php artisan` does, so terminate() fires like in production.
    $kernel = app(ConsoleKernel::class);
    $input = new ArrayInput(['command' => 'laralyze:test-command']);
    $status = $kernel->handle($input, new BufferedOutput);
    $kernel->terminate($input, $status);

    expect($status)->toBe(0)
        ->and(storedCount('import'))->toBe(1);
});

it('flushes after each queued job and on every worker loop', function (object $event) {
    Laralyze::record('invoice', 'SendInvoice')->count();

    event($event);

    expect(storedCount('invoice'))->toBe(1);
})->with([
    'processed' => fn () => new JobProcessed('redis', Mockery::mock(Job::class)),
    'failed' => fn () => new JobFailed('redis', Mockery::mock(Job::class), new RuntimeException),
    'looping' => fn () => new Looping('redis', 'default'),
]);

it('flushes after a scheduled task finishes', function () {
    Laralyze::record('task', 'reports')->count();

    event(new ScheduledTaskFinished(new ScheduledEvent(Mockery::mock(EventMutex::class), 'php artisan reports'), 1.5));

    expect(storedCount('task'))->toBe(1);
});

it('writes early when a console buffer fills up', function () {
    Laralyze::buffer()->limitTo(4);

    foreach (range(1, 5) as $i) {
        Laralyze::record('import', "row {$i}")->count();
    }

    expect(storedCount('import'))->toBeGreaterThanOrEqual(2);
});

it('removes minute data after a day and hour data after the retention period', function () {
    $now = Carbon::parse('2026-09-30 12:00:00', 'UTC');
    $this->travelTo($now);
    config(['laralyze.retention' => 7]);

    Laralyze::record('request', 'recent', timestamp: $now->getTimestamp() - 3_600)->count();
    Laralyze::record('request', 'two days', timestamp: $now->getTimestamp() - 2 * 86_400)->count();
    Laralyze::record('request', 'ten days', timestamp: $now->getTimestamp() - 10 * 86_400)->count();
    Laralyze::flush();

    Laralyze::trim();

    $kept = fn (int $period) => laralyzeRows('laralyze_aggregates')->where('type', 'request')->where('period', $period)->sortBy('key')->pluck('key')->values()->all();

    expect($kept(Period::MINUTE))->toBe(['recent'])
        ->and($kept(Period::HOUR))->toBe(['recent', 'two days']);
});

it('schedules its own cleanup', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => $event->description === 'laralyze:trim');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});

it('cleans up from a web request when the scheduler has not run for hours', function () {
    config(['laralyze.trim_lottery' => [1, 1]]);
    Laralyze::record('request', 'old', timestamp: time() - 90 * 86_400)->count();
    Laralyze::flush();

    Laralyze::record('request', 'new')->count();
    Laralyze::flush();

    expect(laralyzeRows('laralyze_aggregates')->where('key', 'old')->isNotEmpty())->toBeFalse()
        ->and(laralyzeRows('laralyze_values')->where('type', 'laralyze')->where('key', 'trimmed_at')->isNotEmpty())->toBeTrue();
});
