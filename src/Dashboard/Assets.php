<?php

namespace Laralyze\Dashboard;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * The dashboard ships its own CSS and JS inline, so it works whatever the
 * host app uses for its frontend.
 */
class Assets
{
    public function styles(): HtmlString
    {
        return new HtmlString('<style'.$this->nonceAttribute().'>'.$this->read(__DIR__.'/../../resources/css/laralyze.css').'</style>');
    }

    public function scripts(): HtmlString
    {
        return new HtmlString(
            '<script'.$this->nonceAttribute().'>'.$this->read($this->livewireScript()).'</script>'.PHP_EOL.
            '<script'.$this->nonceAttribute().'>'.$this->read(__DIR__.'/../../resources/js/laralyze.js').'</script>'
        );
    }

    public function nonce(): ?string
    {
        return Vite::cspNonce();
    }

    protected function nonceAttribute(): string
    {
        $nonce = $this->nonce();

        return $nonce === null ? '' : ' nonce="'.e($nonce).'"';
    }

    protected function livewireScript(): string
    {
        $path = InstalledVersions::getInstallPath('livewire/livewire');

        return ($path ?? __DIR__.'/../../vendor/livewire/livewire').'/dist/livewire.min.js';
    }

    protected function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Laralyze couldn't read the dashboard asset [{$path}].");
        }

        return $contents;
    }
}
