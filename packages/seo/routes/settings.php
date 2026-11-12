<?php

use Illuminate\Support\Facades\Route;
use Mainstay\Seo\Http\SettingsController;

Route::get('/', [SettingsController::class, 'show'])->name('index');
Route::post('/', [SettingsController::class, 'update'])->name('update');
