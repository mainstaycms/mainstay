<?php

namespace Mainstay\Ai\Tests\Fixtures;

use Mainstay\Ai\DeclaresProviders;
use Mainstay\Plugin;

/* A plugin declaring whatever AI providers a test gives it, to be refused
   for them. */
class Offered extends Plugin implements DeclaresProviders
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
