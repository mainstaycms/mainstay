<?php

namespace Mainstay\Parts;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Mainstay\Plugin;

/*
 | Template parts: the pieces of a layout the site builds from blocks. A part
 | is edited on the Content tab, which needs mainstay/page-builder, and printed
 | by a view with `@part`. It has no screens of its own, so it asks no
 | capability: who may edit one is what a global asks, and that decides who
 | sees its dashboard widget.
 */
class PartsPlugin extends Plugin
{
    public static function handle(): string
    {
        return 'parts';
    }

    public static function label(): string
    {
        return 'Template parts';
    }

    public function views(): ?string
    {
        return __DIR__.'/../resources/views';
    }

    public function widgets(): array
    {
        return [PartsWidget::class];
    }

    public function boot(Application $app): void
    {
        Blade::directive('part', fn (string $expression) => "<?php echo app(\\Mainstay\\Parts\\Parts::class)->print({$expression}); ?>");
    }
}
