<?php

namespace MohammedMojaly\Laralyze\Alerts;

/**
 * One thing worth telling someone about.
 */
final class Alert
{
    /**
     * @param  string  $id  The same problem gets the same id, so it's sent only once in a while.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
    ) {}
}
