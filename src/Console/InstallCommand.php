<?php

namespace MohammedMojaly\Laralyze\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
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

        // Publishing again would copy the migration under a new date and run it twice.
        if ($files->glob(database_path('migrations/*_create_laralyze_tables.php')) === []) {
            $this->call('vendor:publish', ['--tag' => 'laralyze-migrations']);
        }

        $this->call('migrate');

        $this->components->info('Laralyze is installed.');

        $this->components->bulletList([
            'Open the dashboard at <comment>'.url((string) config('laralyze.path', 'laralyze')).'</comment>.',
            'Review <comment>config/laralyze.php</comment> to tune what Laralyze records.',
            'Set <comment>LARALYZE_ENABLED=false</comment> to switch it off at any time.',
            'Define the <comment>viewLaralyze</comment> gate to control who can open the dashboard outside local.',
        ]);

        return self::SUCCESS;
    }
}
