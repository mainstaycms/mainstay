<?php

use Illuminate\Support\Facades\Route;
use Mainstay\Http\EditorSession;
use Mainstay\Http\MediaFileController;

/*
 | Every media file, public or not, through one controller. The file's name
 | is the route's to constrain: a hash, and what an image's copy or a
 | document's extension may follow it. `signed` is the same file by a URL
 | signed to expire, for a restricted one sent where no one is signed in.
 */
Route::get('media/s/{file}', [MediaFileController::class, 'signed'])->where('file', MediaFileController::FILE)->middleware('signed')->name('mainstay.media.signed');
Route::get('media/{file}', [MediaFileController::class, 'show'])->where('file', MediaFileController::FILE)->middleware(EditorSession::class)->name('mainstay.media.file');
