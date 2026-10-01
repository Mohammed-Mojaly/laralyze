<?php

namespace MohammedMojaly\Laralyze\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\LaralyzeServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'laralyze:make-card')]
class MakeCardCommand extends Command
{
    protected $signature = 'laralyze:make-card
                            {name : The card name, e.g. CheckoutFunnel}
                            {--force : Overwrite the card if it exists}';

    protected $description = 'Create a Laralyze dashboard card';

    public function handle(Filesystem $files): int
    {
        $name = Str::studly($this->argument('name'));
        $tag = Str::kebab($name);

        if ($name === '' || array_key_exists($tag, LaralyzeServiceProvider::CARDS)) {
            $this->components->error("[{$tag}] is taken by a built-in card. Pick another name.");

            return self::FAILURE;
        }

        $replacements = [
            '{{ title }}' => Str::headline($name),
            '{{ type }}' => Str::snake($name),
            '{{ class }}' => $name,
            '{{ namespace }}' => $this->classNamespace(),
            '{{ view }}' => "livewire.laralyze.{$tag}",
        ];

        $targets = $this->usesSingleFileComponents()
            ? [$this->componentPath($tag) => 'card-sfc']
            : [
                app_path('Livewire/Laralyze/'.$name.'.php') => 'card-class',
                resource_path("views/livewire/laralyze/{$tag}.blade.php") => 'card-view',
            ];

        foreach ($targets as $path => $stub) {
            if ($files->exists($path) && ! $this->option('force')) {
                $this->components->error("[{$path}] already exists. Use --force to overwrite it.");

                return self::FAILURE;
            }
        }

        foreach ($targets as $path => $stub) {
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, strtr($files->get(__DIR__."/../../stubs/{$stub}.stub"), $replacements));

            $this->components->info("Created [{$path}].");
        }

        $this->components->bulletList([
            "Record data: Laralyze::record('{$replacements['{{ type }}']}', \$key, \$value)->count()->max();",
            "Show it on a page: <livewire:laralyze.{$tag} cols=\"6\" />",
        ]);

        return self::SUCCESS;
    }

    /**
     * Livewire 4 gets a single-file card; Livewire 3 a class and a view.
     */
    protected function usesSingleFileComponents(): bool
    {
        $version = InstalledVersions::getVersion('livewire/livewire');

        return $version !== null && version_compare($version, '4.0.0', '>=');
    }

    protected function componentPath(string $tag): string
    {
        $locations = (array) config('livewire.component_locations', [resource_path('views/components')]);
        $location = is_string($locations[0] ?? null) ? $locations[0] : resource_path('views/components');

        return rtrim($location, '/\\')."/laralyze/{$tag}.blade.php";
    }

    protected function classNamespace(): string
    {
        return rtrim((string) config('livewire.class_namespace', 'App\\Livewire'), '\\').'\\Laralyze';
    }
}
