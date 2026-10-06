<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Recorders;
use MohammedMojaly\Laralyze\Tests\Fixtures\SendInvoice;
use Symfony\Component\Console\Input\ArgvInput;
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
    return app(Storage::class)->executions($filters, 3_600, 'recent');
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

    $execution = app(Storage::class)->execution(kept()->sole()->uuid);

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
        ->and(kept()->firstWhere('name', 'GET /broken')->failed)->toBeTrue()
        ->and(kept()->pluck('meta.kept', 'name')->sortKeys()->all())->toBe(['GET /broken' => 'failed', 'GET /slow' => 'slow', 'GET /throws' => 'exception']);

    $this->get('/laralyze/executions/'.kept()->firstWhere('name', 'GET /slow')->uuid)->assertSee('Kept')->assertSee('It ran longer than its slow threshold.');
});

it('keeps a filtered route\'s timelines out along with its metrics', function () {
    test()->rebootWith(['laralyze.recorders' => [
        Recorders\Traces::class => ['sample_rate' => 1, 'threshold' => 60_000, 'enabled' => true],
        Recorders\Requests::class => ['enabled' => true],
    ]]);
    app()->detectEnvironment(fn () => 'local');
    Laralyze::filter(fn (string $type, string $key) => $key !== 'GET /patients/{patient}');

    Route::get('/patients/{patient}', fn () => 'ok');
    Route::get('/fine', fn () => 'ok');
    $this->get('/patients/7');
    $this->get('/fine');
    Laralyze::flush();

    expect(kept()->pluck('name')->all())->toBe(['GET /fine'])
        ->and(app(Storage::class)->aggregate('request', ['count'], 3_600)->pluck('key')->all())->toBe(['GET /fine']);
});

it('tells under the list that the rest are sampled, and at what rate', function () {
    traceWith(['sample_rate' => 0.1, 'threshold' => 0]);

    Route::get('/fine', fn () => 'ok');
    $this->get('/fine');
    Laralyze::flush();

    Livewire::withoutLazyLoading()->test('laralyze.executions')
        ->assertSee('the rest are sampled at 0.1')
        ->assertSee('The numbers on the other pages count every one.');

    traceWith(['sample_rate' => 1, 'threshold' => 0]);

    Livewire::withoutLazyLoading()->test('laralyze.executions')->assertDontSee('the rest are sampled');
});

it('says when one was kept by the sample, and at what rate', function () {
    traceWith(['sample_rate' => 1, 'threshold' => 60_000]);

    Route::get('/fine', fn () => 'ok');
    $this->get('/fine');
    Laralyze::flush();

    $execution = kept()->first();

    expect($execution->meta['kept'])->toBe('sampled')
        ->and($execution->meta['sample_rate'])->toEqual(1);

    $this->get('/laralyze/executions/'.$execution->uuid)->assertSee('Picked by the sample (rate 1).');
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
    $exception = (string) app(Storage::class)->aggregate('exception', ['count'], 3_600)->first()->key;
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

    $kept = laralyzeRows('laralyze_executions')->first();
    app(Storage::class)->store([], [], [[
        'uuid' => (string) Str::ulid(now()->subDays(8)), 'trace' => (string) Str::ulid(), 'type' => 'request', 'name' => 'GET /old',
        'status' => '200', 'failed' => false, 'duration' => 1.0, 'user_id' => null, 'server' => 'web', 'started_at' => time() - 8 * 86_400,
        'exceptions' => [], 'counts' => [], 'meta' => [], 'events' => [],
    ]]);

    Laralyze::trim();

    expect(laralyzeRows('laralyze_executions')->pluck('uuid')->all())->toBe([$kept->uuid]);
});

it('puts a failed job\'s exception in its timeline, though Laravel reports it after the failure', function () {
    traceWith(['sample_rate' => 0]);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\RestockShelves');
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('uuid')->andReturn('5b6a2d1e-0000-4000-8000-000000000001');
    $failure = new RuntimeException('Warehouse API timed out');

    // A worker loops, reserves the job, then runs it.
    event(new Looping('redis', 'default'));
    event(new JobProcessing('redis', $job));
    DB::select('select 1');
    event(new JobFailed('redis', $job, $failure));
    report($failure);
    Laralyze::flush();

    $execution = app(Storage::class)->execution(kept()->sole()->uuid);

    expect($execution->status)->toBe('failed')
        ->and(array_column($execution->events, 0))->toBe(['query', 'exception'])
        ->and($execution->counts['exception'])->toBe(1);

    $source = json_decode((string) app(Storage::class)->values('exception_details')->first()->value, true)['source'];

    expect($source)->toBe(['type' => 'job', 'name' => 'App\Jobs\RestockShelves']);
});

