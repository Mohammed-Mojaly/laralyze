<?php

use Laralyze\Tests\Concerns\UsesStorage;
use Laralyze\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

pest()->use(UsesStorage::class)->group('storage')->in('Feature/Storage');

pest()->use(UsesStorage::class)->group('dashboard')->in('Feature/Dashboard');
