<?php

namespace MohammedMojaly\Laralyze\Assistant;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Support\Secrets;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Read-only access to your code for the assistant, inside the folders
 * allowed in config. Secrets are never read: .env files, keys and
 * credentials, storage, vendor and .git are refused wherever they are, and
 * values that look like passwords, keys or tokens are masked.
 */
class Files
{
    public const MAX_BYTES = 200_000;

    public const MAX_LINES = 300;

    /**
     * Refused anywhere in a path, whatever the config allows.
     */
    protected const DENIED = [
        '#(^|/)\.env#i',
        '#(^|/)\.git(/|$)#',
        '#(^|/)(vendor|node_modules|storage)(/|$)#',
        '#(^|/)(auth\.json|\.npmrc|\.htpasswd|id_rsa[^/]*|id_ed25519[^/]*)$#i',
        '#\.(pem|key|p12|pfx|crt|cer|jks|keystore|sqlite|sqlite3|db|log|zip|gz|tar|phar)$#i',
        '#(^|/)[^/]*(secret|credential|password)[^/]*$#i',
    ];

    public function __construct(protected Repository $config) {}

    /**
     * Numbered lines of a file, or why it can't be read.
     */
    public function read(string $path, ?int $from = null, ?int $to = null): string
    {
        $file = $this->resolve($path);

        if (! is_string($file)) {
            return "Can't read {$path}: {$file['error']}";
        }

        $lines = preg_split('/\R/', Secrets::mask((string) file_get_contents($file))) ?: [];
        $from = max(1, $from ?? 1);
        $to = min(count($lines), $to ?? $from + self::MAX_LINES - 1, $from + self::MAX_LINES - 1);
        $out = [];

        for ($number = $from; $number <= $to; $number++) {
            $out[] = $number.': '.$lines[$number - 1];
        }

        $more = $to < count($lines) ? "\n(".count($lines).' lines in all; ask for lines from '.($to + 1).')' : '';

        return $this->relative($file)."\n".implode("\n", $out).$more;
    }

    /**
     * A few lines around one, as for a stack frame or a query's location.
     */
    public function around(string $path, int $line, int $padding = 12): ?string
    {
        return is_string($this->resolve($path)) ? $this->read($path, max(1, $line - $padding), $line + $padding) : null;
    }

    /**
     * Lines containing the text, in readable files: "path:line: code".
     *
     * @return list<string>
     */
    public function search(string $text, int $limit = 40): array
    {
        $text = trim($text);
        $matches = [];

        if (strlen($text) < 3) {
            return [];
        }

        foreach ($this->allowed() as $root) {
            $files = is_dir($root)
                ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS))
                : [new SplFileInfo($root)];

            foreach ($files as $info) {
                if (! $info instanceof SplFileInfo || ! $info->isFile() || ! preg_match('/\.(php|js|ts|vue|jsx|tsx|json|ya?ml|md)$/', $info->getFilename())) {
                    continue;
                }

                $file = $this->resolve($info->getPathname());

                if (! is_string($file)) {
                    continue;
                }

                // Searched masked, so guessing a secret can't confirm it.
                foreach (preg_split('/\R/', Secrets::mask((string) file_get_contents($file))) ?: [] as $i => $line) {
                    if (stripos($line, $text) !== false) {
                        $matches[] = $this->relative($file).':'.($i + 1).': '.Str::limit(trim($line), 200);

                        if (count($matches) >= $limit) {
                            return $matches;
                        }
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * The real path of a readable file, or why not.
     *
     * @return string|array{error: string}
     */
    public function resolve(string $path): string|array
    {
        $base = $this->base();
        $path = str_replace('\\', '/', trim($path));
        // Laralyze stores locations from the project's root, as "/app/Models/User.php:12".
        $real = realpath($base.'/'.ltrim($path, '/')) ?: (str_starts_with($path, $base) ? realpath($path) : false);

        if ($real === false || ! is_file($real)) {
            return ['error' => 'no such file in the project.'];
        }

        $relative = $this->relative($real);

        if ($relative === null) {
            return ['error' => 'it is outside the project.'];
        }

        foreach (self::DENIED as $pattern) {
            if (preg_match($pattern, $relative)) {
                return ['error' => 'files like this may hold secrets and are never read.'];
            }
        }

        $inside = false;

        foreach ($this->allowed() as $root) {
            $root = $this->relative($root) ?? '';
            $inside = $inside || $relative === $root || str_starts_with($relative, rtrim($root, '/').'/');
        }

        if (! $inside) {
            return ['error' => 'it is outside the folders Laralyze may read ('.implode(', ', (array) $this->config->get('laralyze.assistant.paths', [])).').'];
        }

        if (filesize($real) > self::MAX_BYTES) {
            return ['error' => 'it is too large.'];
        }

        return $real;
    }

    /**
     * The path from the project's root, or null outside it.
     */
    public function relative(string $path): ?string
    {
        $base = $this->base();
        $path = str_replace('\\', '/', $path);

        if ($path === $base) {
            return '';
        }

        return str_starts_with(strtolower($path), strtolower($base.'/')) ? substr($path, strlen($base) + 1) : null;
    }

    /**
     * @return list<string>
     */
    protected function allowed(): array
    {
        $roots = [];

        foreach ((array) $this->config->get('laralyze.assistant.paths', []) as $path) {
            $real = realpath($this->base().'/'.trim((string) $path, '/'));

            if ($real !== false) {
                $roots[] = str_replace('\\', '/', $real);
            }
        }

        return $roots;
    }

    protected function base(): string
    {
        return str_replace('\\', '/', (string) (realpath(base_path()) ?: base_path()));
    }
}
