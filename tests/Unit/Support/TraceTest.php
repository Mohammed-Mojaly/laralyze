<?php

use MohammedMojaly\Laralyze\Support\Trace;

it('hides secrets in the code it keeps around a frame', function () {
    $key = 'sk-live-abcdef';
    $exception = new RuntimeException('Charge failed');

    $lines = Trace::frames($exception)[0]['code']['lines'];

    expect($lines)->toContain("    \$key = '***';")
        ->and($lines)->toContain("    \$exception = new RuntimeException('Charge failed');");
});
