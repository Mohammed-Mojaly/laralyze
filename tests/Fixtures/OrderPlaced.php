<?php

namespace MohammedMojaly\Laralyze\Tests\Fixtures;

class OrderPlaced
{
    public function __construct(public string $plan, public float $total) {}
}
