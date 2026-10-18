<?php

use MohammedMojaly\Laralyze\Alerts\Metrics\Catalog;
use MohammedMojaly\Laralyze\Alerts\Metrics\DatabaseProblems;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Alerts\Rules;
use MohammedMojaly\Laralyze\Alerts\States;

it('saves, finds, lists by name and deletes rules with their states', function () {
    $rules = app(Rules::class);
    $beta = $rules->save(Rule::fromArray('B1', ['name' => 'beta', 'metric' => 'jobs.failed']));
    $rules->save(Rule::fromArray('A1', ['name' => 'Alpha', 'metric' => 'jobs.failed']));
    app(States::class)->put('B1', '*', ['since' => 1, 'clean' => 0, 'reminded' => 1, 'value' => 3.0]);

    expect(array_keys($rules->all()))->toBe(['A1', 'B1'])
        ->and($rules->find('B1')?->name)->toBe('beta')
        ->and($beta->createdAt)->toBe(now()->getTimestamp())
        ->and($rules->count())->toBe(2)
        ->and(app(States::class)->firing()['B1']['*']['value'])->toBe(3.0);

    $rules->delete('B1');

    expect($rules->find('B1'))->toBeNull()
        ->and(app(States::class)->firing())->toBe([]);
});

it('keeps when a rule was made, and forgets what it had firing when turned off', function () {
    $rules = app(Rules::class);
    $rule = $rules->save(Rule::fromArray('R1', ['name' => 'Rule', 'metric' => 'jobs.failed']));
    app(States::class)->put('R1', 'App\Jobs\Import', ['since' => 1, 'clean' => 0, 'reminded' => 1, 'value' => 3.0]);

    $this->travel(5)->minutes();
    $off = $rules->save($rule->with(['enabled' => false]));

    expect($off->createdAt)->toBe($rule->createdAt)
        ->and($off->updatedAt)->toBeGreaterThan($rule->updatedAt)
        ->and(app(States::class)->firing())->toBe([]);
});

it('creates the default rules once, from the old config', function () {
    config(['laralyze.alerts.rules' => ['exceptions' => true, 'error_rate' => 3, 'failed_jobs' => false]]);
    $rules = app(Rules::class);

    $rules->createDefaults();
    $rules->createDefaults();

    $all = collect($rules->all())->keyBy(fn (Rule $rule) => $rule->metric);

    expect($all)->toHaveCount(13)
        ->and($all->keys()->sort()->values()->all())->toBe(['ai.cost', 'database.problem', 'exceptions.new', 'jobs.failed', 'logs.entries', 'queues.waiting', 'requests.error_rate', 'requests.p95', 'scheduled.failed', 'scheduled.overdue', 'servers.cpu', 'servers.disk', 'servers.stopped'])
        ->and($all['requests.error_rate']->threshold)->toBe(3.0)
        ->and($all['requests.error_rate']->enabled)->toBeTrue()
        ->and($all['jobs.failed']->enabled)->toBeFalse()
        ->and($all['jobs.failed']->threshold)->toBe(10.0)
        ->and($all['exceptions.new']->enabled)->toBeTrue()
        ->and($all['database.problem']->filter?->values)->toBe(DatabaseProblems::DEFAULT_KINDS)
        ->and($all['servers.stopped']->threshold)->toBe(5.0)
        ->and($all['servers.disk']->mode)->toBe(Rule::EACH)
        ->and($all['ai.cost']->enabled)->toBeFalse()
        ->and($all['ai.cost']->threshold)->toBe(10.0)
        ->and($all['ai.cost']->window)->toBe(86_400)
        // Ready to turn on as they are, to show what a rule can watch.
        ->and($all->only(['requests.p95', 'queues.waiting', 'logs.entries', 'scheduled.overdue', 'servers.cpu'])->every(fn (Rule $rule) => ! $rule->enabled))->toBeTrue()
        ->and($all['requests.p95']->threshold)->toBe(1000.0)
        ->and($all['logs.entries']->filter?->values)->toBe(['emergency', 'alert', 'critical', 'error'])
        ->and($all['servers.cpu']->mode)->toBe(Rule::EACH)
        ->and($all->every(fn (Rule $rule) => $rule->channels === [Rule::ALL_CHANNELS]))->toBeTrue();
});

it('makes a default rule turned off with null disabled, with its usual threshold', function () {
    config(['laralyze.alerts.rules' => ['exceptions' => null, 'error_rate' => null]]);
    $rules = app(Rules::class);

    $rules->createDefaults();

    $all = collect($rules->all())->keyBy(fn (Rule $rule) => $rule->metric);

    expect($all['exceptions.new']->enabled)->toBeFalse()
        ->and($all['requests.error_rate']->enabled)->toBeFalse()
        ->and($all['requests.error_rate']->threshold)->toBe(5.0)
        ->and($all['jobs.failed']->enabled)->toBeTrue()
        ->and($all['jobs.failed']->threshold)->toBe(10.0);
});

it('skips default rules whose metric is not available', function () {
    $catalog = app(Catalog::class);
    // As if laravel/ai wasn't installed.
    $metrics = array_diff_key($catalog->all(), array_flip(['ai.cost', 'ai.tokens', 'ai.failed']));
    (fn () => $this->metrics = $metrics)->call($catalog);
    app()->instance(Catalog::class, $catalog);

    app(Rules::class)->createDefaults();

    expect(array_map(fn (Rule $rule) => $rule->metric, app(Rules::class)->all()))->toHaveCount(12)->not->toContain('ai.cost');
});

it('forgets what a rule had firing when what it watches changes, but not when only the threshold does', function () {
    $rules = app(Rules::class);
    $rule = $rules->save(Rule::fromArray('R1', ['name' => 'Rule', 'metric' => 'jobs.failed', 'threshold' => 10]));
    $firing = fn () => app(States::class)->put('R1', '*', ['since' => 1, 'clean' => 0, 'reminded' => 1, 'value' => 12.0]);

    $firing();
    $rules->save($rule->with(['threshold' => 5]));

    expect(app(States::class)->firing())->toHaveKey('R1');

    foreach ([['metric' => 'jobs.p95'], ['mode' => Rule::EACH], ['filter' => ['by' => 'job', 'operator' => 'equals', 'values' => ['App\Jobs\Import']]]] as $change) {
        $firing();
        $rules->save($rule->with($change));

        expect(app(States::class)->firing())->toBe([]);
    }
});

it('does not bring back default rules that were deleted', function () {
    $rules = app(Rules::class);
    $rules->createDefaults();

    foreach ($rules->all() as $rule) {
        $rules->delete($rule->id);
    }

    $rules->createDefaults();

    expect($rules->all())->toBe([]);
});
