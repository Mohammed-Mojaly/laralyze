<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Sleep;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Health;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Metrics\Period;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;
use MohammedMojaly\Laralyze\Support\Outage;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
    Sleep::fake();
});

function deadlock(string $sql = 'insert into "laralyze_aggregates"'): QueryException
{
    return new QueryException('mysql', $sql, [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
}

/**
 * Laralyze's storage, failing every write with the given exception.
 */
function failingWrites(Throwable $e): void
{
    $storage = Mockery::mock(Storage::class);
    $storage->shouldReceive('inTransaction')->andReturnFalse();
    $storage->shouldReceive('installed')->andReturnTrue();
    $storage->shouldReceive('store')->andThrow($e);

    app()->instance(Storage::class, $storage);
}

function workingWrites(): void
{
    app()->forgetInstance(Storage::class);
    app()->forgetInstance(Health::class);
}

/**
 * Fail the next statements that write Laralyze's aggregates, before they run.
 *
 * @return Closure(): int how many aggregate statements were attempted
 */
function deadlockAggregateWrites(int $times, ?Throwable $with = null): Closure
{
    $attempts = 0;

    // What the test's own setup recorded goes first.
    Laralyze::flush();

    app(DatabaseStorage::class)->connection()->beforeExecuting(function (string $query) use (&$attempts, &$times, $with) {
        // Upserts are inserts, or a MERGE on SQL Server.
        if (! str_contains($query, 'laralyze_aggregates') || ! preg_match('/^\s*(insert|merge)\b/i', $query)) {
            return;
        }

        $attempts++;

        if ($times-- > 0) {
            throw $with ?? deadlock($query);
        }
    });

    return function () use (&$attempts) {
        return $attempts;
    };
}

function minuteValue(string $type, string $aggregate = 'sum'): float
{
    return (float) laralyzeRows('laralyze_aggregates')->where('type', $type)->where('aggregate', $aggregate)->where('period', Period::MINUTE)->value('value');
}

it('does not pause recording when a write loses a deadlock', function () {
    $reported = [];
    Laralyze::handleExceptionsUsing(function (Throwable $e) use (&$reported) {
        $reported[] = $e;
    });

    failingWrites(deadlock());
    Laralyze::record('checkout', 'pro')->count();
    Laralyze::flush();

    expect(Outage::active())->toBeFalse()
        ->and(Laralyze::lastFailure())->toBeNull()
        ->and(Laralyze::contention())->toBe(1)
        ->and($reported)->toHaveCount(1);

    workingWrites();
    Laralyze::record('checkout', 'pro')->count();
    Laralyze::flush();

    expect(laralyzeRows('laralyze_aggregates')->where('type', 'checkout')->where('period', Period::MINUTE)->count())->toBe(1);
});

it('treats racing inserts as contention too', function () {
    failingWrites(new UniqueConstraintViolationException('sqlsrv', 'merge [laralyze_aggregates]', [], new PDOException('Violation of UNIQUE KEY constraint')));
    Laralyze::record('checkout', 'pro')->count();
    Laralyze::flush();

    expect(Outage::active())->toBeFalse()
        ->and(Laralyze::contention())->toBe(1);
});

it('still pauses recording for any other failure', function () {
    failingWrites(new RuntimeException('Connection refused'));
    Laralyze::record('checkout', 'pro')->count();
    Laralyze::flush();

    expect(Outage::active())->toBeTrue()
        ->and(Laralyze::lastFailure()['message'] ?? null)->toBe('Connection refused')
        ->and(Laralyze::contention())->toBe(0);
});

it('counts contention for an hour', function () {
    failingWrites(deadlock());

    foreach (range(1, 3) as $i) {
        Laralyze::record('checkout', 'pro')->count();
        Laralyze::flush();
    }

    expect(Laralyze::contention())->toBe(3);

    $this->travel(61)->minutes();

    expect(Laralyze::contention())->toBe(0);
});

it('warns about contention on the dashboard, softer than a failure', function () {
    failingWrites(deadlock());
    Laralyze::record('checkout', 'pro')->count();
    Laralyze::flush();
    workingWrites();

    expect(app(Health::class)->problems())->toHaveCount(1)
        ->and(app(Health::class)->problems()[0]['level'])->toBe('warn');

    $this->get('/laralyze')
        ->assertOk()
        ->assertSee('1 write failed because of lock contention in the last hour.')
        ->assertSee('ClickHouse')
        ->assertDontSee("Laralyze couldn't save data");
});

it('retries a deadlocked statement on its own, without counting twice', function () {
    $attempts = deadlockAggregateWrites(2);

    Laralyze::record('checkout', 'pro', 2)->sum();
    Laralyze::flush();
    Laralyze::record('checkout', 'pro', 3)->sum();
    Laralyze::flush();

    expect(minuteValue('checkout'))->toBe(5.0)
        ->and($attempts())->toBe(4)
        ->and(Laralyze::contention())->toBe(0)
        ->and(Outage::active())->toBeFalse();

    Sleep::assertSleptTimes(2);
})->skip(usingClickHouse(), 'ClickHouse has no locks to wait for.');

it('gives a statement five attempts, then counts the write as contention', function () {
    $attempts = deadlockAggregateWrites(PHP_INT_MAX);

    Laralyze::record('checkout', 'pro', 2)->sum();
    Laralyze::flush();

    expect($attempts())->toBe(5)
        ->and(Laralyze::contention())->toBe(1)
        ->and(Outage::active())->toBeFalse()
        ->and(laralyzeRows('laralyze_aggregates')->where('type', 'checkout')->count())->toBe(0);
})->skip(usingClickHouse(), 'ClickHouse has no locks to wait for.');

it('does not retry errors that are not contention', function () {
    $attempts = deadlockAggregateWrites(1, new QueryException('mysql', 'insert', [], new PDOException('SQLSTATE[42S22]: Column not found')));

    Laralyze::record('checkout', 'pro', 2)->sum();
    Laralyze::flush();

    expect($attempts())->toBe(1)
        ->and(Outage::active())->toBeTrue();

    Sleep::assertNeverSlept();
})->skip(usingClickHouse(), 'ClickHouse has no locks to wait for.');

it('writes each statement outside a transaction, so its locks go at once', function () {
    $levels = [];

    app(DatabaseStorage::class)->connection()->beforeExecuting(function (string $query, array $bindings, $connection) use (&$levels) {
        if (str_contains($query, 'laralyze_')) {
            $levels[] = $connection->transactionLevel();
        }
    });

    Laralyze::record('checkout', 'pro', 2)->sum()->max();
    Laralyze::set('plan', 'pro', 'yearly');
    Laralyze::flush();

    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([0]);
})->skip(usingClickHouse(), 'ClickHouse has no transactions.');
