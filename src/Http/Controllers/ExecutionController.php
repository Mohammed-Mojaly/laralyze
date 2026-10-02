<?php

namespace MohammedMojaly\Laralyze\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use MohammedMojaly\Laralyze\Dashboard\Pages;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;

/**
 * One request, job or command: what happened inside it, in order.
 */
class ExecutionController
{
    public const PAGES = ['request' => 'requests', 'job' => 'jobs', 'command' => 'commands'];

    public function __invoke(Pages $pages, DatabaseStorage $storage, Factory $views, string $execution): View
    {
        $found = $storage->execution($execution) ?? abort(404);
        $page = $pages->find(self::PAGES[$found->type] ?? '');

        return $views->make('laralyze::pages.execution', [
            'execution' => $found,
            'page' => $page,
            'related' => $storage->related($found->trace, $found->uuid),
            'parent' => $found->trace === $found->uuid ? null : $storage->execution($found->trace),
            'user' => $found->user_id === null ? null : json_decode((string) $storage->values('user', [$found->user_id])->first()?->value, true),
            'attempts' => $found->job_uuid === null ? collect() : $storage->attempts((string) $found->job_uuid),
        ]);
    }
}
