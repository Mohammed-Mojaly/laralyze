<?php

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

    expect($all)->toHaveCount(8)
        ->and($all->keys()->sort()->values()->all())->toBe(['ai.cost', 'database.problem', 'exceptions.new', 'jobs.failed', 'requests.error_rate', 'scheduled.failed', 'servers.disk', 'servers.stopped'])
        ->and($all['requests.error_rate']->threshold)->toBe(3.0)
        ->and($all['requests.error_rate']->enabled)->toBeTrue()
        ->and($all['jobs.failed']->enabled)->toBeFalse()
        ->and($all['jobs.failed']->threshold)->toBe(10.0)
        ->and($all['exceptions.new']->enabled)->toBeTrue()
        ->and($all['database.problem']->filter?->values)->toBe(DatabaseProblems::DEFAULT_KINDS)
        ->and($all['servers.stopped']->threshold)->toBe(5.0)
        ->and($all['servers.disk']->mode)->toBe(Rule::EACH)
        ->and($all['ai.cost']->enabled)->toBeFalse()
        ->and($all['ai.cost']->window)->toBe(86_400)
        ->and($all->every(fn (Rule $rule) => $rule->channels === [Rule::ALL_CHANNELS]))->toBeTrue();
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
