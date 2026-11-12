<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;
use Mainstay\Settings\Setting;

/* Declares the same key twice. */
class Twice extends Plugin
{
    public static function handle(): string
    {
        return 'twice';
    }

    public static function label(): string
    {
        return 'Twice';
    }

    public function settings(): array
    {
        return [new Setting('same', 'One'), new Setting('same', 'Two')];
    }
}
