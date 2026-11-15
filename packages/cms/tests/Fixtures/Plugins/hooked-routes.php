<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mainstay\Auth\Gate;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Api\Essay;

Route::get('hooked/whoami', fn () => ['user' => Gate::user()?->getKey()])->name('mainstay.api.hooked.whoami');
Route::post('hooked/essays', fn (Request $request) => ['title' => Mainstay::create(Essay::class, (array) $request->input('data'))->title])->name('mainstay.api.hooked.essays');
