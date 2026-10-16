<?php

use Livewire\Livewire;
use MohammedMojaly\Laralyze\Dashboard\Issues;
use MohammedMojaly\Laralyze\Facades\Laralyze;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function seenAt(string $key, int $timestamp): void
{
    Laralyze::record('exception', $key, $timestamp, $timestamp)->count()->min()->max();
    Laralyze::record('exception_unhandled', $key, timestamp: $timestamp)->count();
    Laralyze::set('exception_message', $key, 'The shelf is empty');
    Laralyze::flush();
}

it('resolves, ignores and reopens exceptions, and reopens resolved ones that happen again', function () {
    $key = (string) json_encode([LogicException::class, 'app/Shelf.php:12']);
    seenAt($key, time() - 100);

    $card = Livewire::withoutLazyLoading()->test('laralyze.exception', ['name' => $key])->assertSee('Resolve')->assertSee('open');

    $card->call('resolve')->assertSee('resolved')->assertSee('Reopen');

    Livewire::withoutLazyLoading()->test('laralyze.exceptions')
        ->assertDontSee('The shelf is empty')
        ->set('status', 'resolved')
        ->assertSee('The shelf is empty');

    seenAt($key, time() + 5);

    // Cards share results for a few seconds; this one should see the new occurrence.
    cache()->flush();

    expect(app(Issues::class)->statuses([$key => time() + 5])[$key])->toBe(Issues::REOPENED);

    Livewire::withoutLazyLoading()->test('laralyze.exceptions')->assertSee('The shelf is empty')->assertSee('Reopened');

    $card->call('ignore');

    expect(app(Issues::class)->statuses([$key => time() + 10])[$key])->toBe(Issues::IGNORED);

    $card->call('reopen');

    expect(app(Issues::class)->statuses([$key => time()])[$key])->toBe(Issues::OPEN);
});
