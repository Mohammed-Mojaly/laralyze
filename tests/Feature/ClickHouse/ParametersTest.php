<?php

use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Storage\ClickHouseStorage;

beforeEach(fn () => usingClickHouse() || $this->markTestSkipped('Needs LARALYZE_TEST_STORAGE=clickhouse.'));

it('sends any text as a parameter unchanged', function () {
    $text = "DOMAIN\jdoe \t tab \n line \r 'quote' \N \\ مرحبا";

    $row = app(ClickHouseStorage::class)->client()->select(
        'SELECT {text:String} AS text, {list:Array(String)} AS list',
        ['text' => $text, 'list' => [$text, 'plain']],
    )[0];

    expect($row['text'])->toBe($text)->and($row['list'])->toBe([$text, 'plain']);
});

it('filters executions by a user id with a backslash', function () {
    app(Storage::class)->store([], [], [[
        'uuid' => (string) Str::ulid(), 'trace' => 'T', 'type' => 'request', 'name' => 'GET /', 'status' => '200',
        'failed' => false, 'duration' => 1.0, 'user_id' => 'DOMAIN\jdoe', 'server' => 'web', 'started_at' => time(),
        'exceptions' => [], 'counts' => [], 'meta' => [], 'events' => [],
    ]]);

    expect(app(Storage::class)->executions(['user' => 'DOMAIN\jdoe'], 3_600))->toHaveCount(1);
});
