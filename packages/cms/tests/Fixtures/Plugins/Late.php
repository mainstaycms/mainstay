<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;

/* A plugin with screens that the routes never took in: added to the config
   after they were cached, or with no route named index. */
class Late extends Plugin
{
    public static function handle(): string
    {
        return 'late';
    }

    public function routes(): ?string
    {
        return __DIR__.'/late-routes.php';
    }
}
