<?php

namespace Mainstay\Forms;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Mainstay\Forms\Http\JsonSubmitController;
use Mainstay\Forms\Http\WebSubmitController;
use Mainstay\Plugin;

/*
 | A form builder: forms are entries edited on the Content tab from field
 | blocks, which needs mainstay/page-builder, and what visitors send is kept
 | as submissions and mailed. It has no admin screens of its own, so it asks
 | no capability: who edits a form is what an entry type asks (`edit_forms`),
 | and who reads submissions, `edit_submissions`.
 |
 | Two public endpoints take a post, both behind one rate limiter by address.
 */
class FormsPlugin extends Plugin
{
    public const LIMITER = 'mainstay-forms';

    /* Posts a minute from one address, across every form and both endpoints; `mainstay.forms.limit` changes it. */
    private const DEFAULT_LIMIT = 30;

    public static function handle(): string
    {
        return 'forms';
    }

    public function types(): array
    {
        return [Form::class, Submission::class];
    }

    public function views(): ?string
    {
        return __DIR__.'/../resources/views';
    }

    /*
     | The page's endpoint is in the host's stack for the site, `web` unless
     | `mainstay.site.middleware` says otherwise: the page printing the form
     | was served through it, so the session its token and flashed errors
     | live in is the same one. The JSON endpoint is in the API's domain,
     | prefix and middleware, and has no token middleware: anyone may post.
     */
    public function boot(Application $app): void
    {
        $app->singleton(Forms::class);

        Blade::directive('form', fn (string $expression) => "<?php echo app(\\Mainstay\\Forms\\Forms::class)->print({$expression}); ?>");

        RateLimiter::for(self::LIMITER, fn (Request $request) => Limit::perMinute(config('mainstay.forms.limit', self::DEFAULT_LIMIT))->by($request->ip()));

        Route::middleware([...config('mainstay.site.middleware', ['web']), 'throttle:'.self::LIMITER])
            ->post('mainstay/forms/{form}', WebSubmitController::class)
            ->whereNumber('form')
            ->name('mainstay.forms.submit');

        Route::group([
            'domain' => config('mainstay.domain'),
            'prefix' => config('mainstay.api.prefix'),
            'middleware' => [...config('mainstay.api.middleware'), 'throttle:'.self::LIMITER],
        ], function () {
            Route::post('forms/{form}/submissions', JsonSubmitController::class)->whereNumber('form')->name('mainstay.api.forms.submit');
        });
    }
}
