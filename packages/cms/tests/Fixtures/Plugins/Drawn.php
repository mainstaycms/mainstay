<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;
use Mainstay\Settings\Setting;

/* A plugin whose one setting draws its own control. */
class Drawn extends Plugin
{
    public static function handle(): string
    {
        return 'drawn';
    }

    public static function label(): string
    {
        return 'Drawn';
    }

    public function views(): ?string
    {
        return __DIR__.'/drawn-views';
    }

    public function settings(): array
    {
        return [new Setting('pick', 'Pick', default: 'one', rules: ['max:5'], control: 'drawn::pick')];
    }
}
