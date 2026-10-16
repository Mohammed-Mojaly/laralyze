<?php

namespace MohammedMojaly\Laralyze;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\LivewireManager;
use Throwable;

class LaralyzeServiceProvider extends ServiceProvider
{
    /**
     * Built-in cards, used as <livewire:laralyze.{name} />.
     */
    public const CARDS = [
        'attention' => Cards\Attention::class,
        'request-totals' => Cards\RequestTotals::class,
        'request-duration' => Cards\RequestDuration::class,
        'routes' => Cards\Routes::class,
        'slow-requests' => Cards\SlowRequests::class,
        'queues' => Cards\Queues::class,
        'jobs' => Cards\JobList::class,
        'slow-jobs' => Cards\SlowJobs::class,
        'commands' => Cards\CommandList::class,
        'scheduled-tasks' => Cards\ScheduledTaskList::class,
        'exceptions' => Cards\ExceptionList::class,
        'exception' => Cards\ExceptionDetail::class,
        'query-totals' => Cards\QueryTotals::class,
        'queries' => Cards\QueryList::class,
        'slow-queries' => Cards\SlowQueries::class,
        'database-now' => Cards\DatabaseNow::class,
        'database-incidents' => Cards\DatabaseIncidents::class,
        'database-trends' => Cards\DatabaseTrends::class,
        'database-size' => Cards\DatabaseSize::class,
        'cache' => Cards\CacheTotals::class,
        'cache-keys' => Cards\CacheKeys::class,
        'outgoing-requests' => Cards\OutgoingRequests::class,
        'ai-totals' => Cards\AiTotals::class,
        'ai-agents' => Cards\AiAgents::class,
        'ai-models' => Cards\AiModels::class,
        'ai-users' => Cards\AiUsers::class,
        'mail' => Cards\MailList::class,
        'notifications' => Cards\NotificationList::class,
        'logs' => Cards\LogLevels::class,
        'log-list' => Cards\LogList::class,
        'visits' => Cards\VisitTotals::class,
        'audience' => Cards\Audience::class,
        'top-pages' => Cards\TopPages::class,
        'bots' => Cards\Bots::class,
        'user-totals' => Cards\UserTotals::class,
        'users' => Cards\UserList::class,
        'servers' => Cards\ServerList::class,
        'group' => Cards\Group::class,
        'executions' => Cards\ExecutionList::class,
        'findings' => Cards\Findings::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laralyze.php', 'laralyze');
        $this->addNewRecorders();
        $this->addNewAlertKeys();

        $this->app->singleton(Laralyze::class);
        $this->app->singleton(Storage\DatabaseStorage::class);
        $this->app->singleton(Storage\ClickHouseStorage::class, fn ($app) => new Storage\ClickHouseStorage(
            Storage\ClickHouse\Client::fromConfig((array) $app['config']->get('laralyze.storage.clickhouse', [])),
        ));
        // Old published configs have no driver key: they keep the database.
        $this->app->singleton(Contracts\Storage::class, fn ($app) => $app['config']->get('laralyze.storage.driver', 'database') === 'clickhouse'
            ? $app->make(Storage\ClickHouseStorage::class)
            : $app->make(Storage\DatabaseStorage::class));
        $this->app->singleton(Contracts\Ingest::class, fn ($app) => Ingest\Drivers::resolve($app));
        $this->app->singleton(Dashboard\Assets::class);
        $this->app->singleton(Support\AiPrices::class);
        $this->app->scoped(Dashboard\Pages::class);
    }

    /**
     * A config published before a recorder existed doesn't list it. New
     * recorders start on, like they would in a fresh install; turn one
     * off with 'enabled' => false.
     */
    protected function addNewRecorders(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $recorders = (array) $config->get('laralyze.recorders', []);
        $defaults = (array) ((require __DIR__.'/../config/laralyze.php')['recorders'] ?? []);

        $config->set('laralyze.recorders', [...$recorders, ...array_diff_key($defaults, $recorders)]);
    }

    /**
     * A config published before 0.8 has its own alerts array without the
     * Telegram, webhook and heartbeat keys, so their .env keys would do
     * nothing. Missing keys are added; a key the app set is kept.
     */
    protected function addNewAlertKeys(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $alerts = (array) $config->get('laralyze.alerts', []);
        $defaults = (array) ((require __DIR__.'/../config/laralyze.php')['alerts'] ?? []);

        foreach (['telegram', 'webhook'] as $key) {
            if (is_array($alerts[$key] ?? null)) {
                $alerts[$key] = [...$alerts[$key], ...array_diff_key((array) $defaults[$key], $alerts[$key])];
            }
        }

        $config->set('laralyze.alerts', [...$alerts, ...array_diff_key($defaults, $alerts)]);
    }

