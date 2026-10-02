<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Alerts\AlertNotification;
use MohammedMojaly\Laralyze\Alerts\Alerts;
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

it('sends an alert for a new exception by mail and to Slack, once', function () {
    config(['laralyze.alerts.mail' => 'ops@example.com, cto@example.com', 'laralyze.alerts.slack' => 'https://hooks.slack.test/abc']);
    Notification::fake();
    Http::fake();

    report(new RuntimeException('Card declined'));
    Laralyze::flush();

    app(Alerts::class)->run();
    app(Alerts::class)->run();

    Notification::assertSentOnDemandTimes(AlertNotification::class, 1);
    Notification::assertSentOnDemand(AlertNotification::class, function (AlertNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
        return $notifiable->routes['mail'] === ['ops@example.com', 'cto@example.com']
            && str_contains($notification->toMail($notifiable)->subject, 'New exception: RuntimeException');
    });

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://hooks.slack.test/abc' && str_contains((string) $request['text'], 'Card declined'));
});

it('alerts on a high error rate and on failing jobs', function () {
    config(['laralyze.alerts.discord' => 'https://discord.test/hook']);
    Http::fake();

    foreach (range(1, 30) as $i) {
        Laralyze::record('request', 'GET /checkout', 10)->count();
        Laralyze::record($i <= 6 ? 'request_5xx' : 'request_2xx', 'GET /checkout')->count();
    }
    foreach (range(1, 12) as $i) {
        Laralyze::record('job_failed', 'App\Jobs\SendInvoice')->count();
    }
    Laralyze::flush();

    $titles = array_map(fn ($alert) => $alert->title, app(Alerts::class)->check());

    expect($titles)->toBe(['20% of requests failed', '12 jobs failed']);

    app(Alerts::class)->run();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://discord.test/hook' && str_contains((string) $request['content'], 'jobs failed'));
});

it('stays quiet without a channel, and only schedules itself with one', function () {
    Http::fake();
    report(new RuntimeException('Card declined'));
    Laralyze::flush();

    app(Alerts::class)->run();
    Http::assertNothingSent();

    $scheduled = fn () => collect(app(Schedule::class)->events())->pluck('description')->all();

    expect($scheduled())->not->toContain('laralyze:alerts');

    $this->rebootWith(['laralyze.alerts.slack' => 'https://hooks.slack.test/abc']);

    expect($scheduled())->toContain('laralyze:alerts');
});
