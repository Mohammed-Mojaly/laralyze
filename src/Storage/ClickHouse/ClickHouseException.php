<?php

namespace MohammedMojaly\Laralyze\Storage\ClickHouse;

use RuntimeException;

class ClickHouseException extends RuntimeException
{
    public static function fromResponse(int $status, string $body): self
    {
        // "Code: 60. DB::Exception: …. (UNKNOWN_TABLE) (version …)": the version adds nothing.
        $message = trim((string) preg_replace('/\s*\(version .*\)\s*$/s', '', trim($body)));

        return new self($message !== '' ? $message : "ClickHouse answered HTTP {$status}.", $status);
    }
}
