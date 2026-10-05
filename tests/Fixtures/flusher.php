<?php

/*
 * One of several processes that record and flush the same metrics at once,
 * for ConcurrencyTest. It reads the same LARALYZE_TEST_DB and DB_* variables
 * as the test suite, and prints what happened as JSON.
 *
 * Usage: php flusher.php <ingest driver> <process number> <flushes> <timestamp>
 */

use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\LaralyzeServiceProvider;
use MohammedMojaly\Laralyze\Support\Outage;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

[, $driver, $process, $flushes, $timestamp] = $argv;

// Processes starting together would race to write the same manifest files.
foreach (['APP_SERVICES_CACHE' => 'services', 'APP_PACKAGES_CACHE' => 'packages'] as $variable => $file) {
    $_ENV[$variable] = $_SERVER[$variable] = "bootstrap/cache/laralyze-flusher-{$process}-{$file}.php";
    putenv("{$variable}={$_ENV[$variable]}");
}

$app = Application::create(options: [
    'load_environment_variables' => false,
    'extra' => ['providers' => [LaralyzeServiceProvider::class], 'dont-discover' => ['*']],
]);

$db = getenv('LARALYZE_TEST_DB');
$app['config']->set('database.default', 'testing');
$app['config']->set('database.connections.testing', [
    'driver' => $db,
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: ['mysql' => 3306, 'mariadb' => 3306, 'pgsql' => 5432, 'sqlsrv' => 1433][$db],
    'database' => getenv('DB_DATABASE') ?: 'laralyze_test',
    'username' => getenv('DB_USERNAME') !== false ? getenv('DB_USERNAME') : ['mysql' => 'root', 'mariadb' => 'root', 'pgsql' => 'postgres', 'sqlsrv' => 'sa'][$db],
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => $db === 'pgsql' ? 'utf8' : 'utf8mb4',
    'prefix' => '',
    'trust_server_certificate' => true,
]);
$app['config']->set('cache.default', 'array');
$app['config']->set('laralyze.ingest.driver', $driver);
$app['config']->set('laralyze.ingest.lottery', [0, 1]);
$app['config']->set('laralyze.trim_lottery', [0, 1]);

$lost = 0;
Laralyze::handleExceptionsUsing(function () use (&$lost) {
    $lost++;
});

for ($flush = 0; $flush < (int) $flushes; $flush++) {
    // A failed write pauses this process; what it records meanwhile is dropped.
    if (Outage::active()) {
        $lost++;
    }

    $value = (int) $process * 100 + $flush + 1;

    foreach (range(1, 20) as $key) {
        Laralyze::record('soak', "key {$key}", $value, (int) $timestamp)->count()->sum()->min()->max()->histogram();
    }

    Laralyze::set('soak_seen', "process {$process}", (string) $flush, (int) $timestamp);
    Laralyze::flush();
}

echo json_encode(['flushes' => (int) $flushes, 'lost' => $lost, 'contention' => Laralyze::contention()]), PHP_EOL;
