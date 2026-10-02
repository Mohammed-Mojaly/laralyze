<?php

use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;

const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');

    Route::get('/pricing', fn () => response('<h1>Pricing</h1>'));
    Route::get('/api/plans', fn () => response('<h1>Plans</h1>'));
    Route::get('/feed', fn () => response()->json(['posts' => []]));
    Route::post('/signup', fn () => response('<h1>Thanks</h1>'));
    Route::get('/missing-page', fn () => abort(404));
});

function visitPage(string $path, string $agent = CHROME, string $ip = '10.0.0.1'): void
{
    test()->withHeaders(['User-Agent' => $agent])->withServerVariables(['REMOTE_ADDR' => $ip])->get($path);
}

function visitCount(string $type, ?string $key = null): float
{
    $rows = app(DatabaseStorage::class)->aggregate($type, ['count'], 3_600)->keyBy('key');

    return (float) ($key === null ? $rows->sum('count') : ($rows[$key]->count ?? 0));
}

it('counts page views and unique visitors once a day', function () {
    visitPage('/pricing');
    visitPage('/pricing');
    visitPage('/pricing', IPHONE, '10.0.0.2');

    expect(visitCount('visit', '/pricing'))->toBe(3.0)
        ->and(visitCount('visitor'))->toBe(2.0)
        ->and(visitCount('visitor_device', 'Desktop'))->toBe(1.0)
        ->and(visitCount('visitor_device', 'Mobile'))->toBe(1.0)
        ->and(visitCount('visitor_os', 'iOS'))->toBe(1.0)
        ->and(visitCount('visitor_browser', 'Chrome'))->toBe(1.0);
});

it('only counts successful GET requests for pages', function () {
    test()->withHeaders(['User-Agent' => CHROME])->post('/signup');
    visitPage('/feed');
    visitPage('/missing-page');

    expect(visitCount('visit'))->toBe(0.0);
});

it('leaves out the paths you exclude', function () {
    visitPage('/api/plans');

    expect(visitCount('visit'))->toBe(0.0);
});

it('keeps bots apart from visitors', function () {
    visitPage('/pricing', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

    expect(visitCount('visit'))->toBe(0.0)
        ->and(visitCount('visit_bot', 'Googlebot'))->toBe(1.0);
});

it('never stores IP addresses or user agents', function () {
    visitPage('/pricing', CHROME, '203.0.113.9');

    $stored = json_encode([
        DB::table('laralyze_aggregates')->pluck('key'),
        DB::table('laralyze_values')->get(['key', 'value']),
    ]);

    expect($stored)->not->toContain('203.0.113.9')
        ->not->toContain('Chrome/129');
});

it('shows who is here now and where they come from', function () {
    visitPage('/pricing');
    visitPage('/pricing', IPHONE, '10.0.0.2');
    visitPage('/pricing', 'curl/8.7.1');

    Livewire::withoutLazyLoading()->test('laralyze.visits')->assertSeeInOrder(['2', 'visitors in the last 5 minutes']);
    Livewire::withoutLazyLoading()->test('laralyze.audience')->assertSee('Mobile')->assertSee('iOS')->assertSee('50%');
    Livewire::withoutLazyLoading()->test('laralyze.top-pages')->assertSee('/pricing');
    Livewire::withoutLazyLoading()->test('laralyze.bots')->assertSee('Script');
});

it('forgets when visitors were last seen after a day', function () {
    visitPage('/pricing');

    $this->travel(25)->hours();
    app(DatabaseStorage::class)->trim(30);

    expect(DB::table('laralyze_values')->where('type', 'visitor_seen')->count())->toBe(0);
});

it('counts pages by route, so tokens in URLs are never stored', function () {
    Route::get('/reset-password/{token}', fn () => response('<h1>Reset</h1>'));

    visitPage('/reset-password/s3cr3t-t0ken-abc');

    expect(visitCount('visit', '/reset-password/{token}'))->toBe(1.0)
        ->and(json_encode(DB::table('laralyze_aggregates')->pluck('key')))->not->toContain('s3cr3t');
});

it('shows brand icons next to systems, browsers, devices and bots', function () {
    app()->detectEnvironment(fn () => 'local');

    Laralyze::record('visitor_os', 'Windows')->count();
    Laralyze::record('visitor_browser', 'Samsung Internet')->count();
    Laralyze::record('visitor_device', 'Mobile')->count();
    Laralyze::record('visit_bot', 'Googlebot')->count();
    Laralyze::flush();

    Livewire::withoutLazyLoading()->test('laralyze.audience')
        ->assertSee('<svg class="lz-mark" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="color: #0078D4">', false)
        ->assertSee('lz-mark-line', false)
        ->assertSee('Samsung Internet');

    Livewire::withoutLazyLoading()->test('laralyze.bots')->assertSee('style="color: #4285F4"', false);
});
