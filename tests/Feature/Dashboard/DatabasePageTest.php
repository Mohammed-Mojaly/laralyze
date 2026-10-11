<?php

use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Database\Check;
use MohammedMojaly\Laralyze\Database\Errors;
use MohammedMojaly\Laralyze\Facades\Laralyze;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

function databaseCard(string $name): Testable
{
    return Livewire::withoutLazyLoading()->test("laralyze.{$name}");
}

/**
 * @param  list<array<string, mixed>>  $checks
 */
function lookedAt(string $connection, array $checks, array $size = [], ?int $startedAt = null): void
{
    Laralyze::set('database', $connection, json_encode(['at' => time(), 'engine' => 'MySQL 8.4.2', 'started_at' => $startedAt ?? time() - 12 * 86_400, 'checks' => $checks]));

    if ($size !== []) {
        Laralyze::set('database_size', $connection, json_encode(['at' => time(), 'count' => 3, 'ids' => [], 'history' => [], ...$size]));
    }

    Laralyze::flush();
}

it('waits for the scheduler before it has anything to show', function () {
    $this->get('/laralyze/database')->assertOk()->assertSee('Database');

    databaseCard('database-now')->assertSee('Laralyze hasn\'t looked at this database yet.');
    databaseCard('database-size')->assertSee('Not measured yet.');
    databaseCard('database-trends')->assertSee('No deadlocks, lock timeouts, refused or lost connections');
});

it('shows each check with what to do about it, and what a missing privilege hides', function () {
    lookedAt('testing', [
        Check::make('connections', 'Connections', Check::PROBLEM, '93 / 100', 'Nearly every connection is in use.', 93.0),
        Check::make('long_running', 'Long-running queries', Check::WARNING, '1 over 30 s', 'Check what they wait for.', rows: [
            ['id' => 41, 'user' => 'app', 'seconds' => 45, 'query' => 'select * from orders where id = ?'],
        ]),
        Check::needs('blocking', 'PROCESS'),
        Check::needs('open_transactions', 'PROCESS'),
    ]);

    databaseCard('database-now')
        ->assertSee('MySQL 8.4.2')
        ->assertSeeInOrder(['Problem', 'Connections', '93 / 100', 'Nearly every connection is in use.'])
        ->assertSeeInOrder(['Warning', 'Long-running queries', 'select * from orders where id = ?'])
        ->assertSee('2 checks need the PROCESS privilege.')
        ->assertDontSee('Restarted');

    databaseCard('attention')->assertSee('Database testing: connections, 93 / 100.');
});

it('says when the database restarted', function () {
    lookedAt('testing', [], startedAt: time() - 600);

    databaseCard('database-now')->assertSeeInOrder(['Warning', 'Restarted', 'If nobody restarted it']);
});

it('shows the size, how fast it grows and ids running out', function () {
    lookedAt('testing', [], [
        'total' => 50 * 1_048_576,
        'tables' => [['name' => 'orders', 'size' => 40 * 1_048_576, 'own' => false], ['name' => 'laralyze_aggregates', 'size' => 10 * 1_048_576, 'own' => true]],
        'ids' => [['table' => 'orders', 'column' => 'id', 'type' => 'int', 'used' => 1_900_000_000.0, 'max' => 2_147_483_647.0, 'percent' => 88.5, 'status' => Check::WARNING]],
        'history' => [
            ['day' => now()->subDays(2)->toDateString(), 'total' => 46 * 1_048_576, 'tables' => ['orders' => 36 * 1_048_576]],
            ['day' => now()->toDateString(), 'total' => 50 * 1_048_576, 'tables' => ['orders' => 40 * 1_048_576]],
        ],
    ]);

    databaseCard('database-size')
        ->assertSee('50 MB')
        ->assertSee('+2 MB a day over the last 2 days')
        ->assertSeeInOrder(['Largest tables', 'orders', 'laralyze_aggregates', 'Laralyze'])
        ->assertSeeInOrder(['Fastest growing', 'orders', '+2 MB'])
        ->assertSeeInOrder(['Ids running out', '89%', 'orders.id', 'Change the column to a larger type']);

    databaseCard('attention')->assertSee('Database testing: orders.id has used 89% of its ids.');
});

it('counts database errors by kind over the period', function () {
    Laralyze::record('db_error', Errors::key('testing', 'deadlock'))->count();
    Laralyze::record('db_error', Errors::key('testing', 'deadlock'))->count();
    Laralyze::record('db_error', Errors::key('reports', 'lock_timeout'))->count();
    Laralyze::record('db_connections', 'testing', 40)->avg()->max();
    Laralyze::flush();

    databaseCard('database-trends')
        ->assertSeeInOrder(['40%', 'of connections in use at most'])
        ->assertSeeInOrder(['Deadlocks', '2', 'Lock timeouts', '0']);
});

it('shows one connection at a time, picked in the top bar', function () {
    lookedAt('testing', [Check::make('connections', 'Connections', Check::OK, '5 / 100', percent: 5.0)]);
    lookedAt('reports', [Check::make('connections', 'Connections', Check::OK, '61 / 200', percent: 30.5)]);

    $this->get('/laralyze/database')->assertOk()->assertSee('class="lz-dropdown lz-connections"', false)->assertDontSee('>All</button>', false);

    databaseCard('database-now')->assertSee('5 / 100')
        ->dispatch('laralyze-connection', connection: 'reports')->assertSee('61 / 200')->assertDontSee('5 / 100');
});
