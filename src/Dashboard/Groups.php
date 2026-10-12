<?php

namespace MohammedMojaly\Laralyze\Dashboard;

use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Recorders\Queries;

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
        'scheduled' => ['scheduled', 'scheduled_failed', 'scheduled_skipped'],
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
            'queries' => Queries::parts($key)[1],
            default => $key,
        };
    }

    /**
     * The single executions listed under a row, as the list's filters, or
     * null when the page lists none.
     *
     * @return array{type?: string, name?: string, user?: string, exception?: string, call?: string, title: string, order?: string}|null
     */
    public static function executions(string $page, string $key, Storage $storage): ?array
    {
        return match ($page) {
            'requests' => ['type' => 'request', 'name' => $key, 'title' => 'Requests'],
            'jobs' => ['type' => 'job', 'name' => $key, 'title' => 'Attempts', 'order' => 'recent'],
            'commands' => ['type' => 'command', 'name' => $key, 'title' => 'Runs', 'order' => 'recent'],
            'users' => ['type' => 'request', 'user' => $key, 'title' => 'Requests'],
            'exceptions' => ['exception' => $key, 'title' => 'Occurrences', 'order' => 'recent'],
            'queries', 'outgoing-requests' => ['call' => $key, 'title' => 'Executions'],
            'scheduled' => [...self::taskRuns($key, $storage), 'title' => 'Runs', 'order' => 'recent'],
            default => null,
        };
    }

    /**
     * A task that runs an artisan command runs it in a process of its own,
     * kept as that command; a closure or job is kept as the task itself.
     *
     * @return array{type: string, name: string}
     */
    protected static function taskRuns(string $key, Storage $storage): array
    {
        $command = json_decode((string) $storage->values('scheduled_task', [$key])->first()?->value, true)['command'] ?? null;

        return is_string($command) && $command !== '' ? ['type' => 'command', 'name' => $command] : ['type' => 'task', 'name' => $key];
    }
}
