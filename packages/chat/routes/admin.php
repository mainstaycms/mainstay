<?php

use Illuminate\Support\Facades\Route;
use Mainstay\Chat\ChatController;

Route::get('conversations', [ChatController::class, 'conversations'])->name('conversations');
Route::post('messages', [ChatController::class, 'send'])->name('send');
Route::get('conversations/{conversation}', [ChatController::class, 'show'])->whereUuid('conversation')->name('show');
Route::post('conversations/{conversation}/approvals', [ChatController::class, 'decide'])->whereUuid('conversation')->name('decide');
