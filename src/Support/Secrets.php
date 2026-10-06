<?php

namespace MohammedMojaly\Laralyze\Support;

/**
 * Hides what looks like a secret in code: a literal string assigned to a
 * name like key, secret, password or token. The name stays.
 */
final class Secrets
{
    public const PATTERN = '/((?:["\']?)[\w.-]*(?:key|secret|password|passwd|pwd|token|dsn|credential)[\w.-]*(?:["\']?)\s*(?:=>|=|:)\s*)(["\'])([^"\'\n]{4,})\2/i';

    public static function mask(string $code): string
    {
        return (string) preg_replace(self::PATTERN, '$1$2***$2', $code);
    }
}
