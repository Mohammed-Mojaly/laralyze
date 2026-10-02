<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function emptyShelf(): never
{
    throw new LogicException('The shelf is empty');
}

/**
 * @return array<string, mixed>
 */
function latestDetails(): array
{
    return json_decode((string) app(DatabaseStorage::class)->values('exception_details')->first()?->value, true);
}

function onlyExceptionKey(): string
{
    return (string) app(DatabaseStorage::class)->aggregate('exception', ['count'], 3_600)->first()->key;
}

/**
 * Tests run in the console; let the recorder see a web request.
 */
function servedOverHttp(callable $callback): void
{
    (fn () => $this->isRunningInConsole = false)->call(app());

    try {
        $callback();
    } finally {
        (fn () => $this->isRunningInConsole = null)->call(app());
    }
}

it('keeps the latest stack trace with the code around your lines', function () {
    Route::get('/shelves/{shelf}', fn () => emptyShelf());

    servedOverHttp(fn () => $this->get('/shelves/3')->assertServerError());
    Laralyze::flush();

    $details = latestDetails();
    $first = $details['frames'][0];

    expect($first['call'])->toBe('emptyShelf()')
        ->and($first['file'])->toEndWith('ExceptionPageTest.php')
        ->and($first['app'])->toBeTrue()
        ->and(implode("\n", $first['code']['lines']))->toContain("throw new LogicException('The shelf is empty');")
        ->and($details['frames'][1]['call'])->toEndWith('{closure}()')
        ->and(collect($details['frames'])->where('app', false))->not->toBeEmpty()
        ->and($details['source'])->toBe(['type' => 'request', 'name' => 'GET /shelves/{shelf}'])
        ->and($details['handled'])->toBeFalse()
        ->and($details['php'])->toBe(PHP_VERSION)
        ->and($details['laravel'])->toBe(app()->version());
});

it('opens a page for each exception, titled with its message', function () {
    Route::get('/shelves', fn () => emptyShelf());
    $this->get('/shelves')->assertServerError();
    Laralyze::flush();

    $key = onlyExceptionKey();
    $path = '/laralyze/exceptions/'.hash('xxh128', $key);

    Livewire::withoutLazyLoading()->test('laralyze.exceptions')->assertSee($path);

    $this->get($path)->assertOk()->assertSee('The shelf is empty')->assertSee('Exceptions');

    Livewire::withoutLazyLoading()->test('laralyze.exception', ['name' => $key])
        ->assertSee('LogicException')
        ->assertSee('Unhandled')
        ->assertSee('emptyShelf()')
        ->assertSee("throw new LogicException('The shelf is empty');")
        ->assertSeeInOrder(['Occurrences', '24 hours', '1', '7 days', '1'])
        ->assertSee('vendor frames')
        ->assertSee('Copy as Markdown')
        ->assertSee('### Stack trace');
});

it('counts the users who ran into it', function () {
    Route::get('/shelves', fn () => emptyShelf());

    foreach ([7, 7, 9] as $id) {
        $this->actingAs(new GenericUser(['id' => $id, 'name' => "User {$id}"]));
        $this->get('/shelves');
    }

    Laralyze::flush();

    expect(latestDetails()['user'])->toBe('9');

    Livewire::withoutLazyLoading()->test('laralyze.exceptions')->assertSeeInOrder(['LogicException', '3', '2']);
    Livewire::withoutLazyLoading()->test('laralyze.exception', ['name' => onlyExceptionKey()])->assertSeeInOrder(['Impacted users', '2']);
});

it('knows the job an exception happened in', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\RestockShelves');
    $job->shouldReceive('payload')->andReturn([]);

    event(new JobProcessing('redis', $job));
    report(new RuntimeException('Supplier timed out'));
    event(new JobFailed('redis', $job, new RuntimeException('Supplier timed out')));
    report(new LogicException('After the job'));
    Laralyze::flush();

    $sources = app(DatabaseStorage::class)->values('exception_details')
        ->mapWithKeys(fn ($row) => [json_decode($row->key, true)[0] => json_decode($row->value, true)['source']]);

    expect($sources[RuntimeException::class])->toBe(['type' => 'job', 'name' => 'App\Jobs\RestockShelves'])
        ->and($sources[LogicException::class])->toBeNull();
});

it('filters handled and unhandled exceptions, and searches them', function () {
    Route::get('/shelves', fn () => emptyShelf());
    $this->get('/shelves');
    report(new RuntimeException('Card declined'));
    Laralyze::flush();

    Livewire::withoutLazyLoading()->test('laralyze.exceptions')
        ->assertSee('LogicException')->assertSee('RuntimeException')
        ->set('show', 'unhandled')
        ->assertSee('LogicException')->assertDontSee('Card declined')
        ->set('show', 'handled')
        ->assertSee('Card declined')->assertDontSee('The shelf is empty')
        ->set('show', 'all')
        ->set('search', 'declined')
        ->assertSee('Card declined')->assertDontSee('The shelf is empty')
        ->set('search', 'nothing like it')
        ->assertSee('No exceptions match');
});

it('won\'t let the browser point the exception card elsewhere', function () {
    Livewire::withoutLazyLoading()->test('laralyze.exception', ['name' => '["A","b.php:1"]'])->set('name', '["B","c.php:2"]');
})->throws(CannotUpdateLockedPropertyException::class);
