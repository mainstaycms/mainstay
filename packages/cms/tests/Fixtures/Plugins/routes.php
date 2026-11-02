<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('seo::screens.index'))->name('index');
Route::post('/', fn () => 'Saved the redirects')->name('store');
