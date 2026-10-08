<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Health;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Recorders;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function logsWith(array $logs = [], array $traces = ['sample_rate' => 1]): void
{
    test()->rebootWith(['laralyze.recorders' => [
        Recorders\Logs::class => ['enabled' => true, ...$logs],
        Recorders\Traces::class => ['enabled' => true, ...$traces],
        Recorders\Exceptions::class => ['enabled' => true],
    ]]);

    app()->detectEnvironment(fn () => 'local');
}

/**
 * @param  array{levels?: list<string>, search?: string, user?: string}  $filters
 * @return Collection<int, stdClass>
 */
function storedLogs(array $filters = []): Collection
{
    return app(Storage::class)->logs($filters, 3_600);
}

it('keeps info and above with their message and context, and only counts debug', function () {
    logsWith();

    Log::debug('Cache warmed');
    Log::info('Order placed', ['order' => 42, 'items' => ['book', 'pen']]);
    Log::error('Payment failed', ['gateway' => 'stripe']);
    Laralyze::flush();

    $logs = storedLogs();

    expect($logs->pluck('message')->all())->toBe(['Payment failed', 'Order placed'])
        ->and($logs->last()->level)->toBe('info')
        ->and($logs->last()->context)->toBe(['order' => 42, 'items' => ['book', 'pen']])
        ->and($logs->first()->server)->not->toBe('')
        ->and(app(Storage::class)->total('log', ['count'], 3_600, 'debug')->count)->toEqual(1);
});

it('keeps debug too when the level says so', function () {
    logsWith(['level' => 'debug']);

    Log::debug('Cache warmed');
    Laralyze::flush();

    expect(storedLogs()->sole()->level)->toBe('debug');
});

it('hides secrets in the context and leaves out what is too big', function () {
    logsWith();

    Log::warning('Login failed', ['email' => 'sara@example.com', 'password' => 'hunter2', 'headers' => ['Authorization' => 'Bearer x']]);
    Log::warning('Huge', ['dump' => array_fill(0, 2_000, 'aaaaaaaaaa')]);
    Laralyze::flush();

    [$huge, $login] = storedLogs()->sortBy('message')->values()->all();

    expect($login->context)->toBe(['email' => 'sara@example.com', 'password' => '***', 'headers' => ['Authorization' => '***']])
        ->and($huge->context)->toBeNull();
});

it('links a log to the request it was written in, with its user', function () {
    logsWith();

    Route::get('/orders/{order}', function () {
        Log::info('Showing an order');

        return 'ok';
    });

    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Sara']));
    $this->get('/orders/5')->assertOk();
    Laralyze::flush();

    $log = storedLogs()->sole();
    $request = app(Storage::class)->executions(['type' => 'request'], 3_600)->sole();

    expect($log->type)->toBe('request')
        ->and($log->name)->toBe('GET /orders/{order}')
        ->and($log->execution)->toBe($request->uuid)
        ->and($log->user_id)->toBe('7');
});

it('links a log written in a worker to its job, failed ones too', function () {
    logsWith();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\RestockShelves');
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('uuid')->andReturn('5b6a2d1e-0000-4000-8000-000000000002');

    event(new Looping('redis', 'default'));
    event(new JobProcessing('redis', $job));
    Log::info('Restocking');
    event(new JobProcessed('redis', $job));

    event(new JobProcessing('redis', $job));
    $failure = new RuntimeException('Warehouse API timed out');
    event(new JobFailed('redis', $job, $failure));
    report($failure);
    Laralyze::flush();

    $jobs = app(Storage::class)->executions(['type' => 'job'], 3_600)->pluck('uuid')->all();
    $logs = storedLogs();

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('type')->unique()->all())->toBe(['job'])
        ->and($logs->pluck('name')->unique()->all())->toBe(['App\Jobs\RestockShelves'])
        ->and($logs->pluck('execution')->sort()->values()->all())->toBe(collect($jobs)->sort()->values()->all());
});

it('links a logged exception to its page instead of keeping its trace', function () {
    logsWith();

    Route::get('/report', fn () => DB::select('select * from missing_table where email = ?', ['sara@example.com']));

    $this->get('/report');
    Laralyze::flush();

    $log = storedLogs()->sole();
    $exception = (string) app(Storage::class)->aggregate('exception', ['count'], 3_600)->sole()->key;

    expect($log->level)->toBe('error')
        ->and($log->exception)->toBe(hash('xxh128', $exception))
        ->and($log->message)->not->toContain('sara@example.com')
        ->and($log->context)->toBeNull();
});

