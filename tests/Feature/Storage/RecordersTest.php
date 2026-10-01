<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laralyze\Facades\Laralyze;
use Laralyze\Recorders;
use Laralyze\Storage\DatabaseStorage;
use Laralyze\Tests\Fixtures\InvoicePaid;
use Laralyze\Tests\Fixtures\SendInvoice;
use Laralyze\Tests\Fixtures\WelcomeMail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

/**
 * @param  list<string>  $aggregates
 * @return array<string, stdClass>
 */
function rowsOf(string $type, array $aggregates = ['count']): array
{
    return app(DatabaseStorage::class)->aggregate($type, $aggregates, 3_600)->keyBy('key')->all();
}

function countOf(string $type, string $key): float
{
    return (float) (rowsOf($type)[$key]->count ?? 0);
}

function showCard(string $name): Testable
{
    return Livewire::withoutLazyLoading()->test("laralyze.{$name}");
}

it('groups queries by their SQL, with lists folded together', function () {
    Schema::create('widgets', function ($table) {
        $table->id();
        $table->string('name');
    });

    DB::table('widgets')->whereIn('id', [1, 2, 3])->get();
    DB::table('widgets')->whereIn('id', [4, 5])->get();
    DB::table('widgets')->insert(['name' => 'Gear']);
    Laralyze::flush();

    $sql = collect(rowsOf('query'))->keys()->first(fn (string $key) => str_contains($key, 'widgets') && str_contains($key, 'in (...)'));

    expect($sql)->not->toBeNull()
        ->and(countOf('query', $sql))->toBe(2.0)
        ->and(countOf('query_kind', 'read'))->toBeGreaterThanOrEqual(2.0)
        ->and(countOf('query_kind', 'write'))->toBeGreaterThanOrEqual(1.0)
        ->and(countOf('query_connection', 'testing'))->toBeGreaterThanOrEqual(3.0);

    showCard('queries')->assertSee('in (...)');
    showCard('query-totals')->assertSee('queries');
});

it('counts every query of a long execution exactly', function () {
    // DDL isn't rolled back on every database, so this table is its own.
    Schema::dropIfExists('long_runs');
    Schema::create('long_runs', fn ($table) => $table->id());

    // Durations are buffered and added up every 1,000 queries.
    for ($i = 0; $i < 2_503; $i++) {
        DB::table('long_runs')->where('id', $i)->first();
    }

    Laralyze::flush();
    Schema::drop('long_runs');

    $row = collect(app(DatabaseStorage::class)->aggregate('query', ['count', 'sum', 'max'], 3_600))
        ->first(fn ($row) => str_contains($row->key, 'long_runs') && str_contains($row->key, 'where'));

    expect((float) $row->count)->toBe(2_503.0)
        ->and((float) $row->sum)->toBeGreaterThan(0.0)
        ->and((float) $row->max)->toBeLessThanOrEqual((float) $row->sum);
});

it('lists slow queries with the line that ran them', function () {
    $this->rebootWith(['laralyze.recorders' => [Recorders\Queries::class => ['threshold' => 0]]]);
    app()->detectEnvironment(fn () => 'local');

    Schema::create('widgets', fn ($table) => $table->id());
    DB::table('widgets')->where('id', 1)->first();
    Laralyze::flush();

    $slow = collect(rowsOf('slow_query'))->keys()->map(fn (string $key) => json_decode($key, true))
        ->first(fn (array $parts) => str_contains($parts[0], 'widgets'));

    expect($slow[1])->toContain('RecordersTest.php:');

    showCard('slow-queries')->assertSee('widgets')->assertSee('RecordersTest.php');
});

it('counts cache hits, misses, writes and deletes per key group', function () {
    Cache::put('user:1', 'Sara');
    Cache::get('user:1');
    Cache::get('user:2');
    Cache::forget('user:1');
    Cache::get('illuminate:framework-key');
    Laralyze::flush();

    expect(countOf('cache_hit', 'user:*'))->toBe(1.0)
        ->and(countOf('cache_miss', 'user:*'))->toBe(1.0)
        ->and(countOf('cache_write', 'user:*'))->toBe(1.0)
        ->and(countOf('cache_delete', 'user:*'))->toBe(1.0)
        ->and(rowsOf('cache_miss'))->not->toHaveKey('illuminate:framework-key');

    showCard('cache')->assertSee('50%');
    showCard('cache-keys')->assertSee('user:*');
});

