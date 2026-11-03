<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Mainstay\Plugin;

/* A plugin bringing two AI providers: one on a driver laravel/ai has, and
   one on a driver of its own, made by a method of the plugin. */
class Lab extends Plugin
{
    /** @var list<array<string, mixed>> the configs its driver was handed */
    public static array $made = [];

    public static function handle(): string
    {
        return 'lab';
    }

    public function providers(): array
    {
        return [
            'together' => ['label' => 'Together AI', 'driver' => 'openai-compatible', 'url' => 'https://api.together.xyz/v1'],
            'echo' => ['label' => 'Echo', 'driver' => fn (Application $app, array $config) => $this->make($app, $config), 'url' => 'https://echo.test/v1'],
        ];
    }

    private function make(Application $app, array $config): OpenAiCompatibleProvider
    {
        self::$made[] = $config;

        return new OpenAiCompatibleProvider($config, $app['events']);
    }
}
