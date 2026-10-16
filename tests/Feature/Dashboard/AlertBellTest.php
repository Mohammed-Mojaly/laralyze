<?php

use Illuminate\Auth\GenericUser;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Alerts\History;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function bellEntry(string $title, int $at, array $deliveries = []): void
{
    $history = app(History::class);
    $entry = $history->add(['rule' => 'R1', 'rule_name' => 'Rule', 'metric' => 'jobs.failed', 'event' => 'fired', 'title' => $title, 'items' => [['item' => $title, 'value' => '']], 'more' => 0, 'text' => '', 'note' => '', 'url' => ''], [], $at);
    $history->save([...$entry, 'deliveries' => $deliveries]);
}

it('sits in the top bar of every page', function () {
    $this->get('/laralyze/requests')->assertOk()->assertSee('aria-label="Alerts', false);
});

it('counts the entries since the viewer last opened it, and lists failed deliveries first', function () {
    $now = now()->getTimestamp();
    bellEntry('Older and failed', $now - 120, ['slack' => ['status' => 'failed', 'attempts' => 3, 'error' => 'HTTP 503', 'at' => $now]]);
    bellEntry('Newest', $now - 60);

    $bell = Livewire::test('laralyze.alert-bell')
        ->assertSeeInOrder(['Older and failed', 'not delivered', 'Newest'])
        ->assertSeeHtml('<span class="lz-bell-count">2</span>');

    $bell->call('markSeen')->assertDontSeeHtml('lz-bell-count');

    bellEntry('After', $now + 60);
    $this->travel(2)->minutes();

    Livewire::test('laralyze.alert-bell')->assertSeeHtml('<span class="lz-bell-count">1</span>');
});

it('keeps a seen marker per user', function () {
    bellEntry('Something', now()->getTimestamp() - 60);

    $this->actingAs(new GenericUser(['id' => 7]));
    Livewire::test('laralyze.alert-bell')->call('markSeen');

    expect(app(History::class)->seenAt('7'))->toBeGreaterThan(0)
        ->and(app(History::class)->seenAt(null))->toBe(0);
});
