<?php

use Illuminate\Support\Facades\Route;
use Mainstay\Http\ApiController;
use Mainstay\Http\AuthenticateToken;
use Mainstay\Mainstay;

/*
 | The content API, every route behind a token or reading as nobody -- see
 | AuthenticateToken.
 | Entries and globals sit under segments of their own, so no type's handle
 | can take one of these.
 */
Route::middleware(AuthenticateToken::class)->group(function () {
    Route::get('/', fn () => [
        'name' => 'mainstay',
        'version' => Mainstay::VERSION,
    ])->name('mainstay.api.index');

    Route::get('schema', [ApiController::class, 'schema'])->name('mainstay.api.schema');
    Route::get('types', [ApiController::class, 'types'])->name('mainstay.api.types');
    Route::get('types/{type}', [ApiController::class, 'type'])->name('mainstay.api.type');
    Route::get('path', [ApiController::class, 'path'])->name('mainstay.api.path');

    Route::get('entries/{type}', [ApiController::class, 'index'])->name('mainstay.api.entries');
    Route::post('entries/{type}', [ApiController::class, 'store'])->name('mainstay.api.entries.store');
    Route::get('entries/{type}/{id}', [ApiController::class, 'show'])->whereNumber('id')->name('mainstay.api.entries.show');
    Route::patch('entries/{type}/{id}', [ApiController::class, 'update'])->whereNumber('id')->name('mainstay.api.entries.update');
    Route::delete('entries/{type}/{id}', [ApiController::class, 'destroy'])->whereNumber('id')->name('mainstay.api.entries.destroy');

    Route::get('globals/{type}', [ApiController::class, 'global'])->name('mainstay.api.globals');
    Route::put('globals/{type}', [ApiController::class, 'saveGlobal'])->name('mainstay.api.globals.update');

    Route::get('drafts', [ApiController::class, 'draftOf'])->name('mainstay.api.drafts');
    Route::post('drafts', [ApiController::class, 'saveDraft'])->name('mainstay.api.drafts.store');
    Route::get('drafts/{id}', [ApiController::class, 'draft'])->whereNumber('id')->name('mainstay.api.drafts.show');
    Route::post('drafts/{id}/publish', [ApiController::class, 'publish'])->whereNumber('id')->name('mainstay.api.drafts.publish');
    Route::delete('drafts/{id}', [ApiController::class, 'discard'])->whereNumber('id')->name('mainstay.api.drafts.destroy');
});
