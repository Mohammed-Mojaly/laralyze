<?php

namespace Laralyze\Benchmarks;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laralyze\Laralyze;
use Laralyze\LaralyzeServiceProvider;
use Orchestra\Testbench\Foundation\Application as Testbench;

/**
 * Pushes requests through real HTTP kernels, one app with Laralyze and one
 * without, in a single long-lived process (like an Octane worker).
 *
 * The two apps run in alternating batches so that CPU noise and thermal
 * drift hit both equally, instead of skewing whichever ran second.
 */
class Benchmark
{
    /**
     * Scenario => cost weight. Heavier scenarios run fewer requests so the
     * whole suite stays around a minute.
     */
    public const SCENARIOS = [
        'trivial' => 1,
        'queries_50' => 5,
        'queries_1000' => 50,
        'cache_heavy' => 5,
        'http_client' => 2,
        'record_1000' => 5,
    ];

    protected const BATCH = 25;

    public function __construct(
        protected int $iterations = 1_000,
        protected int $soakRequests = 10_000,
    ) {}

    /**
     * @return array{without_laralyze: array<string, array<string, float>>, with_laralyze: array<string, array<string, float>>, overhead: array<string, float>}
     */
    public function run(): array
    {
        $apps = [
            'without_laralyze' => $this->createApplication(withLaralyze: false),
            'with_laralyze' => $this->createApplication(withLaralyze: true),
        ];

        $results = ['without_laralyze' => [], 'with_laralyze' => [], 'overhead' => []];

        foreach (self::SCENARIOS as $scenario => $weight) {
            $samples = $this->measureInterleaved($apps, $scenario, max(100, intdiv($this->iterations, $weight)));

            foreach ($apps as $mode => $app) {
                $results[$mode][$scenario] = [
                    ...$this->summarize($samples[$mode]['handle']),
                    'terminate_mean_us' => round(array_sum($samples[$mode]['terminate']) / count($samples[$mode]['terminate']), 1),
                    'memory_growth_kb' => $this->memoryGrowth($app, $scenario, intdiv($this->soakRequests, $weight)),
                ];
            }

            $results['overhead'][$scenario] = $this->pairedOverhead($samples['without_laralyze']['rounds'], $samples['with_laralyze']['rounds']);
        }

        return $results;
    }

    protected function createApplication(bool $withLaralyze): Application
    {
        $app = Testbench::create(options: [
            'extra' => ['providers' => $withLaralyze ? [LaralyzeServiceProvider::class] : []],
        ]);

        $this->activate($app);

        $app['config']->set([
            'app.debug' => false,
            'database.default' => 'bench',
            'database.connections.bench' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
        ]);

        Schema::create('items', function ($table) {
            $table->id();
            $table->string('name');
        });

        DB::table('items')->insert(array_map(fn ($i) => ['name' => "item {$i}"], range(1, 100)));

        if ($withLaralyze) {
            (require __DIR__.'/../database/migrations/2026_09_30_000000_create_laralyze_tables.php')->up();
        }

        // Http::fake() keeps every request in memory, which would look like a
        // leak. A short-circuiting middleware answers without recording.
        Http::globalMiddleware(fn () => fn () => Create::promiseFor(new Response(200, [], 'ok')));

        $this->registerRoutes();

        return $app;
    }

    protected function registerRoutes(): void
    {
        Route::get('/bench/trivial', fn () => 'ok');

        Route::get('/bench/queries_50', fn () => $this->runQueries(50));

        Route::get('/bench/queries_1000', fn () => $this->runQueries(1_000));

        Route::get('/bench/cache_heavy', function () {
            for ($i = 0; $i < 50; $i++) {
                Cache::put("key.{$i}", $i, 60);
                Cache::get("key.{$i}");
                Cache::get("missing.{$i}");
                Cache::forget("key.{$i}");
            }

            return 'ok';
        });

        // Without Laralyze this route does nothing, so the difference is the cost of recording.
        Route::get('/bench/record_1000', function () {
            if (app()->bound(Laralyze::class)) {
                $laralyze = app(Laralyze::class);

                for ($i = 0; $i < 1_000; $i++) {
                    $laralyze->record('bench', 'key '.($i % 50), $i)->count()->max()->histogram();
                }
            }

            return 'ok';
        });

        Route::get('/bench/http_client', function () {
            for ($i = 0; $i < 5; $i++) {
                Http::get("https://api.example.test/items/{$i}");
            }

            return 'ok';
        });
    }

