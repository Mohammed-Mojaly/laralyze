<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use MohammedMojaly\Laralyze\Alerts\AlertNotification;
use MohammedMojaly\Laralyze\Alerts\Channels;
use MohammedMojaly\Laralyze\Alerts\Message;
use MohammedMojaly\Laralyze\Alerts\Sender;

beforeEach(function () {
    config([
        'laralyze.alerts.mail' => 'ops@example.com',
        'laralyze.alerts.slack' => 'https://hooks.slack.test/secret-slack',
        'laralyze.alerts.discord' => 'https://discord.test/api/webhooks/secret-discord',
        'laralyze.alerts.telegram' => ['token' => '123:secret-token', 'chats' => '-100123, 42'],
        'laralyze.alerts.webhook' => ['url' => 'https://hooks.test/laralyze', 'secret' => 'shh'],
    ]);
});

function senderMessage(): Message
{
    return Message::fromEntry([
        'event' => 'fired', 'rule' => 'R1', 'rule_name' => 'API errors', 'title' => 'API errors: 7.5% (> 5%)',
        'items' => [['item' => '', 'value' => '7.5%']], 'more' => 0, 'text' => '', 'note' => '',
        'url' => 'https://app.test/laralyze/alerts', 'at' => 1791900000,
    ]);
}

it('lists the channels set in .env', function () {
    $channels = app(Channels::class);

    expect($channels->configured())->toBe([
        'mail:ops@example.com' => 'Mail: ops@example.com',
        'slack' => 'Slack',
        'discord' => 'Discord',
        'telegram:-100123' => 'Telegram: -100123',
        'telegram:42' => 'Telegram: 42',
        'webhook' => 'Webhook',
    ])
        ->and($channels->resolve(['all']))->toHaveCount(6)
        ->and($channels->resolve(['slack', 'gone']))->toBe(['slack', 'gone']);
});

it('sends to each kind of channel', function () {
    Http::fake();
    Notification::fake();
    $sender = app(Sender::class);

    foreach (['slack', 'discord', 'telegram:-100123', 'webhook', 'mail:ops@example.com'] as $channel) {
        expect($sender->send($channel, senderMessage()))->toBeNull();
    }

    Http::assertSent(fn (Request $request) => $request->url() === 'https://hooks.slack.test/secret-slack' && str_contains((string) $request['text'], 'API errors'));
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://discord.test') && $request['allowed_mentions'] === ['parse' => []]);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.telegram.org/bot123:secret-token/sendMessage' && $request['chat_id'] === '-100123' && $request['parse_mode'] === 'HTML');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://hooks.test/laralyze' && $request['event'] === 'fired' && $request['rule'] === ['id' => 'R1', 'name' => 'API errors']);
    Notification::assertSentOnDemand(AlertNotification::class, fn (AlertNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'ops@example.com'
        && str_contains($notification->toMail($notifiable)->subject, 'API errors'));
});

it('signs the webhook over the timestamp and the body', function () {
    Http::fake();

    app(Sender::class)->send('webhook', senderMessage());

    Http::assertSent(function (Request $request) {
        $timestamp = $request->header('X-Laralyze-Timestamp')[0] ?? '';

        return $request->header('X-Laralyze-Signature')[0] === hash_hmac('sha256', $timestamp.'.'.$request->body(), 'shh')
            && abs((int) $timestamp - now()->getTimestamp()) <= 1;
    });
});

it('sends the webhook unsigned without a secret', function () {
    config(['laralyze.alerts.webhook.secret' => null]);
    Http::fake();

    app(Sender::class)->send('webhook', senderMessage());

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Laralyze-Signature'));
});

it('never shows a channel\'s secret in an error', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host for https://api.telegram.org/bot123:secret-token/sendMessage and https://hooks.slack.test/secret-slack'));

    $error = app(Sender::class)->send('telegram:-100123', senderMessage());

    expect($error)->toContain('cURL error 6')->not->toContain('secret-token')->not->toContain('secret-slack');
});

it('hides the longer secret whole when one secret starts another', function () {
    config(['laralyze.alerts.webhook.url' => 'https://hooks.slack.test/secret-slack/more-secret']);
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to https://hooks.slack.test/secret-slack/more-secret'));

    $error = app(Sender::class)->send('webhook', senderMessage());

    expect($error)->toContain('[hidden]')->not->toContain('more-secret');
});

it('says what a channel answered when it refused', function () {
    Http::fake(['hooks.slack.test/*' => Http::response('no_service', 404)]);

    expect(app(Sender::class)->send('slack', senderMessage()))->toContain('404');
});