it('keeps at most 200 per request, and counts them all', function () {
    logsWith();

    Route::get('/import', function () {
        foreach (range(1, 450) as $i) {
            Log::info("Row {$i} imported");
        }

        return 'ok';
    });

    asWebRequest(fn () => $this->get('/import'));
    Laralyze::flush();

    expect(app(Storage::class)->logs([], 3_600, 500))->toHaveCount(200)
        ->and(app(Storage::class)->total('log', ['count'], 3_600, 'info')->count)->toEqual(450);
});

it('writes early instead of dropping when a command logs a lot', function () {
    logsWith();

    foreach (range(1, 450) as $i) {
        Log::info("Row {$i} imported");
    }

    Laralyze::flush();

    expect(app(Storage::class)->logs([], 3_600, 500))->toHaveCount(450);
});

it('leaves out the logs of a filtered route, and ignored levels', function () {
    logsWith(['ignore' => ['/^notice$/']]);

    Laralyze::filter(fn (string $type, string $key) => ! str_contains($key, '/health'));
    Route::get('/health', function () {
        Log::info('Health checked');

        return 'ok';
    });

    $this->get('/health');
    Log::notice('Nobody cares');
    Log::warning('Somebody cares');
    Laralyze::flush();

    expect(storedLogs()->pluck('message')->all())->toBe(['Somebody cares']);
});

it('finds logs by level, text and user, newest first and a page at a time', function () {
    logsWith();

    Log::error('Payment failed for order 1');
    Log::warning('Stock is low');
    $this->actingAs(new GenericUser(['id' => 9]));
    Log::info('Payment received for order 2');
    Laralyze::flush();

    expect(storedLogs(['levels' => ['error', 'warning']])->pluck('message')->all())->toBe(['Stock is low', 'Payment failed for order 1'])
        ->and(storedLogs(['search' => 'PAYMENT'])->pluck('level')->all())->toBe(['info', 'error'])
        ->and(storedLogs(['user' => '9'])->pluck('message')->all())->toBe(['Payment received for order 2'])
        ->and(app(Storage::class)->logs([], 3_600, 2, 2)->pluck('message')->all())->toBe(['Payment failed for order 1'])
        ->and(app(Storage::class)->logUsers(3_600))->toBe(['9']);
});

it('forgets logs after the retention period', function () {
    logsWith();

    Date::setTestNow(now()->subDays(31));
    Log::info('Long ago');
    Laralyze::flush();
    Date::setTestNow();

    Log::info('Today');
    Laralyze::flush();

    app(Storage::class)->trim(30);

    expect(app(Storage::class)->logs([], 40 * 86_400)->pluck('message')->all())->toBe(['Today']);
});

it('keeps recording when an upgraded app has no logs table yet, and says so', function () {
    if (usingClickHouse()) {
        $this->markTestSkipped('ClickHouse creates the table itself.');
    }

    logsWith();
    $migration = require __DIR__.'/../../../database/migrations/2026_10_08_000000_create_laralyze_logs_table.php';
    $migration->down();

    try {
        Log::info('Order placed');
        Laralyze::record('checkout', 'pro')->count();
        Laralyze::flush();

        expect(app(Storage::class)->total('checkout', ['count'], 3_600)->count)->toEqual(1)
            ->and(collect(app(Health::class)->problems())->pluck('title')->all())->toContain('Logs aren\'t stored yet.');
    } finally {
        // MySQL and PostgreSQL keep the schema between tests.
        $migration->up();
    }
});

it('lists logs on the logs page, filtered and expanded in place', function () {
    logsWith(traces: ['sample_rate' => 0]);

    Route::get('/kept', function () {
        Log::warning('Slow supplier', ['supplier' => 'acme']);
        abort(500);
    });
    Route::get('/fine', function () {
        Log::info('All good');

        return 'ok';
    });

    $this->get('/kept');
    $this->get('/fine');
    Laralyze::flush();

    $kept = app(Storage::class)->executions([], 3_600)->sole();
    $card = Livewire::withoutLazyLoading()->test('laralyze.log-list');

    $card->assertSee('Slow supplier')->assertSee('All good')
        ->set('levels', ['warning'])->assertSee('Slow supplier')->assertDontSee('All good')
        ->set('levels', [])->set('search', 'good')->assertSee('All good')->assertDontSee('Slow supplier')
        ->set('search', '');

    $warning = storedLogs(['levels' => ['warning']])->sole();
    $fine = storedLogs(['levels' => ['info']])->sole();

    // Only a run that was kept can be opened.
    $card->call('toggle', $warning->uuid)
        ->assertSee('"supplier": "acme"')
        ->assertSee(route('laralyze.execution', ['execution' => $kept->uuid]), false)
        ->call('toggle', $warning->uuid)
        ->call('toggle', $fine->uuid)
        ->assertSee('GET /fine')
        ->assertDontSee('/laralyze/executions/'.$fine->execution, false);

    $this->get('/laralyze/logs')->assertOk()->assertSee('wire:name="laralyze.log-list"', false);
});
