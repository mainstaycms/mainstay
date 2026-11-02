<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;

/* A plugin of whatever handle a test gives it, to be refused for it. */
class Named extends Plugin
{
    public static string $handle = 'named';

    public static function handle(): string
    {
        return static::$handle;
    }
}
