<?php

namespace Mainstay\Forms\Tests;

use Mainstay\Ai\AiConnectors;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Forms\FormsPlugin;
use Mainstay\Mcp\McpPlugin;
use Mainstay\PageBuilder\PageBuilderPlugin;
use Mainstay\Parts\PartsPlugin;
use Mainstay\Parts\Tests\Fixtures\Footer;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Post;
use PHPUnit\Framework\Attributes\Test;

/* Every first-party plugin together, which is where cross-plugin tests live: their widgets' handles must not clash. */
class PluginWidgetsTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [AiConnectors::class, McpPlugin::class, SitePlugin::class, PageBuilderPlugin::class, PartsPlugin::class, FormsPlugin::class]);
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mainstay::types([Post::class, Footer::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    #[Test]
    public function all_six_plugins_register_their_widgets_without_a_handle_clash(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse battery', 'role_id' => Role::query()->where('name', 'administrator')->value('id')]);

        $this->actingAs($user, 'mainstay');

        preg_match_all('/aria-labelledby="widget-(\w+)-title"/', $this->get(route('mainstay.admin'))->assertOk()->getContent(), $shown);
        $handles = $shown[1];

        $this->assertEqualsCanonicalizing(['at_a_glance', 'ai_connectors', 'mcp_server', 'site', 'page_builder', 'parts', 'forms'], $handles);
    }
}
