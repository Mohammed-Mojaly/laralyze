<?php

use Illuminate\Support\Facades\Route;
use Laralyze\Http\Controllers\PageController;

Route::get('/', PageController::class)->name('laralyze.dashboard');
Route::get('{page}', PageController::class)->name('laralyze.page');