    public function boot(): void
    {
        $this->defineGate();
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laralyze');

        if ($this->app->make(Laralyze::class)->isEnabled()) {
            $this->app->make(Recorders\RecorderManager::class)->register($this->app->make('config')->array('laralyze.recorders', []));

            $this->flushAfterEachExecution();
            $this->scheduleMaintenance();
            $this->registerDashboard();
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerMigrations();
            $this->registerAboutSection();

            $this->commands([
                Console\InstallCommand::class,
                Console\MakeCardCommand::class,
                Console\AiPricesCommand::class,
            ]);
        }
    }

    protected function registerDashboard(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::group([
                'domain' => $this->app->make('config')->get('laralyze.domain'),
                'prefix' => $this->app->make('config')->get('laralyze.path', 'laralyze'),
                'middleware' => $this->app->make('config')->get('laralyze.middleware', []),
            ], __DIR__.'/../routes/web.php');
        }

        $this->callAfterResolving('livewire', function (LivewireManager $livewire) {
            $config = $this->app->make('config');

            // Livewire updates run the same checks as the page itself. Livewire
            // compares class names, so aliases are resolved; groups are left
            // out, since the list applies to every Livewire update in the app.
            $this->app->booted(function () use ($livewire, $config) {
                $router = $this->app->make('router');

                $livewire->addPersistentMiddleware(collect((array) $config->get('laralyze.middleware', []))
                    ->map(fn ($middleware) => is_string($middleware) ? Str::before($middleware, ':') : $middleware)
                    ->reject(fn ($middleware) => is_string($middleware) && $router->hasMiddlewareGroup($middleware))
                    ->map(fn ($middleware) => is_string($middleware) ? ($router->getMiddleware()[$middleware] ?? $middleware) : $middleware)
                    ->values()
                    ->all());
            });

            foreach (self::CARDS as $name => $class) {
                $livewire->component("laralyze.{$name}", $config->get("laralyze.cards.{$name}", $class));
            }

            $livewire->component('laralyze.assistant', Livewire\AssistantPanel::class);
            $livewire->component('laralyze.alerts', Livewire\AlertsPage::class);
            $livewire->component('laralyze.alert-bell', Livewire\AlertBell::class);
        });
    }

    /**
     * Write what was recorded once the work is done: after the response is
     * sent, after a command or job finishes, or when a worker loops.
     */
    protected function flushAfterEachExecution(): void
    {
        $flush = fn () => $this->app->make(Laralyze::class)->flush();

        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel) use ($flush) {
            if (method_exists($kernel, 'whenRequestLifecycleIsLongerThan')) {
                $kernel->whenRequestLifecycleIsLongerThan(-1, $flush);
            }
        });

        $this->callAfterResolving(ConsoleKernel::class, function (ConsoleKernel $kernel) use ($flush) {
            if (method_exists($kernel, 'whenCommandLifecycleIsLongerThan')) {
                $kernel->whenCommandLifecycleIsLongerThan(-1, $flush);
            }
        });

        $this->callAfterResolving(Dispatcher::class, function (Dispatcher $events) use ($flush) {
            $events->listen([
                JobProcessed::class,
                JobFailed::class,
                JobReleasedAfterException::class,
                Looping::class,
                WorkerStopping::class,
                ScheduledTaskFinished::class,
                ScheduledTaskFailed::class,
                ScheduledTaskSkipped::class,
            ], $flush);

            // Octane reuses the process, so nothing may leak into the next request.
            $events->listen('Laravel\Octane\Events\RequestReceived', fn () => $this->app->make(Laralyze::class)->reset());
        });
    }

    protected function scheduleMaintenance(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(fn () => $this->app->make(Laralyze::class)->trim())
                ->hourly()
                ->name('laralyze:trim');

            if (Ingest\Drivers::name($this->app) === 'database') {
                $schedule->call(fn () => $this->app->make(Laralyze::class)->digest())
                    ->everyMinute()
                    ->name('laralyze:digest')
                    // A digest takes under a minute; a scheduler killed during one frees it in five.
                    ->withoutOverlapping(5);
            }

            // Rules, history and the bell work without a channel, so the check always runs.
            $alerts = $schedule->call(fn () => $this->app->make(Alerts\Alerts::class)->run())
                ->everyMinute()
                ->name('laralyze:alerts');

            // Every server may run the scheduler; one checks and sends. It needs a cache that can lock.
            if ($this->app->make('cache')->store()->getStore() instanceof LockProvider) {
                // A slow look must never overlap the next and re-send.
                $alerts->onOneServer()->withoutOverlapping(5);
            }
        });
    }

    /**
     * Local-only by default. App providers boot after package providers,
     * so defining the gate in the app's own provider overrides this one.
     */
    protected function defineGate(): void
    {
        Gate::define('viewLaralyze', fn ($user = null) => $this->app->environment('local'));

        // Ask AI reads your code and sends it to your AI provider: its own
        // permission, given by default to whoever may see the dashboard.
        Gate::define('useLaralyzeAssistant', fn ($user = null) => ! in_array(Http\Middleware\Authorize::class, (array) $this->app->make('config')->get('laralyze.middleware', []), true)
            || Gate::forUser($user)->allows('viewLaralyze'));

        // Making, changing, muting and test-sending alert rules; seeing them needs only viewLaralyze.
        Gate::define('manageLaralyzeAlerts', fn ($user = null) => ! in_array(Http\Middleware\Authorize::class, (array) $this->app->make('config')->get('laralyze.middleware', []), true)
            || Gate::forUser($user)->allows('viewLaralyze'));
    }

    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/laralyze.php' => config_path('laralyze.php'),
        ], 'laralyze-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'laralyze-migrations');

        $this->publishes([
            __DIR__.'/../resources/views/components' => resource_path('views/vendor/laralyze/components'),
            __DIR__.'/../resources/views/pages' => resource_path('views/vendor/laralyze/pages'),
        ], 'laralyze-views');

        $this->publishes([
            __DIR__.'/../resources/views/cards' => resource_path('views/vendor/laralyze/cards'),
        ], 'laralyze-cards');
    }

    /**
     * php artisan migrate runs Laralyze's migrations from the package, so an
     * upgrade needs nothing more. A migration an older install copied into
     * the app runs from there instead, never twice. On ClickHouse the app's
     * database gets no tables: Laralyze makes its own there after migrate.
     */
    protected function registerMigrations(): void
    {
        $this->callAfterResolving('migrator', function (Migrator $migrator) {
            if ($this->usesClickHouse()) {
                return;
            }

            foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $migration) {
                $name = (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($migration));

                if ((glob(database_path('migrations/*_'.$name)) ?: []) === []) {
                    $migrator->path($migration);
                }
            }
        });

        $this->app->make(Dispatcher::class)->listen(CommandFinished::class, function (CommandFinished $event) {
            if ($event->exitCode !== 0 || ! in_array($event->command, ['migrate', 'migrate:fresh', 'migrate:refresh'], true) || ! $this->usesClickHouse()) {
                return;
            }

            try {
                $storage = $this->app->make(Storage\ClickHouseStorage::class);
                $storage->install();

                $event->output->writeln("  Laralyze's tables are ready in ClickHouse at {$storage->client()->url()}.");
            } catch (Throwable $e) {
                $event->output->writeln("  <comment>Laralyze couldn't create its tables in ClickHouse: {$e->getMessage()}</comment>");
            }
        });
    }

    protected function usesClickHouse(): bool
    {
        return $this->app->make('config')->get('laralyze.storage.driver', 'database') === 'clickhouse';
    }

    protected function registerAboutSection(): void
    {
        AboutCommand::add('Laralyze', fn () => [
            'Enabled' => AboutCommand::format(
                $this->app->make(Laralyze::class)->isEnabled(),
                console: fn (bool $enabled) => $enabled ? '<fg=green;options=bold>ENABLED</>' : 'OFF',
            ),
            'Version' => Laralyze::version() ?? 'unknown',
            'Health' => AboutCommand::format(
                array_column($this->app->make(Dashboard\Health::class)->problems(), 'title'),
                console: fn (array $problems) => $problems === [] ? '<fg=green;options=bold>OK</>' : '<fg=yellow;options=bold>'.implode(' ', $problems).'</>',
            ),
        ]);
    }
}