it('counts exceptions by class and line, handled and unhandled', function () {
    Route::get('/boom', fn () => throw new LogicException('Unhandled boom'));

    report(new RuntimeException('Handled boom'));
    $this->get('/boom')->assertServerError();
    Laralyze::flush();

    $keys = collect(rowsOf('exception'))->keys()->map(fn (string $key) => json_decode($key, true));

    expect($keys->pluck(0)->all())->toContain(RuntimeException::class, LogicException::class)
        ->and($keys->first(fn ($key) => $key[0] === RuntimeException::class)[1])->toContain('RecordersTest.php:')
        ->and((float) app(DatabaseStorage::class)->total('exception_handled', ['count'], 3_600)->count)->toBe(1.0)
        ->and((float) app(DatabaseStorage::class)->total('exception_unhandled', ['count'], 3_600)->count)->toBe(1.0);

    showCard('exceptions')->assertSee('RuntimeException')->assertSee('Handled boom')->assertSee('Unhandled boom');
});

it('follows jobs per queue and per class', function () {
    event(new JobQueued('redis', 'emails', '1', new SendInvoice, '{}', null));

    dispatch(new SendInvoice);

    try {
        dispatch(new SendInvoice(fail: true));
    } catch (RuntimeException) {
        // The sync queue rethrows.
    }

    Laralyze::flush();

    expect(countOf('queue_queued', 'redis:emails'))->toBe(1.0)
        ->and(countOf('queue_processed', 'sync:sync'))->toBe(1.0)
        ->and(countOf('queue_failed', 'sync:sync'))->toBe(1.0)
        ->and(countOf('job', SendInvoice::class))->toBe(2.0)
        ->and(countOf('job_failed', SendInvoice::class))->toBe(1.0);

    showCard('queues')->assertSee('sync:sync')->assertSee('redis:emails');
    showCard('jobs')->assertSee('SendInvoice');
});

it('records scheduled task runs, failures and skips with the next run', function () {
    $task = app(Schedule::class)->command('inspire')->everyFiveMinutes();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.25));
    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException('Quote service down')));
    event(new ScheduledTaskSkipped($task));
    Laralyze::flush();

    $latest = json_decode((string) app(DatabaseStorage::class)->values('scheduled_task', ['inspire'])->first()?->value, true);

    expect(countOf('scheduled', 'inspire'))->toBe(2.0)
        ->and(countOf('scheduled_failed', 'inspire'))->toBe(1.0)
        ->and(countOf('scheduled_skipped', 'inspire'))->toBe(1.0)
        ->and($latest['status'])->toBe('skipped')
        ->and($latest['expression'])->toBe('*/5 * * * *')
        ->and($latest['next_at'])->toBeGreaterThan(time());

    showCard('scheduled-tasks')->assertSee('inspire')->assertSee('*/5 * * * *');
});

it('records commands and their failures', function () {
    Artisan::command('orders:sync', fn () => 0);
    Artisan::command('orders:prune', fn () => 1);

    // Laravel only fires command events in tests when asked to (see WithConsoleEvents).
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();

    Artisan::call('orders:sync');
    Artisan::call('orders:prune');
    Laralyze::flush();

    expect(countOf('command', 'orders:sync'))->toBe(1.0)
        ->and(countOf('command_failed', 'orders:prune'))->toBe(1.0)
        ->and(rowsOf('command_failed'))->not->toHaveKey('orders:sync');

    showCard('commands')->assertSee('orders:sync');
});

it('records outgoing requests, errors and connections that failed', function () {
    Http::fake([
        'api.example.com/*' => Http::response('ok'),
        'broken.example.com/*' => Http::response('nope', 503),
        'down.example.com/*' => Http::failedConnection(),
    ]);

    Http::get('https://api.example.com/users/42');
    Http::get('https://api.example.com/users/7');
    Http::get('https://broken.example.com/status');

    try {
        Http::get('https://down.example.com/ping');
    } catch (ConnectionException) {
        //
    }

    Laralyze::flush();

    expect(countOf('http_2xx', 'GET api.example.com/users/*'))->toBe(2.0)
        ->and(countOf('http_5xx', 'GET broken.example.com/status'))->toBe(1.0)
        ->and(countOf('http_failed', 'GET down.example.com/ping'))->toBe(1.0);

    showCard('outgoing-requests')->assertSee('api.example.com/users/*')->assertSee('down.example.com/ping');
});

it('records mail sent and mail that never finished sending', function () {
    config(['mail.default' => 'array']);

    Mail::to('sara@example.com')->send(new WelcomeMail);
    event(new MessageSending(new Email, ['__laravel_mailable' => 'App\Mail\Receipt']));
    Laralyze::flush();

    expect(countOf('mail', WelcomeMail::class))->toBe(1.0)
        ->and(countOf('mail_failed', 'App\Mail\Receipt'))->toBe(1.0)
        ->and(rowsOf('mail_failed'))->not->toHaveKey(WelcomeMail::class);

    showCard('mail')->assertSee('WelcomeMail')->assertSee('Receipt');
});

