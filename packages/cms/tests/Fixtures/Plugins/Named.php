<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;

/* A plugin of whatever handle and label a test gives it, to be refused for them. */
class Named extends Plugin
{
    public static string $handle = 'named';

    public static string $label = 'Named';

    public static function handle(): string
    {
        return static::$handle;
    }

    public static function label(): string
    {
        return static::$label;
    }
}
