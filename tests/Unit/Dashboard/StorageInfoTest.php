<?php

use MohammedMojaly\Laralyze\Dashboard\StorageInfo;

it('names the database, its kind and how writes reach it', function () {
    config([
        'laralyze.storage.driver' => 'database',
        'laralyze.storage.connection' => 'monitoring',
        'laralyze.ingest.driver' => null,
        'database.connections.monitoring' => ['driver' => 'mysql', 'database' => 'shop', 'password' => 'secret'],
    ]);

    $info = StorageInfo::describe(app());

    expect($info['label'])->toBe('MySQL · shop')
        ->and($info['writes'])->toBe('Queued writes')
        ->and($info['detail'])->toBe('Storage: database (MySQL), connection [monitoring], database shop. Writes wait in laralyze_ingest and are merged every minute.')
        ->and(json_encode($info))->not->toContain('secret');
});

it('uses the default connection and shows a SQLite file by its name', function () {
    config([
        'laralyze.storage.driver' => 'database',
        'laralyze.storage.connection' => null,
        'laralyze.ingest.driver' => null,
        'database.default' => 'local',
        'database.connections.local' => ['driver' => 'sqlite', 'database' => '/var/www/app/database/database.sqlite'],
    ]);

    $info = StorageInfo::describe(app());

    expect($info['label'])->toBe('SQLite · database.sqlite')
        ->and($info['writes'])->toBe('Direct writes');
});

it('names ClickHouse and its database, without credentials', function () {
    config([
        'laralyze.storage.driver' => 'clickhouse',
        'laralyze.storage.clickhouse.url' => 'https://admin:hunter2@ch.example.com:8443',
        'laralyze.storage.clickhouse.database' => 'laralyze',
    ]);

    $info = StorageInfo::describe(app());

    expect($info['label'])->toBe('ClickHouse · laralyze')
        ->and($info['writes'])->toBeNull()
        ->and($info['detail'])->toBe('Storage: ClickHouse at https://ch.example.com:8443, database laralyze.')
        ->and(json_encode($info))->not->toContain('hunter2');
});

it('names a database kind it doesn\'t know by its driver', function () {
    config([
        'laralyze.storage.driver' => 'database',
        'laralyze.storage.connection' => 'other',
        'laralyze.ingest.driver' => 'direct',
        'database.connections.other' => ['driver' => 'singlestore', 'database' => 'metrics'],
    ]);

    expect(StorageInfo::describe(app())['label'])->toBe('singlestore · metrics');
});
