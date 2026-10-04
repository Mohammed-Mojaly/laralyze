<?php

namespace MohammedMojaly\Laralyze\Tests\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class BookWriter implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Write a short profile of the book.';
    }

    public function tools(): iterable
    {
        return [new SearchBooks];
    }
}
