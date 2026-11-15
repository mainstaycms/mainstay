<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;

/* A plugin with a tool on every screen and nothing else: no routes, so no
   sidebar item. Its label is markup, to be drawn as text. */
class Toolbox extends Plugin
{
    public static function handle(): string
    {
        return 'toolbox';
    }

    public static function label(): string
    {
        return 'Tool<b>box</b>';
    }

    public function views(): ?string
    {
        return __DIR__.'/views';
    }

    public function tools(): ?string
    {
        return 'toolbox::tools.button';
    }
}
