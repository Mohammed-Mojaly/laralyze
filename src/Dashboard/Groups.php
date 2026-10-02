<?php

namespace MohammedMojaly\Laralyze\Dashboard;

use MohammedMojaly\Laralyze\Storage\DatabaseStorage;

/**
 * Pages whose rows open a page of their own: one route, job, command,
 * query, outgoing URL or user.
 */
final class Groups
{
    /**
     * Page => the types that hold its keys, the main one first.
     */
    public const TYPES = [
        'requests' => ['request'],
        'jobs' => ['job', 'job_failed'],
        'commands' => ['command'],
        'queries' => ['query'],
        'outgoing-requests' => ['http', 'http_failed'],
        'users' => ['user_request', 'user_job', 'user_exception'],
    ];

    public static function has(string $page): bool
    {
        return isset(self::TYPES[$page]);
    }

    /**
     * What the page is called: the key itself, or a user's name.
     */
    public static function title(string $page, string $key, DatabaseStorage $storage): string
    {
        if ($page !== 'users') {
            return $key;
        }

        $about = json_decode((string) $storage->values('user', [$key])->first()?->value, true);

        return (string) ($about['name'] ?? $key);
    }
}
