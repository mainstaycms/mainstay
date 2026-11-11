<?php

use Illuminate\Support\Facades\Route;
use Mainstay\Chat\ChatController;

Route::get('/', [ChatController::class, 'index'])->name('index');
Route::post('messages', [ChatController::class, 'send'])->name('send');
Route::get('conversations/{conversation}', [ChatController::class, 'show'])->whereUuid('conversation')->name('show');
Route::post('conversations/{conversation}/approvals', [ChatController::class, 'decide'])->whereUuid('conversation')->name('decide');
