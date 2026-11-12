<?php

namespace Mainstay\Seo;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use LogicException;
use Mainstay\Events\Deleted;
use Mainstay\Events\Published;
use Mainstay\Events\Restored;
use Mainstay\Events\Saved;
use Mainstay\Http\Authenticate;
use Mainstay\Http\AuthenticateSession;
use Mainstay\Plugin;
use Mainstay\Seo\Http\PreviewController;
use Mainstay\Seo\Http\RobotsController;
use Mainstay\Seo\Http\SitemapController;
use Mainstay\Settings\Setting;
use Mainstay\Site\SitePlugin;

/*
 | Search engine optimisation. A type opts in with a field,
 |
 |     #[Seo]
 |     public ?SeoMeta $seo;
 |
 | and gets an SEO tab on its form: a snippet preview, a focus keyphrase and
 | live checks of the content and its readability. The host's layout draws the
 | tags with `<x-seo::head :entry="$entry" />`. The plugin also serves
 | sitemap.xml and robots.txt, and keeps the site-wide defaults in Settings:
 | the general ones under General, the ones per language on a screen of their
 | own.
 |
 | It needs mainstay/site, which gives an entry the address every one of its
 | outputs links to, and works without the page builder: the tab draws the
 | page itself. Mainstay checks no plugin's dependencies, so boot() does. It
 | has no sidebar screens and asks no capability of its own: the tab follows
 | who may edit the entry, the settings who may manage settings, and the
 | sitemap and robots.txt are public.
 */
class SeoPlugin extends Plugin
{
    public const REQUIRES = [SitePlugin::class];

    public const DESCRIPTION_MAX = 500;

    public static function handle(): string
    {
        return 'seo';
    }

    public static function label(): string
    {
        return 'SEO';
    }

    public function settings(): array
    {
        return [
            new Setting('site_name', 'Site name', 'text', 'Written into a page title where its template says %site%, and as the Organization and the Open Graph site name.', default: '', rules: ['max:100']),
            new Setting('default_description', 'Default description', 'textarea', 'What a page with no description of its own says about itself.', default: '', rules: ['max:'.self::DESCRIPTION_MAX]),
            new Setting('organization_name', 'Organization name', 'text', 'The name in the Organization structured data. Empty uses the site name.', default: '', rules: ['max:100']),
            new Setting('noindex_site', 'Hide the whole site from search engines', 'boolean', 'Every page says noindex and robots.txt disallows everything. For a site not yet launched.', default: false),
        ];
    }

    public function views(): ?string
    {
        return __DIR__.'/../resources/views';
    }

    public function settingsRoutes(): ?string
    {
        return __DIR__.'/../routes/settings.php';
    }

    public function entryTabs(): array
    {
        return [SeoTab::class];
    }

    public function assets(): array
    {
        return ['seo.css', 'seo.js'];
    }

    public function listeners(): array
    {
        return array_fill_keys([Saved::class, Published::class, Deleted::class, Restored::class], [ForgetSitemap::class]);
    }

    public function migrations(): ?string
    {
        return __DIR__.'/../database/migrations';
    }

    public function tables(): array
    {
        return ['seo_locales'];
    }

    /*
     | sitemap.xml and robots.txt are explicit routes, which the site plugin's
     | catch-all, a fallback, never outranks, and named `mainstay.*` so that
     | an entry whose path would be one is refused as it is for the admin's.
     | The tab's preview is JSON beside the admin's screens, filed before
     | them as the page builder's are, whose `{type}/{id}` would answer it
     | first otherwise.
     */
    public function boot(Application $app): void
    {
        foreach (self::REQUIRES as $required) {
            if (! in_array($required, config('mainstay.plugins', []), true)) {
                throw new LogicException(self::class." needs the plugin {$required} in config('mainstay.plugins'): it gives entries the addresses the sitemap and the tags link to.");
            }
        }

        $app->bind(LocaleSettings::class);

        Route::middleware(config('mainstay.site.middleware', ['web']))->group(function () {
            Route::get('sitemap.xml', SitemapController::class)->name('mainstay.seo.sitemap');
            Route::get('robots.txt', RobotsController::class)->name('mainstay.seo.robots');
        });

        Route::group([
            'domain' => config('mainstay.domain'),
            'prefix' => config('mainstay.path'),
            'middleware' => ['mainstay', ...config('mainstay.middleware', []), Authenticate::class, AuthenticateSession::class],
        ], function () {
            Route::post('{type}/new/seo-preview', [PreviewController::class, 'create'])->name('mainstay.seo.preview.create');
            Route::post('{type}/drafts/{draft}/seo-preview', [PreviewController::class, 'draft'])->whereNumber('draft')->name('mainstay.seo.preview.draft');
            Route::post('{type}/{id}/seo-preview', [PreviewController::class, 'entry'])->whereNumber('id')->name('mainstay.seo.preview.entry');
        });

        $app->register(SeoServiceProvider::class);
    }
}
