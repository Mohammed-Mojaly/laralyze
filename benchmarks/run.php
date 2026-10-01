<?php

/*
 * composer bench                 Print the comparison table.
 * composer bench -- --save       Also write benchmarks/baseline.json.
 * composer bench -- --compare    Exit 1 if Laralyze breaks the performance budget.
 */

use Composer\InstalledVersions;
use MohammedMojaly\Laralyze\Benchmarks\Benchmark;

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', ['save', 'compare', 'iterations:', 'soak:']);

// Budget from docs/architecture.md §9.
const MAX_P50_OVERHEAD_US = 500;
const MAX_P50_OVERHEAD_RATIO = 0.01;
const MAX_MEMORY_GROWTH_KB = 1_024;

// Stress tests, reported but not gated: their cost is mostly Laravel's event
// dispatcher, and shared CI runners vary too much for a fixed limit.
const INFORMATIONAL = ['queries_1000', 'cache_heavy', 'record_1000'];

$benchmark = new Benchmark(
    iterations: (int) ($options['iterations'] ?? 1_000),
    soakRequests: (int) ($options['soak'] ?? 10_000),
);

['without_laralyze' => $without, 'with_laralyze' => $with, 'overhead' => $overheads] = $benchmark->run();

$failures = [];

printf("%-14s %12s %12s %12s %12s %10s %12s\n", 'scenario', 'p50 base', 'p50 laralyze', 'p95 base', 'p95 laralyze', 'Δ p50', 'Δ memory');

foreach (array_keys(Benchmark::SCENARIOS) as $scenario) {
    $base = $without[$scenario];
    $laralyze = $with[$scenario];

    $overhead = $overheads[$scenario];
    $memory = $laralyze['memory_growth_kb'] - $base['memory_growth_kb'];

    printf(
        "%-14s %10.1fµs %10.1fµs %10.1fµs %10.1fµs %8.1fµs %10.1fKB\n",
        $scenario, $base['p50_us'], $laralyze['p50_us'], $base['p95_us'], $laralyze['p95_us'], $overhead, $memory,
    );

    $budget = max(MAX_P50_OVERHEAD_US, $base['p50_us'] * MAX_P50_OVERHEAD_RATIO);

    if (! in_array($scenario, INFORMATIONAL, true) && $overhead > $budget) {
        $failures[] = "{$scenario}: Laralyze adds {$overhead}µs at p50";
    }

    if ($memory > MAX_MEMORY_GROWTH_KB) {
        $failures[] = "{$scenario}: memory grows {$memory}KB more with Laralyze";
    }
}

if (isset($options['save'])) {
    file_put_contents(__DIR__.'/baseline.json', json_encode([
        'recorded_at' => date(DATE_ATOM),
        'php' => PHP_VERSION,
        'laravel' => InstalledVersions::getPrettyVersion('laravel/framework'),
        'os' => PHP_OS_FAMILY,
        'without_laralyze' => $without,
        'with_laralyze' => $with,
        'overhead_us' => $overheads,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    echo PHP_EOL.'Saved benchmarks/baseline.json'.PHP_EOL;
}

if (isset($options['compare']) && $failures !== []) {
    fwrite(STDERR, PHP_EOL.'Performance budget exceeded:'.PHP_EOL.' - '.implode(PHP_EOL.' - ', $failures).PHP_EOL);

    exit(1);
}
