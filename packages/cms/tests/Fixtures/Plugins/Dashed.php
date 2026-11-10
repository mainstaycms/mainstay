<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;
use Mainstay\Tests\Fixtures\Post;

/* A plugin with widgets and no screens, and a type for the dashboard to count. */
class Dashed extends Plugin
{
    public static function handle(): string
    {
        return 'dashed';
    }

    public function types(): array
    {
        return [Post::class];
    }

    public function views(): ?string
    {
        return __DIR__.'/dashed-views';
    }

    public function widgets(): array
    {
        return [DashedMetrics::class, DashedQuiet::class, DashedWide::class];
    }
}