it('records notifications per channel and their failures', function () {
    config(['mail.default' => 'array']);

    $notifiable = Notification::route('mail', 'sara@example.com');
    $notifiable->notify(new InvoicePaid);
    event(new NotificationFailed($notifiable, new InvoicePaid, 'slack'));
    Laralyze::flush();

    expect(countOf('notification', json_encode([InvoicePaid::class, 'mail'])))->toBe(1.0)
        ->and(countOf('notification_failed', json_encode([InvoicePaid::class, 'slack'])))->toBe(1.0);

    showCard('notifications')->assertSee('InvoicePaid')->assertSee('slack');
});

it('counts log messages per level', function () {
    Log::warning('Disk is getting full');
    Log::error('Payment failed');
    Log::error('Payment failed again');
    Laralyze::flush();

    expect(countOf('log', 'error'))->toBe(2.0)
        ->and(countOf('log', 'warning'))->toBe(1.0);

    showCard('logs')->assertSee('error')->assertSee('warning');
});

it('ranks signed-in users and shows them by name', function () {
    Route::get('/orders', fn () => 'ok');

    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Sara', 'email' => 'sara@example.com']));
    $this->get('/orders')->assertOk();
    $this->get('/orders')->assertOk();

    expect(countOf('user_request', '7'))->toBe(2.0);

    showCard('users')->assertSee('Sara')->assertSee('sara@example.com');
});

it('lets the app decide how users are shown', function () {
    Laralyze::user(fn ($user) => ['name' => 'Team '.$user->team, 'extra' => 'Plan: pro']);
    Route::get('/orders', fn () => 'ok');

    $this->actingAs(new GenericUser(['id' => 8, 'team' => 'Blue']));
    $this->get('/orders')->assertOk();

    showCard('users')->assertSee('Team Blue')->assertSee('Plan: pro');
});

it('reports this server\'s memory and disks', function () {
    app(Recorders\Servers::class, ['config' => ['server_name' => 'web-1', 'directories' => [sys_get_temp_dir()]]])->snapshot();
    Laralyze::flush();

    $server = json_decode((string) app(DatabaseStorage::class)->values('server', ['web-1'])->first()?->value, true);

    expect($server['memory_total'])->toBeGreaterThan(0)
        ->and($server['disks'][0]['total'])->toBeGreaterThan(0);

    showCard('servers')->assertSee('web-1');
})->skip(fn () => ! in_array(PHP_OS_FAMILY, ['Linux', 'Darwin', 'Windows'], true), 'Unsupported OS');

it('adds the server snapshot to the schedule', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => $event->description === 'laralyze:servers');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

it('registers no listeners for recorders that are turned off', function () {
    $listeners = fn () => collect([
        QueryExecuted::class,
        CacheHit::class,
        JobProcessed::class,
        MessageLogged::class,
        ResponseReceived::class,
        MessageSent::class,
        NotificationSent::class,
        CommandFinished::class,
    ])->map(fn (string $event) => count(app('events')->getListeners($event)))->all();

    $this->rebootWith(['laralyze.recorders' => []]);
    $baseline = $listeners();

    $defaults = (require __DIR__.'/../../../config/laralyze.php')['recorders'];

    $this->rebootWith(['laralyze.recorders' => array_map(fn () => ['enabled' => false], $defaults)]);
    expect($listeners())->toBe($baseline);

    // The same count sees the listeners once the recorders are on.
    $this->rebootWith(['laralyze.recorders' => $defaults]);
    expect(array_map(fn (int $count, int $base) => $count > $base, $listeners(), $baseline))->not->toContain(false);
});

it('ignores other events whose names start like a recorded one', function () {
    $reported = [];
    Laralyze::handleExceptionsUsing(function (Throwable $e) use (&$reported) {
        $reported[] = $e;
    });

    event(QueryExecuted::class.'Later', [new stdClass]);
    DB::select('select 1');

    expect($reported)->toBe([]);
});

it('keeps query values out of stored exception messages', function () {
    Schema::create('customers', fn ($table) => $table->string('email')->unique());
    DB::table('customers')->insert(['email' => 'sara@example.com']);

    try {
        DB::table('customers')->insert(['email' => 'sara@example.com']);
    } catch (QueryException $e) {
        report($e);
    }

    Laralyze::flush();

    $message = (string) app(DatabaseStorage::class)->values('exception_message')->first()?->value;

    expect($message)->not->toContain('sara@example.com')
        ->toStartWith('SQLSTATE[23')
        ->toContain('Integrity constraint violation (insert into')
        ->and(json_encode(rowsOf('exception')))->toContain('UniqueConstraintViolationException');
});
