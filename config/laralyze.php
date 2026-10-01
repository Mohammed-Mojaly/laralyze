<?php

use Laralyze\Http\Middleware\Authorize;
use Laralyze\Recorders;

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
    | Leave empty to use the default connection.
    |
    */

    'storage' => [
        'connection' => env('LARALYZE_DB_CONNECTION'),
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
