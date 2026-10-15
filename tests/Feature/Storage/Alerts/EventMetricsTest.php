<?php

use MohammedMojaly\Laralyze\Alerts\Metrics\DatabaseProblems;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Issues;
use MohammedMojaly\Laralyze\Database\Errors;
use MohammedMojaly\Laralyze\Facades\Laralyze;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function exceptionAt(string $key, int $at, string $message = 'Card declined'): void
{
    Laralyze::record('exception', $key, $at, $at)->count()->min()->max();
    Laralyze::set('exception_message', $key, $message);
    Laralyze::flush();
}

it('finds new exceptions and resolved ones that came back', function () {
    $new = (string) json_encode([RuntimeException::class, 'app/Pay.php:3']);
    $back = (string) json_encode([LogicException::class, 'app/Shelf.php:12']);

    exceptionAt($back, now()->getTimestamp() - 7_200, 'The shelf is empty');
    app(Issues::class)->resolve($back);
    $this->travel(10)->seconds();
    exceptionAt($new, now()->getTimestamp());
    exceptionAt($back, now()->getTimestamp(), 'The shelf is empty');

    expect(array_column(alertEvents(['metric' => 'exceptions.new']), 'item'))->toEqualCanonicalizing(['New exception: RuntimeException', 'Reopened: LogicException'])
        ->and(alertEvents(['metric' => 'exceptions.new', 'filter' => ['by' => 'class', 'operator' => 'contains', 'values' => ['logic']]]))->toHaveCount(1)
        ->and(alertEvents(['metric' => 'exceptions.new', 'filter' => ['by' => 'message', 'operator' => 'contains', 'values' => ['declined']]]))->toHaveCount(1)
        ->and(alertEvents(['metric' => 'exceptions.new', 'filter' => ['by' => 'message', 'operator' => 'contains', 'values' => ['nothing']]]))->toBe([]);
});

it('finds each failed run of a scheduled task by when it failed', function () {
    $at = time() - 120;
    Laralyze::record('scheduled_failed', 'reports:send', $at, $at)->count()->max();
    Laralyze::flush();

    $events = alertEvents(['metric' => 'scheduled.failed']);

    expect($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe("reports:send:{$at}")
        ->and($events[0]['item'])->toBe('reports:send')
        ->and(alertEvents(['metric' => 'scheduled.failed', 'filter' => ['by' => 'task', 'operator' => 'equals', 'values' => ['backup:run']]]))->toBe([]);
});

it('finds tasks more than a minute past their next run, and not tasks gone for a day', function () {
    $now = now()->getTimestamp();

    app(Storage::class)->store([], [
        ['timestamp' => $now - 3_600, 'type' => 'scheduled_task', 'key' => 'reports:send', 'value' => (string) json_encode(['next_at' => $now - 300])],
        ['timestamp' => $now, 'type' => 'scheduled_task', 'key' => 'inspire', 'value' => (string) json_encode(['next_at' => $now + 30])],
        ['timestamp' => $now - 3_600, 'type' => 'scheduled_task', 'key' => 'just:due', 'value' => (string) json_encode(['next_at' => $now - 30])],
        ['timestamp' => $now - 200_000, 'type' => 'scheduled_task', 'key' => 'old:task', 'value' => (string) json_encode(['next_at' => $now - 190_000])],
    ]);

    expect(array_column(alertEvents(['metric' => 'scheduled.overdue']), 'id'))->toBe(['reports:send:'.($now - 300)]);
});

it('finds database incidents of the chosen kinds, each by its id', function () {
    $now = now()->getTimestamp();
    Laralyze::record('db_incident', Errors::key('mysql', 'deadlock'))->count();
    Laralyze::record('db_incident', Errors::key('mysql', 'long_running'))->count();
    Laralyze::flush();

    app(Storage::class)->store([], [
        ['timestamp' => $now, 'type' => 'db_incident', 'key' => "mysql|{$now}:deadlock", 'value' => (string) json_encode(['connection' => 'mysql', 'kind' => 'deadlock', 'summary' => 'Two transactions waited on each other.'])],
        ['timestamp' => $now, 'type' => 'db_incident', 'key' => "mysql|{$now}:long", 'value' => (string) json_encode(['connection' => 'mysql', 'kind' => 'long_running', 'summary' => 'A query ran for 40s.'])],
        ['timestamp' => $now - 7_200, 'type' => 'db_incident', 'key' => 'mysql|'.($now - 7_200).':deadlock', 'value' => (string) json_encode(['connection' => 'mysql', 'kind' => 'deadlock', 'summary' => 'An old one.'])],
    ]);

    $events = alertEvents(['metric' => 'database.problem', 'filter' => ['by' => 'kind', 'operator' => 'in', 'values' => DatabaseProblems::DEFAULT_KINDS]]);

    expect(array_column($events, 'item'))->toBe(['mysql · Deadlock'])
        ->and($events[0]['id'])->toBe("mysql|{$now}:deadlock")
        ->and(alertEvents(['metric' => 'database.problem']))->toHaveCount(2);
});
