<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;
use Mainstay\Settings\Setting;

/* Declares a `title` as Configured does, which is its own. */
class Tuned extends Plugin
{
    public static function handle(): string
    {
        return 'tuned';
    }

    public static function label(): string
    {
        return 'Tuned';
    }

    public function settings(): array
    {
        return [new Setting('title', 'Tuned title', default: 'Tuned')];
    }
}
