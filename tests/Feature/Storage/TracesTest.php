<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Recorders;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;
use MohammedMojaly\Laralyze\Tests\Fixtures\SendInvoice;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function traceWith(array $options): void
{
    test()->rebootWith(['laralyze.recorders' => [
        Recorders\Traces::class => [...$options, 'enabled' => true],
        Recorders\Exceptions::class => ['enabled' => true],
    ]]);

    app()->detectEnvironment(fn () => 'local');
}

/**
 * @return Collection<int, stdClass>
 */
function kept(array $filters = []): Collection
{
    return app(DatabaseStorage::class)->executions($filters, 3_600, 'recent');
}

it('keeps a request with everything that happened inside it, in order', function () {
    traceWith(['sample_rate' => 1]);

    Route::get('/books/{book}', function () {
        DB::select('select 1');
        Cache::get('book:7');
        Log::warning('Low stock');
        report(new RuntimeException('Card declined'));

        return 'ok';
    });

    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Sara']));
    $this->get('/books/7')->assertOk();
    Laralyze::flush();

    $execution = app(DatabaseStorage::class)->execution(kept()->sole()->uuid);

    expect($execution->name)->toBe('GET /books/{book}')
        ->and($execution->type)->toBe('request')
        ->and($execution->status)->toBe('200')
        ->and($execution->failed)->toBeFalse()
        ->and($execution->user_id)->toBe('7')
        ->and(array_column($execution->events, 0))->toBe(['query', 'cache', 'log', 'exception'])
        ->and($execution->events[0][3])->toBe('select 1')
        ->and($execution->events[1][4])->toBe('miss')
        ->and($execution->events[3][3])->toBe(RuntimeException::class)
        ->and($execution->counts['query'])->toBe(1);

    $this->get('/laralyze/executions/'.$execution->uuid)
        ->assertOk()
        ->assertSee('GET /books/{book}')
        ->assertSee('Timeline')
        ->assertSee('Low stock')
        ->assertSee('Card declined')
        ->assertSee('/laralyze/exceptions/'.$execution->events[3][5]);
});

it('always keeps failed, throwing and slow ones, and samples the rest', function () {
    traceWith(['sample_rate' => 0, 'threshold' => ['#^GET /slow#' => 0, 'default' => 60_000]]);

    Route::get('/fine', fn () => 'ok');
    Route::get('/broken', fn () => abort(500));
    Route::get('/throws', function () {
        report(new RuntimeException('Handled'));

        return 'ok';
    });
    Route::get('/slow', fn () => 'ok');

    foreach (['/fine', '/broken', '/throws', '/slow'] as $path) {
        $this->get($path);
    }

    Laralyze::flush();

    expect(kept()->pluck('name')->sort()->values()->all())->toBe(['GET /broken', 'GET /slow', 'GET /throws'])
        ->and(kept()->firstWhere('name', 'GET /broken')->failed)->toBeTrue();
});

it('links a job to the request that queued it', function () {
    traceWith(['sample_rate' => 1]);

    Route::get('/checkout', function () {
        dispatch(new SendInvoice);

        return 'ok';
    });

    $this->get('/checkout');
    Laralyze::flush();

    $request = kept(['type' => 'request'])->sole();
    $job = kept(['type' => 'job'])->sole();

    expect($job->name)->toBe(SendInvoice::class)
        ->and($job->status)->toBe('processed')
        ->and($job->trace)->toBe($request->uuid);

    $this->get('/laralyze/executions/'.$request->uuid)->assertSee('Jobs it queued')->assertSee($job->uuid);
    $this->get('/laralyze/executions/'.$job->uuid)->assertSee('Queued by')->assertSee('GET /checkout');
});

it('keeps commands, but not workers that run for hours', function () {
    traceWith(['sample_rate' => 1]);

    foreach (['books:import', 'queue:work'] as $command) {
        $input = new ArrayInput([]);
        $output = new BufferedOutput;

        event(new CommandStarting($command, $input, $output));
        DB::select('select 1');
        event(new CommandFinished($command, $input, $output, 0));
    }

    Laralyze::flush();

    expect(kept()->sole()->name)->toBe('books:import')
        ->and(kept()->sole()->status)->toBe('0');
});

it('lists them on the route, user and exception pages', function () {
    traceWith(['sample_rate' => 1]);

    Route::get('/books/{book}', function () {
        report(new RuntimeException('Card declined'));

        return 'ok';
    });

    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Sara']));
    $this->get('/books/7');
    Laralyze::flush();

    $uuid = kept()->sole()->uuid;
    $exception = (string) app(DatabaseStorage::class)->aggregate('exception', ['count'], 3_600)->first()->key;
    $card = fn (array $props) => Livewire::withoutLazyLoading()->test('laralyze.executions', $props);

    $card(['type' => 'request', 'name' => 'GET /books/{book}'])->assertSee($uuid)->call('$set', 'order', 'recent')->assertSee($uuid);
    $card(['type' => 'request', 'name' => 'GET /other'])->assertDontSee($uuid)->assertSee('Nothing kept');
    $card(['user' => '7'])->assertSee($uuid);
    $card(['exception' => $exception])->assertSee($uuid);
});

it('forgets single executions after a week', function () {
    traceWith(['sample_rate' => 1]);

    Route::get('/fine', fn () => 'ok');
    $this->get('/fine');
    Laralyze::flush();

    DB::table('laralyze_executions')->update(['started_at' => time() - 8 * 86_400]);
    Laralyze::trim();

    expect(DB::table('laralyze_executions')->count())->toBe(0);
});

it('puts a failed job\'s exception in its timeline, though Laravel reports it after the failure', function () {
    traceWith(['sample_rate' => 0]);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\RestockShelves');
    $job->shouldReceive('payload')->andReturn([]);
    $failure = new RuntimeException('Warehouse API timed out');

    event(new JobProcessing('redis', $job));
    DB::select('select 1');
    event(new JobFailed('redis', $job, $failure));
    report($failure);
    Laralyze::flush();

    $execution = app(DatabaseStorage::class)->execution(kept()->sole()->uuid);

    expect($execution->status)->toBe('failed')
        ->and(array_column($execution->events, 0))->toBe(['query', 'exception'])
        ->and($execution->counts['exception'])->toBe(1);

    $source = json_decode((string) app(DatabaseStorage::class)->values('exception_details')->first()->value, true)['source'];

    expect($source)->toBe(['type' => 'job', 'name' => 'App\Jobs\RestockShelves']);
});
