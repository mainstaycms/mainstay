<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Content\Icon;
use Mainstay\Events\Deleted;
use Mainstay\Events\Published;
use Mainstay\Events\Restored;
use Mainstay\Events\Saved;
use Mainstay\Plugin;

/* A plugin with one of everything: a type and a global, a block and a field
   type drawn from its own views, screens, and a listener on every event. */
#[Icon('star')]
class Seo extends Plugin
{
    public static function handle(): string
    {
        return 'seo';
    }

    public function types(): array
    {
        return [SeoRedirect::class, SeoSettings::class];
    }

    public function views(): ?string
    {
        return __DIR__.'/views';
    }

    public function routes(): ?string
    {
        return __DIR__.'/routes.php';
    }

    public function listeners(): array
    {
        return array_fill_keys([Saved::class, Published::class, Deleted::class, Restored::class], [Heard::class]);
    }
}
