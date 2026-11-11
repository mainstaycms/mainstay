<?php

namespace Mainstay\PageBuilder;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Mainstay\Http\Authenticate;
use Mainstay\Http\AuthenticateSession;
use Mainstay\PageBuilder\Http\CanvasController;
use Mainstay\PageBuilder\Http\PreviewController;
use Mainstay\Plugin;

/*
 | The Content tab: the page the site draws from an entry's form, edited in
 | place. It needs mainstay/site for a type to have a page; the content
 | itself -- blocks, rich text -- is core's, and Details edits it as data
 | without this plugin.
 |
 | It has no sidebar screens, so it asks no capability of its own: who may
 | edit is what the store asks of Gate, as a save does. Its dashboard widget
 | is for whoever may edit and read a type with a page.
 */
class PageBuilderPlugin extends Plugin
{
    public static function handle(): string
    {
        return 'page_builder';
    }

    public static function label(): string
    {
        return 'Page builder';
    }

    public function views(): ?string
    {
        return __DIR__.'/../resources/views';
    }

    public function entryTabs(): array
    {
        return [ContentTab::class];
    }

    public function widgets(): array
    {
        return [PagesWidget::class];
    }

    public function assets(): array
    {
        return ['page-builder.css', 'page-builder.js'];
    }

    /*
     | Its endpoints are JSON beside the admin's screens, under the admin's
     | stack, sign-in and prefix and at the addresses the entry form's own
     | would have, so they are filed here, before the admin's routes, whose
     | `{type}/{id}` and not-found catch-all would answer them first
     | otherwise.
     */
    public function boot(Application $app): void
    {
        Route::group([
            'domain' => config('mainstay.domain'),
            'prefix' => config('mainstay.path'),
            'middleware' => ['mainstay', ...config('mainstay.middleware', []), Authenticate::class, AuthenticateSession::class],
        ], function () {
            Route::post('{type}/new/canvas', [CanvasController::class, 'canvas'])->name('mainstay.canvas.create');
            Route::post('{type}/new/block', [CanvasController::class, 'block'])->name('mainstay.block.create');
            Route::post('{type}/canvas', [CanvasController::class, 'canvas'])->name('mainstay.canvas.global');
            Route::post('{type}/block', [CanvasController::class, 'block'])->name('mainstay.block.global');
            Route::post('{type}/drafts/{draft}/canvas', [CanvasController::class, 'draftCanvas'])->whereNumber('draft')->name('mainstay.canvas.draft');
            Route::post('{type}/drafts/{draft}/block', [CanvasController::class, 'draftBlock'])->whereNumber('draft')->name('mainstay.block.draft');
            Route::post('{type}/{id}/canvas', [CanvasController::class, 'entryCanvas'])->whereNumber('id')->name('mainstay.canvas.entry');
            Route::post('{type}/{id}/block', [CanvasController::class, 'entryBlock'])->whereNumber('id')->name('mainstay.block.entry');
            Route::get('{type}/previews', [PreviewController::class, 'previews'])->name('mainstay.previews');
        });

        $app->register(PageBuilderServiceProvider::class);
    }
}