    protected function runQueries(int $count): string
    {
        for ($i = 1; $i <= $count; $i++) {
            DB::table('items')->where('id', ($i % 100) + 1)->first();
        }

        return 'ok';
    }

    /**
     * Point facades and the global container at the given app, since both
     * apps live in the same process.
     */
    protected function activate(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    /**
     * @param  array<string, Application>  $apps
     * @return array<string, array{handle: list<float>, terminate: list<float>, rounds: list<float>}>
     */
    protected function measureInterleaved(array $apps, string $scenario, int $iterations): array
    {
        $handle = $terminate = $rounds = array_map(fn () => [], $apps);

        foreach ($apps as $app) {
            $this->activate($app);
            $this->hit($app, $scenario, self::BATCH);
        }

        for ($round = 0; $round * self::BATCH < $iterations; $round++) {
            // Swap the order every round so neither app always goes first.
            $order = $round % 2 === 0 ? array_keys($apps) : array_reverse(array_keys($apps));

            foreach ($order as $mode) {
                $this->activate($apps[$mode]);
                $kernel = $apps[$mode]->make(Kernel::class);

                $batch = [];

                for ($i = 0; $i < self::BATCH; $i++) {
                    [$handled, $terminated] = $this->timeRequest($kernel, $scenario);

                    $batch[] = $handled;
                    $terminate[$mode][] = $terminated;
                }

                array_push($handle[$mode], ...$batch);
                $rounds[$mode][] = $this->median($batch);
            }
        }

        return array_map(
            fn (string $mode) => ['handle' => $handle[$mode], 'terminate' => $terminate[$mode], 'rounds' => $rounds[$mode]],
            array_combine(array_keys($apps), array_keys($apps)),
        );
    }

    /**
     * @return array{0: float, 1: float} Microseconds spent in handle() and terminate().
     */
    protected function timeRequest(Kernel $kernel, string $scenario): array
    {
        $request = Request::create("/bench/{$scenario}");

        $start = hrtime(true);
        $response = $kernel->handle($request);
        $handled = hrtime(true);
        $kernel->terminate($request, $response);
        $terminated = hrtime(true);

        return [($handled - $start) / 1_000, ($terminated - $handled) / 1_000];
    }

    protected function hit(Application $app, string $scenario, int $times): void
    {
        $kernel = $app->make(Kernel::class);

        for ($i = 0; $i < $times; $i++) {
            $this->timeRequest($kernel, $scenario);
        }
    }

    protected function memoryGrowth(Application $app, string $scenario, int $requests): float
    {
        $this->activate($app);

        gc_collect_cycles();
        $before = memory_get_usage();

        $this->hit($app, $scenario, $requests);

        gc_collect_cycles();

        return round((memory_get_usage() - $before) / 1024, 1);
    }

    /**
     * @param  list<float>  $samples
     * @return array{p50_us: float, p95_us: float, mean_us: float}
     */
    protected function summarize(array $samples): array
    {
        sort($samples);

        return [
            'p50_us' => round($this->percentile($samples, 0.50), 1),
            'p95_us' => round($this->percentile($samples, 0.95), 1),
            'mean_us' => round(array_sum($samples) / count($samples), 1),
        ];
    }

    /**
     * The typical cost Laralyze adds: the median of per-round differences.
     * Comparing rounds that ran back to back cancels most machine noise.
     *
     * @param  list<float>  $without
     * @param  list<float>  $with
     */
    protected function pairedOverhead(array $without, array $with): float
    {
        return round($this->median(array_map(fn (float $a, float $b) => $b - $a, $without, $with)), 1);
    }

    /**
     * @param  list<float>  $samples
     */
    protected function median(array $samples): float
    {
        sort($samples);

        return $this->percentile($samples, 0.5);
    }

    /**
     * @param  list<float>  $sorted
     */
    protected function percentile(array $sorted, float $percentile): float
    {
        return $sorted[(int) floor($percentile * (count($sorted) - 1))];
    }
}
