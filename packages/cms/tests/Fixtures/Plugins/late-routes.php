<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => 'Late, with no route named index');
