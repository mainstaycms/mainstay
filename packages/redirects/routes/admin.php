<?php

use Illuminate\Support\Facades\Route;
use Mainstay\Redirects\Http\MissController;
use Mainstay\Redirects\Http\RedirectController;

Route::get('/', [RedirectController::class, 'index'])->name('index');
Route::get('new', [RedirectController::class, 'create'])->name('create');
Route::post('new', [RedirectController::class, 'store'])->name('store');
Route::post('bulk', [RedirectController::class, 'bulk'])->name('bulk');
Route::get('{redirect}/edit', [RedirectController::class, 'edit'])->whereNumber('redirect')->name('edit');
Route::post('{redirect}', [RedirectController::class, 'update'])->whereNumber('redirect')->name('update');
Route::post('{redirect}/toggle', [RedirectController::class, 'toggle'])->whereNumber('redirect')->name('toggle');
Route::post('{redirect}/delete', [RedirectController::class, 'destroy'])->whereNumber('redirect')->name('destroy');

Route::get('misses', [MissController::class, 'index'])->name('misses');
Route::post('misses/{miss}/ignore', [MissController::class, 'ignore'])->whereNumber('miss')->name('misses.ignore');
Route::post('misses/{miss}/unignore', [MissController::class, 'unignore'])->whereNumber('miss')->name('misses.unignore');
