<?php

namespace MohammedMojaly\Laralyze\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use MohammedMojaly\Laralyze\Dashboard\Groups;
use MohammedMojaly\Laralyze\Dashboard\Pages;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;

/**
 * The page of one route, job, command, query or outgoing URL.
 */
class GroupController
{
    public function __invoke(Pages $pages, DatabaseStorage $storage, Factory $views, string $page, string $group): View
    {
        $current = $pages->find($page);

        if ($current === null || ! Groups::has($page)) {
            abort(404);
        }

        $key = $storage->keyFor(Groups::TYPES[$page], $group) ?? abort(404);

        return $views->make('laralyze::pages.group', ['page' => $current, 'name' => $key]);
    }
}
