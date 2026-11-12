<?php

namespace Mainstay\Seo;

use Illuminate\Support\ServiceProvider;

/* Laravel lets only a provider publish files; this one is the plugin's, registered from its boot(). */
class SeoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../dist' => public_path('vendor/'.SeoPlugin::handle()),
            ], 'mainstay-assets');
        }
    }
}
