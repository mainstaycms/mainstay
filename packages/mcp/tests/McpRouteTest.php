<?php

namespace Mainstay\Mcp\Tests;

use Illuminate\Support\Facades\Route;
use Mainstay\Mcp\McpPlugin;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/* The server answers where the REST API does: on the host the admin and API
   are served from, and on no other. */
class McpRouteTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, McpPlugin::class]);
        $app['config']->set('mainstay.domain', 'cms.example.test');
    }

    #[Test]
    public function the_route_is_bound_to_the_domain_the_api_is(): void
    {
        $this->assertSame('cms.example.test', Route::getRoutes()->getByName('mainstay.api.mcp')->getDomain());
        $this->assertSame('cms.example.test', Route::getRoutes()->getByName('mainstay.api.index')->getDomain());
    }
}
