<?php

use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Dashboard\Issues;
use MohammedMojaly\Laralyze\Facades\Laralyze;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

it('puts what matters first on the dashboard', function () {
    $this->get('/laralyze')->assertOk()->assertSeeInOrder([
        'laralyze.attention',
        'laralyze.request-totals',
        'laralyze.request-duration',
        'laralyze.exceptions',
        'laralyze.queues',
        'laralyze.slow-requests',
        'laralyze.slow-jobs',
    ]);
});

it('shows the open exceptions seen most, without the list controls, on the dashboard', function () {
    foreach (['RareError' => 1, 'CommonError' => 9, 'FixedError' => 20] as $class => $times) {
        foreach (range(1, $times) as $i) {
            Laralyze::record('exception', json_encode(["App\\{$class}", 'app/A.php:1']), time() - 60)->count()->max();
            Laralyze::record('exception_handled', 'all')->count();
        }
    }
    Laralyze::flush();
    app(Issues::class)->resolve(json_encode(['App\FixedError', 'app/A.php:1']));

    Livewire::withoutLazyLoading()->test('laralyze.exceptions', ['compact' => true])
        ->assertSeeInOrder(['CommonError', 'RareError'])
        ->assertDontSee('FixedError')
        ->assertDontSee('Search exceptions')
        ->assertDontSee('Unhandled</button>', escape: false)
        ->assertSee('All exceptions');

    Livewire::withoutLazyLoading()->test('laralyze.exceptions')->assertSee('Search exceptions')->assertDontSee('All exceptions');
});

it('keeps four columns of the queues table on the dashboard', function () {
    Laralyze::record('queue_processed', 'redis:default')->count();
    Laralyze::record('queue_wait', 'redis:default', 120)->avg()->max();
    Laralyze::flush();

    Livewire::withoutLazyLoading()->test('laralyze.queues', ['compact' => true])
        ->assertSee('Longest wait')
        ->assertDontSee('Avg wait')
        ->assertSee('All jobs');

    Livewire::withoutLazyLoading()->test('laralyze.queues')->assertSee('Avg wait')->assertDontSee('All jobs');
});

it('really hides cards that hide themselves, whatever their own display', function () {
    // .lz-card and .lz-grid set display, which would otherwise win over [hidden].
    expect($this->get('/laralyze')->getContent())->toMatch('/\[hidden\]\s*\{\s*display:\s*none\s*!important;?\s*\}/');
});

it('leaves the AI summary out until there are calls', function () {
    Livewire::withoutLazyLoading()->test('laralyze.ai-totals', ['summary' => true])->assertSee('hidden wire:poll.30s', escape: false);

    Laralyze::record('ai', 'openai:gpt', 300)->count()->avg()->max();
    Laralyze::flush();
    Cache::flush();

    Livewire::withoutLazyLoading()->test('laralyze.ai-totals', ['summary' => true])->assertSee('AI calls')->assertDontSee('hidden wire:poll.30s', escape: false);
});
