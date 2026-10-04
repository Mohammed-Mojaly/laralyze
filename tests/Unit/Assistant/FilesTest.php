<?php

use Illuminate\Filesystem\Filesystem;
use MohammedMojaly\Laralyze\Assistant\Files;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/laralyze-files-'.uniqid();
    $files = new Filesystem;

    foreach ([
        'app/Models/Order.php' => "<?php\n\nclass Order\n{\n    public function items()\n    {\n        return \$this->hasMany(Item::class);\n    }\n}\n",
        'config/services.php' => "<?php\n\nreturn [\n    'stripe' => [\n        'secret' => 'sk_live_51HxYz',\n        'key' => env('STRIPE_KEY'),\n    ],\n];\n",
        '.env' => "APP_KEY=base64:abc\n",
        'app/.env.backup' => "DB_PASSWORD=hunter2\n",
        'storage/logs/laravel.log' => "secret stuff\n",
        'vendor/acme/Thing.php' => "<?php\n",
        'app/certs/server.pem' => "-----BEGIN PRIVATE KEY-----\n",
        'public/index.php' => "<?php\n",
    ] as $path => $contents) {
        $files->ensureDirectoryExists(dirname("{$this->root}/{$path}"));
        $files->put("{$this->root}/{$path}", $contents);
    }

    $this->app->setBasePath($this->root);
    config(['laralyze.assistant.paths' => ['app', 'config', 'vendor']]);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->root);
});

it('reads app files with line numbers', function () {
    expect(app(Files::class)->read('app/Models/Order.php'))
        ->toContain('app/Models/Order.php')
        ->toContain('7:         return $this->hasMany(Item::class);');
});

it('reads a location as Laralyze stores it, from the project root', function () {
    expect(app(Files::class)->around('/app/Models/Order.php', 7, 1))->toContain('6:     {')->toContain('8:     }')->not->toContain('3: class Order');
});

it('never reads secrets, whatever the config allows', function (string $path) {
    expect(app(Files::class)->read($path))->toStartWith("Can't read");
})->with([
    '.env', 'app/.env.backup', 'storage/logs/laravel.log', 'vendor/acme/Thing.php', 'app/certs/server.pem',
    'outside the allowed folders' => 'public/index.php',
    'climbing out' => 'app/../.env',
    'out of the project' => '../../etc/passwd',
]);

it('masks values that look like secrets, keeping their names', function () {
    expect(app(Files::class)->read('config/services.php'))
        ->toContain("'secret' => '***'")
        ->toContain("'key' => env('STRIPE_KEY')")
        ->not->toContain('sk_live');
});

it('searches the code it may read', function () {
    $matches = app(Files::class)->search('hasMany');

    expect($matches)->toBe(['app/Models/Order.php:7: return $this->hasMany(Item::class);'])
        ->and(app(Files::class)->search('sk_live'))->toBe([])
        ->and(app(Files::class)->search('hunter2'))->toBe([]);
});