it('splits a request into stages and adds up time per kind', function () {
    traceWith(['sample_rate' => 1]);

    Route::get('/books', function () {
        DB::select('select 1');
        DB::select('select 2');

        return 'ok';
    });

    $this->get('/books');
    Laralyze::flush();

    $execution = app(Storage::class)->execution(kept()->sole()->uuid);

    expect(array_column($execution->meta['stages'], 0))->toBe(['middleware', 'handle', 'terminating'])
        ->and($execution->meta['ms']['query'])->toBeGreaterThan(0)
        ->and(collect($execution->meta['stages'])->every(fn (array $stage) => $stage[2] >= $stage[1]))->toBeTrue();

    $this->get('/laralyze/executions/'.$execution->uuid)->assertSee('handle')->assertSee('2 events');
});

it('keeps the command line, with secrets hidden', function () {
    traceWith(['sample_rate' => 1]);

    $input = new ArgvInput(['artisan', 'books:import', '--limit=5', '--api-key=abc123', '--password', 'secret']);
    $output = new BufferedOutput;

    event(new CommandStarting('books:import', $input, $output));
    event(new CommandFinished('books:import', $input, $output, 1));
    Laralyze::flush();

    $execution = kept()->sole();

    expect($execution->meta['line'])->toStartWith('books:import --limit=5')
        ->not->toContain('abc123')
        ->not->toContain('secret')
        ->and($execution->failed)->toBeTrue()
        ->and(array_column($execution->meta['stages'], 0))->toBe(['handle']);
});

it('links the attempts of a job, with its connection, queue and the worker reserving it', function () {
    traceWith(['sample_rate' => 1]);

    $job = fn (int $attempt) => tap(Mockery::mock(Job::class), function ($job) use ($attempt) {
        $job->shouldReceive('resolveName')->andReturn('App\Jobs\RestockShelves');
        $job->shouldReceive('payload')->andReturn(['createdAt' => time() - 30]);
        $job->shouldReceive('getQueue')->andReturn('restock');
        $job->shouldReceive('attempts')->andReturn($attempt);
        $job->shouldReceive('uuid')->andReturn('5b6a2d1e-0000-4000-8000-000000000002');
    });

    event(new Looping('database', 'restock'));
    DB::select('select 1 as reserve');
    event(new JobProcessing('database', $first = $job(1)));
    event(new JobReleasedAfterException('database', $first));
    report(new RuntimeException('Warehouse API timed out'));

    event(new Looping('database', 'restock'));
    event(new JobProcessing('database', $second = $job(2)));
    event(new JobProcessed('database', $second));
    Laralyze::flush();

    $attempts = app(Storage::class)->attempts('5b6a2d1e-0000-4000-8000-000000000002');

    expect($attempts->pluck('status')->all())->toBe(['released', 'processed'])
        ->and($attempts[0]->meta)->toMatchArray(['connection' => 'database', 'queue' => 'restock', 'attempt' => 1, 'error' => 'Warehouse API timed out'])
        ->and(app(Storage::class)->execution($attempts[0]->uuid)->events[0][3])->toBe('select 1 as reserve');

    $this->get('/laralyze/executions/'.$attempts[1]->uuid)->assertSee('Attempt 2 of 2')->assertSee('restock');
    $this->get('/laralyze/executions/'.$attempts[0]->uuid)->assertSee('Stack trace and code')->assertSee('Warehouse API timed out');

    Livewire::withoutLazyLoading()->test('laralyze.executions', ['type' => 'job', 'name' => 'App\Jobs\RestockShelves'])
        ->assertSee('restock')
        ->set('status', 'failed')
        ->assertSee('Warehouse API timed out')
        ->assertDontSee('processed')
        ->set('status', 'ok')
        ->assertSee('processed');
});

it('pages through runs and filters them by speed', function () {
    traceWith(['sample_rate' => 1]);
    Route::get('/fine', fn () => 'ok');

    foreach (range(1, 4) as $i) {
        $this->get('/fine');
    }

    Laralyze::record('request', 'GET /fine', 0.001)->avg()->max()->histogram();
    Laralyze::flush();

    Livewire::withoutLazyLoading()->test('laralyze.executions', ['type' => 'request', 'name' => 'GET /fine', 'limit' => 3])
        ->assertSee('Page 1')
        ->call('nextPage')
        ->assertSee('Page 2')
        ->set('speed', 'p95')
        ->assertSet('page', 1);
});
