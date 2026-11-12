<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('paged::page'))->name('index');
