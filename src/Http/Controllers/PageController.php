<?php

namespace MohammedMojaly\Laralyze\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use MohammedMojaly\Laralyze\Dashboard\Pages;
use RuntimeException;

class PageController
{
    public function __invoke(Pages $pages, Factory $views, string $page = Pages::HOME): View
    {
        $current = $pages->find($page) ?? abort(404);

        if (! $views->exists($current->view)) {
            throw new RuntimeException("The Laralyze page [{$current->key}] uses the view [{$current->view}], which doesn't exist.");
        }

        return $views->make($current->view);
    }
}
