<?php

namespace MohammedMojaly\Laralyze\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class SendInvoice implements ShouldQueue
{
    use Queueable;

    public function __construct(public bool $fail = false) {}

    public function handle(): void
    {
        if ($this->fail) {
            throw new RuntimeException('Payment provider is down.');
        }
    }
}
