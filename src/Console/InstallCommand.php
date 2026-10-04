<?php

namespace MohammedMojaly\Laralyze\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Env;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Schema;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'laralyze:install')]
class InstallCommand extends Command
{
    protected $signature = 'laralyze:install
                            {--force : Overwrite files that were already published}';

    protected $description = 'Install Laralyze into your application';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', array_filter([
            '--tag' => 'laralyze-config',
            '--force' => $this->option('force'),
        ]));

        // A config published before ClickHouse support has no driver key, and
        // would quietly keep the data in the database.
        if (Env::get('LARALYZE_STORAGE') === 'clickhouse' && config('laralyze.storage.driver') === null) {
            $this->components->error('LARALYZE_STORAGE is clickhouse, but config/laralyze.php was published before ClickHouse support and has no storage.driver key.');
            $this->components->bulletList([
                'Copy the <comment>storage</comment> block from <comment>vendor/mohammed-mojaly/laralyze/config/laralyze.php</comment> into it,',
                'or publish it again with <comment>php artisan laralyze:install --force</comment> (this overwrites your changes),',
                'then run <comment>php artisan laralyze:install</comment> again.',
            ]);

            return self::FAILURE;
        }

        if (config('laralyze.storage.driver', 'database') === 'clickhouse') {
            // ClickHouse isn't a Laravel connection: Laralyze creates its tables itself.
            $storage = $this->laravel->make(ClickHouseStorage::class);
            $version = $storage->install();

            if (version_compare($version, Schema::MINIMUM_VERSION, '<')) {
                $this->components->warn("ClickHouse {$version} is older than ".Schema::MINIMUM_VERSION.', the oldest version Laralyze is tested with.');
            }

            $this->components->info('Laralyze\'s tables are ready in ClickHouse at '.$storage->client()->url().'.');
        } else {
            // Publishing again would copy the migration under a new date and run it twice.
            if ($files->glob(database_path('migrations/*_create_laralyze_tables.php')) === []) {
                $this->call('vendor:publish', ['--tag' => 'laralyze-migrations']);
            }

            $this->call('migrate');
        }

        $this->components->info('Laralyze is installed.');

        $this->components->bulletList([
            'Open the dashboard at <comment>'.url((string) config('laralyze.path', 'laralyze')).'</comment>.',
            'Restart your queue workers (<comment>php artisan queue:restart</comment> or <comment>horizon:terminate</comment>) so their jobs are recorded too.',
            'Review <comment>config/laralyze.php</comment> to tune what Laralyze records.',
            'Set <comment>LARALYZE_ENABLED=false</comment> to switch it off at any time.',
            'Define the <comment>viewLaralyze</comment> gate to control who can open the dashboard outside local.',
        ]);

        return self::SUCCESS;
    }
}
