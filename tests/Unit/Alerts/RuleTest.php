<?php

use MohammedMojaly\Laralyze\Alerts\Filter;
use MohammedMojaly\Laralyze\Alerts\Rule;

it('reads a rule from its JSON and writes it back the same', function () {
    $data = [
        'name' => 'API errors',
        'metric' => 'requests.error_rate',
        'operator' => '>',
        'threshold' => 5.0,
        'window' => 300,
        'filter' => ['by' => 'route', 'operator' => 'contains', 'values' => ['/api/']],
        'mode' => 'together',
        'channels' => ['slack', 'mail:ops@example.com', 'telegram:-100123'],
        'remind' => 0,
        'title' => '',
        'note' => 'Check the payment gateway first.',
        'enabled' => true,
        'muted_until' => null,
        'created_at' => 1791900000,
        'updated_at' => 1791900000,
    ];

    $rule = Rule::fromJson('01J0000000000000000000000', (string) json_encode($data));

    expect($rule->toArray())->toBe($data)
        ->and($rule->filter)->toBeInstanceOf(Filter::class);
});

it('falls back to safe values for anything it does not know', function () {
    $rule = Rule::fromArray('r', ['metric' => 'jobs.failed', 'operator' => 'LIKE', 'window' => 42, 'remind' => 7, 'mode' => 'x', 'title' => str_repeat('a', 300)]);

    expect($rule->operator)->toBe('>')
        ->and($rule->window)->toBe(300)
        ->and($rule->remind)->toBe(0)
        ->and($rule->mode)->toBe(Rule::TOGETHER)
        ->and(mb_strlen($rule->title))->toBe(150)
        ->and($rule->threshold)->toBeNull()
        ->and(Rule::fromJson('r', 'not json'))->toBeNull();
});

it('compares a value with each operator', function (string $operator, float $value, bool $breaches) {
    expect(Rule::fromArray('r', ['metric' => 'm', 'operator' => $operator, 'threshold' => 5])->breaches($value))->toBe($breaches);
})->with([
    ['>', 5.1, true], ['>', 5, false],
    ['>=', 5, true], ['>=', 4.9, false],
    ['<', 4.9, true], ['<', 5, false],
    ['<=', 5, true], ['<=', 5.1, false],
    ['=', 5, true], ['=', 5.1, false],
    ['!=', 5.1, true], ['!=', 5, false],
]);

it('never breaches without a threshold', function () {
    expect(Rule::fromArray('r', ['metric' => 'm'])->breaches(1_000))->toBeFalse();
});

it('is muted until a time, until unmuted, or not at all', function () {
    expect(Rule::fromArray('r', ['metric' => 'm', 'muted_until' => 200])->muted(100))->toBeTrue()
        ->and(Rule::fromArray('r', ['metric' => 'm', 'muted_until' => 200])->muted(300))->toBeFalse()
        ->and(Rule::fromArray('r', ['metric' => 'm', 'muted_until' => Rule::MUTED_FOREVER])->muted(PHP_INT_MAX))->toBeTrue()
        ->and(Rule::fromArray('r', ['metric' => 'm'])->muted(100))->toBeFalse();
});

it('checks 24-hour rules only every five minutes', function () {
    $daily = Rule::fromArray('r', ['metric' => 'm', 'window' => 86_400]);
    $short = Rule::fromArray('r', ['metric' => 'm', 'window' => 300]);

    expect($daily->dueAt(300))->toBeTrue()
        ->and($daily->dueAt(360))->toBeFalse()
        ->and($short->dueAt(360))->toBeTrue();
});

it('matches filters without case', function (string $operator, array $values, string $value, bool $matches) {
    expect((new Filter('route', $operator, $values))->matches($value))->toBe($matches);
})->with([
    ['equals', ['GET /a'], 'get /a', true],
    ['not_equals', ['GET /a'], 'GET /b', true],
    ['contains', ['/API/'], 'GET /api/users', true],
    ['not_contains', ['/api/'], 'GET /api/users', false],
    ['in', ['error', 'critical'], 'critical', true],
    ['not_in', ['error', 'critical'], 'warning', true],
]);

it('drops a filter with no values or an unknown operator', function () {
    expect(Filter::fromArray(['by' => 'route', 'operator' => 'contains', 'values' => ['', ' ']]))->toBeNull()
        ->and(Filter::fromArray(['by' => 'route', 'operator' => 'regex', 'values' => ['a']]))->toBeNull()
        ->and(Filter::fromArray(null))->toBeNull()
        ->and(Filter::fromArray(['by' => 'route', 'operator' => 'contains', 'values' => [' /api/ ']])?->values)->toBe(['/api/']);
});

it('keeps channels in order, without duplicates', function () {
    expect(Rule::fromArray('r', ['metric' => 'm', 'channels' => ['slack', 'slack', '', 'webhook']])->channels)->toBe(['slack', 'webhook']);
});
