<?php

namespace MohammedMojaly\Laralyze\Dashboard;

/**
 * Pages whose rows open a page of their own: one route, job, command,
 * query or outgoing URL.
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
    ];

    public static function has(string $page): bool
    {
        return isset(self::TYPES[$page]);
    }
}
