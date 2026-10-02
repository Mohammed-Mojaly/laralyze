<?php

use Illuminate\Support\Facades\Route;
use MohammedMojaly\Laralyze\Http\Controllers\GroupController;
use MohammedMojaly\Laralyze\Http\Controllers\PageController;

Route::get('/', PageController::class)->name('laralyze.dashboard');
Route::get('{page}', PageController::class)->name('laralyze.page');
Route::get('{page}/{group}', GroupController::class)->where('group', '[0-9a-f]{32}')->name('laralyze.group');
