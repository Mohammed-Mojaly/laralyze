<?php

use MohammedMojaly\Laralyze\Contracts\Storage;

it('keeps alert rules, firing states and seen markers past the retention, and trims the history', function () {
    $storage = app(Storage::class);
    $old = now()->subDays(40)->getTimestamp();

    $storage->store([], [
        ['timestamp' => $old, 'type' => 'alert_rule', 'key' => 'r1', 'value' => '{}'],
        ['timestamp' => $old, 'type' => 'alert_state', 'key' => 'r1:abc', 'value' => '{}'],
        ['timestamp' => $old, 'type' => 'alert_seen', 'key' => '7', 'value' => '1'],
        ['timestamp' => $old, 'type' => 'alert_setup', 'key' => 'defaults', 'value' => '1'],
        ['timestamp' => $old, 'type' => 'alert_event', 'key' => '0000000001:r1:abc:fired', 'value' => '{}'],
        ['timestamp' => $old, 'type' => 'alert_sent', 'key' => 'r1:new:x', 'value' => '1'],
    ]);

    $storage->trim(30);

    expect($storage->values('alert_rule')->pluck('key')->all())->toBe(['r1'])
        ->and($storage->values('alert_state')->pluck('key')->all())->toBe(['r1:abc'])
        ->and($storage->values('alert_seen')->pluck('key')->all())->toBe(['7'])
        ->and($storage->values('alert_setup')->pluck('key')->all())->toBe(['defaults'])
        ->and($storage->values('alert_event'))->toBeEmpty()
        ->and($storage->values('alert_sent'))->toBeEmpty();
});

it('deletes a firing state when it is forgotten', function () {
    $storage = app(Storage::class);
    $storage->put('alert_state', 'r1:abc', '{}');

    $storage->forget('alert_state', 'r1:abc');

    expect($storage->values('alert_state'))->toBeEmpty();
});
