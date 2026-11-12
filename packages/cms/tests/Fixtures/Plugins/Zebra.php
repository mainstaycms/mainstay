<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;

/* A plugin whose settings are a screen of its own inside Settings, and nothing else: no routes, no capability. */
class Zebra extends Plugin
{
    public static function handle(): string
    {
        return 'zebra';
    }

    public static function label(): string
    {
        return 'Zebra';
    }

    public function settingsRoutes(): ?string
    {
        return __DIR__.'/paged-routes.php';
    }
}
