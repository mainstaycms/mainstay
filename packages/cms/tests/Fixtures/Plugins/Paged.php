<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;

/* A plugin whose settings are a screen of its own inside Settings, and nothing else: no routes, no capability. */
class Paged extends Plugin
{
    public static function handle(): string
    {
        return 'paged';
    }

    public static function label(): string
    {
        return 'Paged';
    }

    public function views(): ?string
    {
        return __DIR__.'/paged-views';
    }

    public function settingsRoutes(): ?string
    {
        return __DIR__.'/paged-routes.php';
    }
}
