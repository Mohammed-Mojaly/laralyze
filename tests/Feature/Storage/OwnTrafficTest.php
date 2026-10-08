<?php

use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Http\Middleware\Authorize;
use MohammedMojaly\Laralyze\Tests\Fixtures\ReadsCache;

beforeEach(function () {
    $this->rebootWith(['laralyze.middleware' => ['web', ReadsCache::class, Authorize::class]]);
    app()->detectEnvironment(fn () => 'local');
});

it('records nothing from its own dashboard, even what ran before it knew', function () {
    asWebRequest(function () {
        $this->get('/laralyze/cache')->assertOk();
        Laralyze::flush();
    });

    expect(app(Storage::class)->total('cache_miss', ['count'], 3_600)->count)->toBeNull();
});

it('still records the app\'s own requests', function () {
    Route::get('/shop', fn () => 'ok')->middleware(ReadsCache::class);

    asWebRequest(function () {
        $this->get('/shop')->assertOk();
        Laralyze::flush();
    });

    expect(app(Storage::class)->total('cache_miss', ['count'], 3_600, 'probe')->count)->toEqual(1);
});

it('sorts cache keys by any column, by total at first', function () {
    foreach (['busy' => [5, 1, 0], 'missed' => [0, 3, 1], 'written' => [0, 0, 9]] as $key => [$hits, $misses, $writes]) {
        foreach (['hit' => $hits, 'miss' => $misses, 'write' => $writes] as $type => $times) {
            for ($i = 0; $i < $times; $i++) {
                Laralyze::record("cache_{$type}", $key)->count();
            }
        }
    }
    Laralyze::flush();

    $card = Livewire::withoutLazyLoading()->test('laralyze.cache-keys');

    $card->assertSeeInOrder(['written', 'busy', 'missed'])
        ->call('sortBy', 'miss')->assertSeeInOrder(['missed', 'busy', 'written'])
        ->call('sortBy', 'ratio')->assertSeeInOrder(['busy', 'missed', 'written'])
        ->call('sortBy', 'key')->assertSeeInOrder(['busy', 'missed', 'written'])
        ->assertSee('Total');
});
