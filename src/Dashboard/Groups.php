<?php

namespace MohammedMojaly\Laralyze\Dashboard;

use MohammedMojaly\Laralyze\Contracts\Storage;

/**
 * Pages whose rows open a page of their own: one route, job, command,
 * query, outgoing URL, user or exception.
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
        // Agents and models share the page; a model's key is ["provider","model"].
        'ai' => ['ai', 'ai_failed', 'ai_model', 'ai_model_failed'],
        'users' => ['user_request', 'user_job', 'user_exception'],
        'exceptions' => ['exception'],
    ];

    public static function has(string $page): bool
    {
        return isset(self::TYPES[$page]);
    }

    /**
     * What the page is called: the key itself, a user's name or an exception's message.
     */
    public static function title(string $page, string $key, Storage $storage): string
    {
        return match ($page) {
            'users' => (string) (json_decode((string) $storage->values('user', [$key])->first()?->value, true)['name'] ?? $key),
            'exceptions' => (string) ($storage->values('exception_message', [$key])->first()->value ?? json_decode($key, true)[0] ?? $key),
            'ai' => (string) (json_decode($key, true)[1] ?? $key),
            default => $key,
        };
    }
}
