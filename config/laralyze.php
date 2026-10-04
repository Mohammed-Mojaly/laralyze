<?php

use MohammedMojaly\Laralyze\Http\Middleware\Authorize;
use MohammedMojaly\Laralyze\Recorders;

return [

    /*
    |--------------------------------------------------------------------------
    | Laralyze Master Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, Laralyze records nothing and adds no work to your requests,
    | jobs or commands. Handy as an emergency switch in production.
    |
    */

    'enabled' => env('LARALYZE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Laralyze keeps its data in its own tables. Point it at a separate database
    | connection to keep monitoring data away from your application's data.
    | Leave empty to use the default connection. Set LARALYZE_STORAGE=clickhouse
    | to keep it in ClickHouse instead, for high traffic.
    |
    */

    'storage' => [
        // database (the connection below) or clickhouse.
        'driver' => env('LARALYZE_STORAGE', 'database'),

        'connection' => env('LARALYZE_DB_CONNECTION'),

        'clickhouse' => [
            'url' => env('LARALYZE_CLICKHOUSE_URL', 'http://127.0.0.1:8123'),
            'database' => env('LARALYZE_CLICKHOUSE_DATABASE', 'default'),
            'username' => env('LARALYZE_CLICKHOUSE_USERNAME', 'default'),
            'password' => env('LARALYZE_CLICKHOUSE_PASSWORD', ''),
            // Seconds a write may take before recording pauses.
            'timeout' => (float) env('LARALYZE_CLICKHOUSE_TIMEOUT', 3),
            // Wait until ClickHouse has saved each write. Turning it off frees
            // workers sooner (Octane, high traffic) but hides failed writes.
            'wait' => (bool) env('LARALYZE_CLICKHOUSE_WAIT', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many days of data to keep. Older data is removed automatically.
    |
    */

    'retention' => (int) env('LARALYZE_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Buffer
    |--------------------------------------------------------------------------
    |
    | How many distinct metrics one request, job or command holds in memory
    | before writing. A web request that fills it drops new ones rather than
    | writing early; the dashboard warns when that happens.
    |
    */

    'buffer' => (int) env('LARALYZE_BUFFER', 5_000),

    /*
    |--------------------------------------------------------------------------
    | Recorders
    |--------------------------------------------------------------------------
    |
    | Each recorder watches one part of your application. Disable the ones
    | you don't need; a disabled recorder costs nothing at runtime.
    |
    */

    'recorders' => [

        Recorders\Requests::class => [
            'enabled' => env('LARALYZE_REQUESTS_ENABLED', true),
            'sample_rate' => (float) env('LARALYZE_REQUESTS_SAMPLE_RATE', 1),

            // Milliseconds. Use a map for per-route limits, matched against "GET /path":
            // ['#^GET /reports#' => 3000, 'default' => 1000]
            'threshold' => (int) env('LARALYZE_SLOW_REQUESTS_THRESHOLD', 1000),

            // Paths to leave out, as regular expressions. Laralyze's own pages are
            // always left out. The defaults are developer tools.
            'ignore' => [
                '#^/_boost#',
                '#^/_debugbar#',
                '#^/_ignition#',
                '#^/telescope#',
                '#^/horizon#',
                '#^/pulse#',
                // '#^/up$#',
            ],
        ],

        Recorders\Queries::class => [
            'enabled' => env('LARALYZE_QUERIES_ENABLED', true),

            // Milliseconds. A map works too, matched against the SQL.
            'threshold' => (int) env('LARALYZE_SLOW_QUERIES_THRESHOLD', 1000),

            // Show the file and line that ran each slow query.
            'location' => true,

            'ignore' => [
                // '/^select \* from `sessions`/',
            ],
        ],

        Recorders\Exceptions::class => [
            'enabled' => env('LARALYZE_EXCEPTIONS_ENABLED', true),

            // Exception classes to leave out, as regular expressions.
            'ignore' => [
                // '/^App\\\\Exceptions\\\\PaymentDeclined$/',
            ],
        ],

        Recorders\Jobs::class => [
            'enabled' => env('LARALYZE_JOBS_ENABLED', true),
            'threshold' => (int) env('LARALYZE_SLOW_JOBS_THRESHOLD', 1000),
            'ignore' => [],
        ],

        Recorders\ScheduledTasks::class => [
            'enabled' => env('LARALYZE_SCHEDULED_TASKS_ENABLED', true),
            'ignore' => [],
        ],

        Recorders\Commands::class => [
            'enabled' => env('LARALYZE_COMMANDS_ENABLED', true),

            // Long-running workers and framework plumbing aren't interesting here.
            'ignore' => [
                '/^(schedule:|queue:(work|listen)|horizon|octane:|reverb:|package:discover|laralyze:)/',
            ],
        ],

        Recorders\Cache::class => [
            'enabled' => env('LARALYZE_CACHE_ENABLED', true),

            // Turn keys into groups, so user:1 and user:2 share a row.
            'groups' => [
                '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '*',
                '/\d+/' => '*',
            ],

            'ignore' => [],
        ],

        Recorders\OutgoingRequests::class => [
            'enabled' => env('LARALYZE_OUTGOING_REQUESTS_ENABLED', true),

            // URLs become groups, so /users/1 and /users/2 share a row.
            'groups' => [
                '#/\d+(?=/|$)#' => '/*',
            ],

            'ignore' => [],
        ],

        // Calls made with laravel/ai: tokens, estimated cost, duration and
        // failures by agent, model and user. Prompts and responses are never kept.
        Recorders\Ai::class => [
            'enabled' => env('LARALYZE_AI_ENABLED', true),

            // USD per million tokens, for models Laralyze has no price for or
            // that you pay differently for. Keyed by model, or provider/model.
            'prices' => [
                // 'my-fine-tuned-model' => ['input' => 0.30, 'output' => 1.20],
            ],

            // Agent classes to leave out, as regular expressions.
            'ignore' => [],
        ],

        Recorders\Mail::class => [
            'enabled' => env('LARALYZE_MAIL_ENABLED', true),
            'ignore' => [],
        ],

        Recorders\Notifications::class => [
            'enabled' => env('LARALYZE_NOTIFICATIONS_ENABLED', true),
            'ignore' => [],
        ],

        Recorders\Logs::class => [
            'enabled' => env('LARALYZE_LOGS_ENABLED', true),

            // Levels to leave out, e.g. '/^debug$/'.
            'ignore' => [],
        ],

        Recorders\Users::class => [
            'enabled' => env('LARALYZE_USERS_ENABLED', true),

            // Milliseconds after which a request counts as slow for this user.
            'threshold' => (int) env('LARALYZE_SLOW_REQUESTS_THRESHOLD', 1000),
        ],

        Recorders\Traces::class => [
            'enabled' => env('LARALYZE_TRACES_ENABLED', true),

            // Share of requests, jobs and commands kept with everything that
            // happened inside them. Slow, failed and throwing ones are always kept.
            'sample_rate' => (float) env('LARALYZE_TRACES_SAMPLE_RATE', 0.1),

            // Milliseconds after which one is slow and always kept.
            'threshold' => (int) env('LARALYZE_SLOW_REQUESTS_THRESHOLD', 1000),

            'keep_days' => (int) env('LARALYZE_TRACES_DAYS', 7),

            // Events kept per execution; the counts still include the rest.
            'max_events' => 500,

            // Commands to leave out, as regular expressions.
            'ignore' => [],
        ],

        Recorders\Servers::class => [
            'enabled' => env('LARALYZE_SERVERS_ENABLED', true),

            // Taken every minute by the scheduler, so it needs your cron entry.
            'server_name' => env('LARALYZE_SERVER_NAME', gethostname()),

            // Disks to watch, by any path on them. Comma separated in .env.
            'directories' => explode(',', (string) env('LARALYZE_SERVER_DIRECTORIES', '/')),
        ],

        Recorders\Visits::class => [
            'enabled' => env('LARALYZE_VISITS_ENABLED', true),

            // Paths that aren't page visits.
            'except' => [
                'api/*',
                'horizon*',
                'telescope*',
                'pulse*',
                '_boost*',
                '_debugbar*',
            ],

            // Pages are counted by route ("/posts/{post}"). Fold more together here.
            'groups' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | Laralyze checks every minute from your scheduler and tells you when
    | something goes wrong. Leave every channel empty to turn alerts off.
    | Each alert is sent at most once per "every" minutes.
    |
    */

    'alerts' => [
        // Comma separated addresses.
        'mail' => env('LARALYZE_ALERTS_MAIL'),
        'slack' => env('LARALYZE_ALERTS_SLACK_WEBHOOK'),
        'discord' => env('LARALYZE_ALERTS_DISCORD_WEBHOOK'),

        'every' => (int) env('LARALYZE_ALERTS_EVERY', 60),

        'rules' => [
            // A new exception, or one you resolved happening again.
            'exceptions' => true,

            // Percent of requests answered with a 5xx over the last 5 minutes.
            'error_rate' => 5,

            // Jobs that failed over the last 5 minutes.
            'failed_jobs' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Assistant
    |--------------------------------------------------------------------------
    |
    | "Ask AI" on an exception, an N+1, a slow query or request, or anything
    | Laralyze recorded. Needs laravel/ai 1.0+ and a provider with an API key
    | in config/ai.php. Nothing is sent until someone asks. Conversations are
    | kept in Laralyze's tables for 7 days and are never recorded as your
    | app's AI calls.
    |
    */

    'assistant' => [
        'enabled' => env('LARALYZE_ASSISTANT_ENABLED', true),

        // A provider from config/ai.php and its model. Empty: your app's
        // default AI provider and that provider's default model.
        'provider' => env('LARALYZE_ASSISTANT_PROVIDER'),
        'model' => env('LARALYZE_ASSISTANT_MODEL'),

        // Show answers as they're written. Turn off if your server holds
        // responses back until they're complete.
        'stream' => env('LARALYZE_ASSISTANT_STREAM', true),

        // Folders and files it may read, from your project's root. .env files,
        // keys and credentials, storage, vendor and .git are never read, and
        // values that look like passwords, keys or tokens are masked.
        'paths' => ['app', 'routes', 'config', 'database', 'resources', 'tests', 'composer.json'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Where the dashboard lives and who gets in. Access outside the local
    | environment is decided by the "viewLaralyze" gate.
    |
    */

    'path' => env('LARALYZE_PATH', 'laralyze'),

    'domain' => env('LARALYZE_DOMAIN'),

    'middleware' => [
        'web',
        Authorize::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Pages and Cards
    |--------------------------------------------------------------------------
    |
    | Add your own pages to the sidebar, or hide built-in ones:
    |
    |     'checkout' => ['label' => 'Checkout', 'section' => 'Business', 'view' => 'laralyze.checkout'],
    |     'requests' => false,
    |
    | Swap a built-in card for your own subclass of it:
    |
    |     'routes' => App\Laralyze\Routes::class,
    |
    */

    'pages' => [
        //
    ],

    'cards' => [
        //
    ],

];
