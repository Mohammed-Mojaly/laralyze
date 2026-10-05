<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Metrics\Period;

/*
 * Real processes writing the same rows at once. Needs a database server
 * that several processes can share, so it skips on SQLite and ClickHouse.
 */

beforeEach(function () {
    if (usingClickHouse() || ! in_array(getenv('LARALYZE_TEST_DB'), ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
        $this->markTestSkipped('Needs a database server several processes can share.');
    }
});

/**
 * @return list<array{flushes: int, lost: int, contention: int}>
 */
function runFlushers(string $driver, int $timestamp, int $processes = 10, int $flushes = 60): array
{
    $running = array_map(
        fn (int $process) => Process::timeout(300)->start([PHP_BINARY, __DIR__.'/../../Fixtures/flusher.php', $driver, (string) $process, (string) $flushes, (string) $timestamp]),
        range(1, $processes),
    );

    return array_map(function ($process) {
        $result = $process->wait();

        expect($result->successful())->toBeTrue($result->errorOutput().$result->output());

        $decoded = json_decode(trim(last(explode(PHP_EOL, trim($result->output())))), true);

        expect($decoded)->toBeArray('A flusher printed no result: '.$result->output().$result->errorOutput());

        return $decoded;
    }, $running);
}

/**
 * @return array<string, float> aggregate => value, for one key's minute bucket
 */
function soakTotals(int $timestamp): array
{
    return DB::table('laralyze_aggregates')
        ->where('type', 'soak')->where('key', 'key 7')->where('period', Period::MINUTE)->where('bucket', Period::bucket($timestamp, Period::MINUTE))
        ->pluck('value', 'aggregate')->map(fn ($value) => (float) $value)->all();
}

it('loses and double counts nothing when many processes queue writes for the digest', function () {
    config(['laralyze.ingest.driver' => 'database']);
    $timestamp = time();

    $results = runFlushers('database', $timestamp);

    expect(array_sum(array_column($results, 'lost')))->toBe(0)
        ->and(array_sum(array_column($results, 'contention')))->toBe(0)
        ->and(DB::table('laralyze_ingest')->count())->toBe(600)
        ->and(DB::table('laralyze_aggregates')->where('type', 'soak')->count())->toBe(0);

    Laralyze::digest();

    // Process p writes p * 100 + 1 to p * 100 + 60.
    $sum = array_sum(array_map(fn (int $p) => 60 * $p * 100 + 60 * 61 / 2, range(1, 10)));
    $totals = soakTotals($timestamp);
    $histogram = array_sum(array_filter($totals, fn (string $aggregate) => str_starts_with($aggregate, 'h'), ARRAY_FILTER_USE_KEY));

    expect(DB::table('laralyze_ingest')->count())->toBe(0)
        ->and($totals['count'])->toBe(600.0)
        ->and($totals['sum'])->toBe((float) $sum)
        ->and($totals['min'])->toBe(101.0)
        ->and($totals['max'])->toBe(1060.0)
        ->and($histogram)->toBe(600.0)
        ->and(DB::table('laralyze_values')->where('type', 'soak_seen')->count())->toBe(10);
});

it('never counts a write twice when many processes write directly', function () {
    $timestamp = time();

    $results = runFlushers('direct', $timestamp);

    // Busy databases may lose some writes here, all of them reported: that's what the ingest table is for.
    $lost = array_sum(array_column($results, 'lost'));
    $count = soakTotals($timestamp)['count'] ?? 0.0;

    expect($count)->toBeLessThanOrEqual(600.0)
        ->and($count)->toBeGreaterThanOrEqual((float) (600 - $lost));
});
