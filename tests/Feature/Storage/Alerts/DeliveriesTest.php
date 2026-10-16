<?php

use Illuminate\Support\Facades\Http;
use MohammedMojaly\Laralyze\Alerts\Deliveries;
use MohammedMojaly\Laralyze\Alerts\History;

beforeEach(function () {
    config(['laralyze.alerts.slack' => 'https://hooks.slack.test/abc', 'laralyze.alerts.discord' => 'https://discord.test/hook']);
});

/**
 * @param  list<string>  $channels
 * @return array<string, mixed>
 */
function pendingEntry(array $channels = ['slack'], string $rule = 'R1'): array
{
    return app(History::class)->add([
        'rule' => $rule,
        'rule_name' => 'API errors',
        'metric' => 'requests.error_rate',
        'event' => History::FIRED,
        'title' => 'API errors: 7.5% (> 5%)',
        'items' => [['item' => '', 'value' => '7.5%']],
        'more' => 0,
        'text' => '',
        'note' => '',
        'url' => 'https://app.test/laralyze/alerts',
    ], $channels, now()->getTimestamp());
}

it('keeps an entry with a pending delivery per channel', function () {
    $entry = pendingEntry(['slack', 'discord']);

    expect($entry['key'])->toStartWith(sprintf('%010d:R1:', now()->getTimestamp()))
        ->and(app(History::class)->find($entry['key'])['deliveries'])->toBe([
            'slack' => ['status' => 'pending', 'attempts' => 0, 'error' => null, 'at' => null],
            'discord' => ['status' => 'pending', 'attempts' => 0, 'error' => null, 'at' => null],
        ]);
});

it('sends pending deliveries and marks them sent', function () {
    Http::fake();
    $entry = pendingEntry(['slack', 'discord']);

    app(Deliveries::class)->send();

    $kept = app(History::class)->find($entry['key']);

    expect($kept['deliveries']['slack']['status'])->toBe(History::SENT)
        ->and($kept['deliveries']['discord']['status'])->toBe(History::SENT)
        ->and(app(History::class)->pending())->toBe([]);

    Http::assertSentCount(2);
});

it('tries a failed channel on the next looks, three times in all, then marks it failed', function () {
    Http::fake(['hooks.slack.test/*' => Http::response('down', 503), 'discord.test/*' => Http::response('ok')]);
    $entry = pendingEntry(['slack', 'discord']);
    $deliveries = app(Deliveries::class);

    $deliveries->send();

    expect(app(History::class)->find($entry['key'])['deliveries']['slack'])->toMatchArray(['status' => History::PENDING, 'attempts' => 1]);

    $deliveries->send();
    $deliveries->send();
    $deliveries->send();

    $kept = app(History::class)->find($entry['key']);

    expect($kept['deliveries']['slack'])->toMatchArray(['status' => History::FAILED, 'attempts' => 3])
        ->and($kept['deliveries']['slack']['error'])->toContain('503')
        ->and($kept['deliveries']['discord'])->toMatchArray(['status' => History::SENT, 'attempts' => 1])
        ->and(History::failed($kept))->toBeTrue();

    Http::assertSentCount(4);
});

it('fails a channel that is no longer set at once', function () {
    Http::fake();
    $entry = pendingEntry(['telegram:-100123']);

    app(Deliveries::class)->send();

    expect(app(History::class)->find($entry['key'])['deliveries']['telegram:-100123'])->toMatchArray(['status' => History::FAILED, 'attempts' => 1, 'error' => 'Not set in .env.']);

    Http::assertNothingSent();
});

it('stops at the time budget, and sends the rest on the next look without counting an attempt', function () {
    Http::fake(function () {
        usleep(60_000);

        return Http::response('ok');
    });
    $first = pendingEntry(['slack'], 'R1');
    $this->travel(1)->seconds();
    $second = pendingEntry(['slack'], 'R2');
    $history = app(History::class);

    app(Deliveries::class)->send(0.05);

    expect($history->find($first['key'])['deliveries']['slack']['status'])->toBe(History::SENT)
        ->and($history->find($second['key'])['deliveries']['slack'])->toMatchArray(['status' => History::PENDING, 'attempts' => 0]);

    app(Deliveries::class)->send();

    expect($history->find($second['key'])['deliveries']['slack']['status'])->toBe(History::SENT);
});

it('keeps an entry without channels, with nothing to send', function () {
    $entry = pendingEntry([]);

    expect(app(History::class)->find($entry['key'])['deliveries'])->toBe([])
        ->and(app(History::class)->pending())->toBe([]);
});
