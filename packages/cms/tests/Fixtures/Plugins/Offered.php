<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;

/* A plugin declaring whatever AI providers a test gives it, to be refused
   for them. */
class Offered extends Plugin
{
    public static array $providers = [];

    public static function handle(): string
    {
        return 'offered';
    }

    public function providers(): array
    {
        return static::$providers;
    }
}
