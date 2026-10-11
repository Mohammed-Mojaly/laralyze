<?php

use MohammedMojaly\Laralyze\Database\Check;
use MohammedMojaly\Laralyze\Database\Incidents;
use MohammedMojaly\Laralyze\Database\Readers\MySql;

/**
 * @param  list<array<string, mixed>>  $waits
 * @return array<string, mixed>
 */
function lookWith(array $waits = [], array $extra = []): array
{
    return [
        'checks' => [Check::make('blocking', 'Blocking', $waits === [] ? Check::OK : Check::PROBLEM, '', rows: $waits)],
        'sessions' => [['id' => 37, 'user' => 'app', 'seconds' => 240, 'state' => 'idle, transaction open', 'query' => '']],
        'deadlocks' => 4,
        'deadlock' => null,
        'started_at' => 1_000,
        ...$extra,
    ];
}

it('turns a blocking session into one incident, kept at its worst, until it ends', function () {
    $wait = fn (int $seconds) => ['waiting' => 41, 'blocking' => 37, 'blocker' => 'app', 'seconds' => $seconds, 'query' => 'update orders set paid = ?'];

    [$open, $save, $new] = Incidents::track([], Incidents::found(lookWith([$wait(40)]), []), 100);

    expect($new)->toHaveCount(1)
        ->and($new[0]['kind'])->toBe('blocking')
        ->and($new[0]['summary'])->toContain('Session 37 (app) blocked 1 session')
        ->and($new[0]['sessions'][0]['id'])->toBe(37);

    // A minute later it's worse: the same incident, at its new worst.
    [$open, $save, $new] = Incidents::track($open, Incidents::found(lookWith([$wait(100)]), ['deadlocks' => 4]), 160);

    expect($new)->toBe([])
        ->and($save[0]['id'])->toBe('100:blocking:37')
        ->and($save[0]['peak'])->toBe(100)
        ->and($save[0]['last_seen'])->toBe(160);

    // Better again: kept at its worst, only seen later.
    [$open, $save] = Incidents::track($open, Incidents::found(lookWith([$wait(20)]), ['deadlocks' => 4]), 220);

    expect($save[0]['peak'])->toBe(100)->and($save[0]['last_seen'])->toBe(220);

    // Gone: over. Back later: a new one.
    [$open, $save] = Incidents::track($open, Incidents::found(lookWith(), ['deadlocks' => 4]), 280);
    expect($open)->toBe([])->and($save)->toBe([]);

    [, , $new] = Incidents::track($open, Incidents::found(lookWith([$wait(40)]), ['deadlocks' => 4]), 400);
    expect($new[0]['id'])->toBe('400:blocking:37');
});

it('notes each new deadlock with its details, or counts them when there are none', function () {
    $deadlock = ['at' => '2026-10-11 01:23:45', 'transactions' => [
        ['number' => 1, 'session' => 12, 'query' => 'update orders set paid = ? where id = ?', 'holds' => 'orders (PRIMARY): X', 'waits' => 'orders (PRIMARY): X', 'rolled_back' => false],
        ['number' => 2, 'session' => 13, 'query' => 'update orders set paid = ? where id = ?', 'holds' => 'orders (PRIMARY): X', 'waits' => 'orders (PRIMARY): X', 'rolled_back' => true],
    ]];

    $found = Incidents::found(lookWith(extra: ['deadlock' => $deadlock, 'deadlocks' => 5]), ['deadlocks' => 4, 'deadlock_at' => '2026-10-10 09:00:00']);

    expect($found)->toHaveKey('deadlock:2026-10-11 01:23:45')
        ->and($found['deadlock:2026-10-11 01:23:45']['summary'])->toBe('Deadlock on orders: transaction 2 was rolled back')
        ->and($found['deadlock:2026-10-11 01:23:45']['deadlock'])->toBe($deadlock);

    // The same one seen again isn't new; without details, the count says how many.
    expect(Incidents::found(lookWith(extra: ['deadlock' => $deadlock]), ['deadlocks' => 4, 'deadlock_at' => '2026-10-11 01:23:45']))->toBe([])
        ->and(Incidents::found(lookWith(extra: ['deadlocks' => 7]), ['deadlocks' => 4])['deadlocks:7']['summary'])->toBe('3 deadlocks');
});

it('says how many deadlocks there were when only the last has details', function () {
    $deadlock = ['at' => '2026-10-11 01:23:45', 'transactions' => [
        ['number' => 1, 'session' => 12, 'query' => 'update orders set paid = ?', 'holds' => 'orders (PRIMARY): X', 'waits' => '', 'rolled_back' => true],
    ]];

    $found = Incidents::found(lookWith(extra: ['deadlock' => $deadlock, 'deadlocks' => 6]), ['deadlocks' => 4, 'deadlock_at' => 'earlier']);

    expect($found['deadlock:2026-10-11 01:23:45']['summary'])->toBe('2 deadlocks, the last on orders: transaction 1 was rolled back');
});

