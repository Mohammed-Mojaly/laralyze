<?php

use MohammedMojaly\Laralyze\Alerts\Metrics\Catalog;
use MohammedMojaly\Laralyze\Facades\Laralyze;

it('looks at 50 rules in under a second', function () {
    app()->detectEnvironment(fn () => 'local');

    foreach (range(1, 200) as $i) {
        Laralyze::record('request', 'GET /route-'.($i % 40), 10 + $i)->avg()->max()->histogram();
        Laralyze::record($i % 10 === 0 ? 'request_5xx' : 'request_2xx', 'GET /route-'.($i % 40))->count();
        Laralyze::record('job', 'App\Jobs\Job'.($i % 20), 50 + $i)->avg()->max()->histogram();
        Laralyze::record('log', $i % 3 === 0 ? 'error' : 'info')->count();
    }
    Laralyze::flush();

    $metrics = array_keys(app(Catalog::class)->all());

    foreach (range(0, 49) as $i) {
        alertRule([
            'name' => "Rule {$i}",
            'metric' => $metrics[$i % count($metrics)],
            'operator' => '>',
            'threshold' => 1_000_000,
            'mode' => $i % 2 === 0 ? 'together' : 'each',
        ]);
    }

    $started = microtime(true);
    look();
    $seconds = microtime(true) - $started;

    expect($seconds)->toBeLessThan(1.0);
});
