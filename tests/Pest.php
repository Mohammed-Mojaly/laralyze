<?php

use MohammedMojaly\Laralyze\Tests\Concerns\UsesStorage;
use MohammedMojaly\Laralyze\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

pest()->use(UsesStorage::class)->group('storage')->in('Feature/Storage');

pest()->use(UsesStorage::class)->group('dashboard')->in('Feature/Dashboard');
