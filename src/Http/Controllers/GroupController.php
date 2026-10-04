<?php

namespace MohammedMojaly\Laralyze\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Dashboard\Groups;
use MohammedMojaly\Laralyze\Dashboard\Pages;

/**
 * The page of one route, job, command, query, outgoing URL or user.
 */
class GroupController
{
    public function __invoke(Pages $pages, Storage $storage, Factory $views, string $page, string $group): View
    {
        $current = $pages->find($page);

        if ($current === null || ! Groups::has($page)) {
            abort(404);
        }

        $key = $storage->keyFor(Groups::TYPES[$page], $group) ?? abort(404);

        return $views->make('laralyze::pages.group', [
            'page' => $current,
            'name' => $key,
            'title' => Groups::title($page, $key, $storage),
        ]);
    }
}
