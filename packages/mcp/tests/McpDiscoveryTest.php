<?php

namespace Mainstay\Mcp\Tests;

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\McpServiceProvider;
use Mainstay\Mcp\McpPlugin;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/* A host that lets Laravel discover laravel/mcp has its `mcp` view namespace
   before Mainstay's plugins load, so the plugin cannot be called `mcp`. */
class McpDiscoveryTest extends DatabaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, McpPlugin::class]);
    }

    #[Test]
    public function the_plugin_loads_beside_a_discovered_laravel_mcp(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('mainstay.api.mcp'));
    }
}
