<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Alerts\History;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Alerts\Rules;
use MohammedMojaly\Laralyze\Facades\Laralyze;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
    config(['laralyze.alerts.slack' => 'https://hooks.slack.test/abc']);
});

it('lists the Alerts page under Issues and shows each rule with its state', function () {
    alertRule(['name' => 'Failed jobs', 'metric' => 'jobs.failed', 'operator' => '>=', 'threshold' => 10]);

    $this->get('/laralyze/alerts')->assertOk()->assertSeeInOrder(['Issues', 'Exceptions', 'Findings', 'Alerts']);

    Livewire::test('laralyze.alerts')
        ->assertSee('Failed jobs')
        ->assertSee('Jobs · Failed')
        ->assertSee('>= 10')
        ->assertSee('Slack');
});

it('creates a rule from the form', function () {
    Livewire::test('laralyze.alerts')
        ->call('create')
        ->set('form.name', 'API errors')
        ->set('form.metric', 'requests.error_rate')
        ->set('form.threshold', '5')
        ->set('form.filter_by', 'route')
        ->set('form.filter_values', '/api/, /v2/')
        ->set('form.channels', ['slack'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', null)
        ->assertSee('API errors');

    $rule = collect(app(Rules::class)->all())->first();

    expect($rule->metric)->toBe('requests.error_rate')
        ->and($rule->threshold)->toBe(5.0)
        ->and($rule->filter?->toArray())->toBe(['by' => 'route', 'operator' => 'contains', 'values' => ['/api/', '/v2/']])
        ->and($rule->channels)->toBe(['slack']);
});

it('picks list values with checkboxes', function () {
    Livewire::test('laralyze.alerts')
        ->call('create')
        ->set('form.name', 'Errors in the log')
        ->set('form.metric', 'logs.entries')
        ->set('form.threshold', '10')
        ->set('form.filter_by', 'level')
        ->assertSet('form.filter_operator', 'in')
        ->set('form.filter_choices', ['error', 'critical'])
        ->call('save')
        ->assertHasNoErrors();

    expect(collect(app(Rules::class)->all())->first()->filter?->toArray())->toBe(['by' => 'level', 'operator' => 'in', 'values' => ['error', 'critical']]);
});

it('refuses a rule without a threshold, or with a channel that is not set', function () {
    Livewire::test('laralyze.alerts')
        ->call('create')
        ->set('form.name', 'Failed jobs')
        ->set('form.metric', 'jobs.failed')
        ->set('form.threshold', '')
        ->set('form.channels', ['telegram:-1'])
        ->call('save')
        ->assertHasErrors(['form.threshold', 'form.channels.0']);

    expect(app(Rules::class)->all())->toBe([]);
});

it('refuses the 101st rule', function () {
    foreach (range(1, 100) as $i) {
        app(Rules::class)->save(Rule::fromArray("R{$i}", ['name' => "Rule {$i}", 'metric' => 'jobs.failed', 'threshold' => 1]));
    }

    Livewire::test('laralyze.alerts')
        ->call('create')
        ->set('form.name', 'One more')
        ->set('form.metric', 'jobs.failed')
        ->set('form.threshold', '1')
        ->call('save')
        ->assertHasErrors('form.name');

    expect(app(Rules::class)->count())->toBe(100);
});

it('shows the current value next to the condition', function () {
    foreach (range(1, 12) as $i) {
        Laralyze::record('job_failed', 'App\Jobs\Import')->count();
    }
    Laralyze::flush();

    Livewire::test('laralyze.alerts')->call('create')->set('form.metric', 'jobs.failed')->assertSee('Now: 12');
});

it('edits, turns off, mutes, unmutes and deletes a rule', function () {
    $rule = alertRule(['name' => 'Failed jobs', 'metric' => 'jobs.failed', 'threshold' => 10]);
    $rules = app(Rules::class);
    $page = Livewire::test('laralyze.alerts');

    $page->call('edit', $rule->id)->assertSet('form.name', 'Failed jobs')->set('form.threshold', '20')->call('save')->assertHasNoErrors();

    expect($rules->find($rule->id)->threshold)->toBe(20.0)
        ->and($rules->find($rule->id)->createdAt)->toBe($rule->createdAt);

    $page->call('toggle', $rule->id);
    expect($rules->find($rule->id)->enabled)->toBeFalse();

    $page->call('mute', $rule->id, 60);
    expect($rules->find($rule->id)->mutedUntil)->toBeGreaterThan(now()->getTimestamp() + 3_500);

    $page->call('mute', $rule->id, -1);
    expect($rules->find($rule->id)->mutedUntil)->toBe(Rule::MUTED_FOREVER);

    $page->call('mute', $rule->id, 0);
    expect($rules->find($rule->id)->mutedUntil)->toBeNull();

    $page->call('delete', $rule->id);
    expect($rules->find($rule->id))->toBeNull();
});

it('sends a test and keeps it in history', function () {
    Http::fake();
    $rule = alertRule(['name' => 'Failed jobs', 'metric' => 'jobs.failed', 'threshold' => 10, 'channels' => ['slack']]);

    Livewire::test('laralyze.alerts')->call('test', $rule->id)->assertSee('Slack: sent.');

    $entry = app(History::class)->latest()[0];

    expect($entry['event'])->toBe('test')
        ->and($entry['deliveries']['slack']['status'])->toBe('sent');

    Http::assertSent(fn (Request $request) => str_contains((string) $request['text'], 'Test: Failed jobs'));
});

it('shows the history with each channel\'s delivery, and filters it', function () {
    $history = app(History::class);
    $fired = $history->add(['rule' => 'R1', 'rule_name' => 'API errors', 'metric' => 'requests.error_rate', 'event' => 'fired', 'title' => 'API errors: 7.5% (> 5%)', 'items' => [['item' => '', 'value' => '7.5%']], 'more' => 0, 'text' => '', 'note' => '', 'url' => ''], ['slack'], now()->getTimestamp() - 60);
    $history->save([...$fired, 'deliveries' => ['slack' => ['status' => 'failed', 'attempts' => 3, 'error' => 'HTTP 503', 'at' => now()->getTimestamp()]]]);
    $history->add(['rule' => 'R2', 'rule_name' => 'Disk', 'metric' => 'servers.disk', 'event' => 'recovered', 'title' => 'Disk: web-1 · / 50%', 'items' => [['item' => 'web-1 · /', 'value' => '50%']], 'more' => 0, 'text' => '', 'note' => '', 'url' => ''], [], now()->getTimestamp());

    $page = Livewire::test('laralyze.alerts')->set('tab', 'history');

    // Livewire has no assertSeeInOrder of its own, and the response of an update is JSON, so look at the rendered HTML.
    $html = $page->html();

    expect(strpos($html, 'Disk: web-1 · / 50%'))->toBeInt()
        ->and(strpos($html, 'API errors: 7.5% (&gt; 5%)'))->toBeGreaterThan(strpos($html, 'Disk: web-1 · / 50%'))
        ->and(strpos($html, 'HTTP 503'))->toBeGreaterThan(strpos($html, 'API errors: 7.5% (&gt; 5%)'));

    $page
        ->set('outcome', 'failed')
        ->assertSee('API errors')
        ->assertDontSee('Disk: web-1')
        ->set('outcome', '')
        ->set('rule', 'R2')
        ->assertSee('Disk: web-1')
        ->assertDontSee('API errors: 7.5%');
});

it('lets viewers see the page, and only managers change rules', function () {
    app()->detectEnvironment(fn () => 'production');
    Gate::define('viewLaralyze', fn ($user = null) => true);
    Gate::define('manageLaralyzeAlerts', fn ($user = null) => false);
    $rule = alertRule(['name' => 'Failed jobs', 'metric' => 'jobs.failed', 'threshold' => 10]);

    $this->get('/laralyze/alerts')->assertOk();

    Livewire::test('laralyze.alerts')->assertSee('Failed jobs')->assertDontSee('New rule')
        ->call('delete', $rule->id)->assertForbidden();
    Livewire::test('laralyze.alerts')->call('create')->assertForbidden();
    Livewire::test('laralyze.alerts')->call('toggle', $rule->id)->assertForbidden();
    Livewire::test('laralyze.alerts')->call('test', $rule->id)->assertForbidden();

    expect(app(Rules::class)->find($rule->id)?->enabled)->toBeTrue();
});

it('lets whoever may see the dashboard manage alerts by default', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(Gate::allows('manageLaralyzeAlerts'))->toBeFalse();

    Gate::define('viewLaralyze', fn ($user = null) => true);

    expect(Gate::allows('manageLaralyzeAlerts'))->toBeTrue();
});

it('says which .env keys to add when no channel is set', function () {
    config(['laralyze.alerts.slack' => null]);

    Livewire::test('laralyze.alerts')->assertSee('LARALYZE_ALERTS_SLACK_WEBHOOK');
});

it('offers Unmute only while a mute lasts', function () {
    $rule = alertRule(['name' => 'Failed jobs', 'metric' => 'jobs.failed', 'threshold' => 10]);
    $rules = app(Rules::class);

    $rules->save($rule->with(['muted_until' => now()->getTimestamp() - 60]));
    Livewire::test('laralyze.alerts')->assertDontSee('Unmute');

    $rules->save($rule->with(['muted_until' => now()->getTimestamp() + 3_600]));
    Livewire::test('laralyze.alerts')->assertSee('Unmute');
});