it('keeps the details of only so many incidents a day, and counts the rest', function () {
    $wait = fn (int $blocker) => ['waiting' => 41, 'blocking' => $blocker, 'blocker' => 'app', 'seconds' => 40, 'query' => 'select ?'];

    [$open, $save, $new] = Incidents::track([], Incidents::found(lookWith([$wait(1), $wait(2)]), []), 100, room: 1);

    expect($new)->toHaveCount(2)
        ->and($save)->toHaveCount(1)
        ->and($save[0]['id'])->toBe('100:blocking:1');

    // The one without room stays without details while it lasts.
    [, $save] = Incidents::track($open, Incidents::found(lookWith([$wait(1), $wait(2)]), ['deadlocks' => 4]), 160, room: 5);

    expect(array_column($save, 'id'))->toBe(['100:blocking:1']);
});

it('says nothing about deadlocks and restarts on the first look', function () {
    expect(Incidents::found(lookWith(extra: ['deadlock' => ['at' => 'old', 'transactions' => []], 'deadlocks' => 9]), []))->toBe([]);
});

it('says nothing about a deadlock it has no count to compare with', function () {
    // The first count only notes the last deadlock there was.
    expect(Incidents::found(lookWith(extra: ['deadlock' => ['at' => 'old', 'transactions' => []], 'deadlocks' => 9]), ['started_at' => 1_000]))->toBe([]);
});

it('notes a restart', function () {
    expect(Incidents::found(lookWith(extra: ['started_at' => 5_000]), ['started_at' => 1_000, 'deadlocks' => 4]))
        ->toHaveKey('restart:5000');
});

it('reads the last deadlock from InnoDB\'s status, without the rows', function () {
    $status = <<<'STATUS'
=====================================
2026-10-11 01:30:00 0x7f INNODB MONITOR OUTPUT
=====================================
------------------------
LATEST DETECTED DEADLOCK
------------------------
2026-10-11 01:23:45 140234567
*** (1) TRANSACTION:
TRANSACTION 5678, ACTIVE 5 sec starting index read
mysql tables in use 1, locked 1
LOCK WAIT 3 lock struct(s), heap size 1128, 2 row lock(s)
MySQL thread id 12, OS thread handle 140, query id 99 localhost app updating
update orders set paid = 1 where id = 2
*** (1) HOLDS THE LOCK(S):
RECORD LOCKS space id 5 page no 4 n bits 72 index PRIMARY of table `shop`.`orders` trx id 5678 lock_mode X locks rec but not gap
Record lock, heap no 2 PHYSICAL RECORD: n_fields 5; compact format; info bits 0
 0: len 4; hex 80000001; asc     ;;

*** (1) WAITING FOR THIS LOCK TO BE GRANTED:
RECORD LOCKS space id 5 page no 4 n bits 72 index PRIMARY of table `shop`.`orders` trx id 5678 lock_mode X locks rec but not gap waiting
Record lock, heap no 3 PHYSICAL RECORD: n_fields 5; compact format; info bits 0

*** (2) TRANSACTION:
TRANSACTION 5679, ACTIVE 4 sec starting index read
MySQL thread id 13, OS thread handle 141, query id 100 localhost app updating
update orders set paid = 1 where id = 1
*** (2) HOLDS THE LOCK(S):
RECORD LOCKS space id 5 page no 4 n bits 72 index PRIMARY of table `shop`.`orders` trx id 5679 lock_mode X locks rec but not gap
*** (2) WAITING FOR THIS LOCK TO BE GRANTED:
RECORD LOCKS space id 5 page no 4 n bits 72 index PRIMARY of table `shop`.`orders` trx id 5679 lock_mode X locks rec but not gap waiting
*** WE ROLL BACK TRANSACTION (2)
------------
TRANSACTIONS
------------
Trx id counter 5690
STATUS;

    $deadlock = MySql::deadlock($status);

    expect($deadlock['at'])->toBe('2026-10-11 01:23:45')
        ->and($deadlock['transactions'])->toHaveCount(2)
        ->and($deadlock['transactions'][0])->toMatchArray([
            'number' => 1,
            'session' => 12,
            'query' => 'update orders set paid = ? where id = ?',
            'holds' => 'orders (PRIMARY): X locks rec but not gap',
            'waits' => 'orders (PRIMARY): X locks rec but not gap waiting',
            'rolled_back' => false,
        ])
        ->and($deadlock['transactions'][1]['rolled_back'])->toBeTrue()
        ->and(json_encode($deadlock))->not->toContain('80000001');

    expect(MySql::deadlock("------------\nTRANSACTIONS\n------------\n"))->toBeNull();
});
