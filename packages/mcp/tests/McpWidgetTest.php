<?php

namespace Mainstay\Mcp\Tests;

use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Mcp\McpPlugin;
use Mainstay\Mcp\Server;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/* The MCP server card: its address, the tools it offers and where tokens are made, for any account. */
class McpWidgetTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, McpPlugin::class]);
    }

    private function signIn(array $capabilities): void
    {
        $role = Role::query()->create(['name' => 'role', 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    #[Test]
    public function it_shows_the_endpoint_the_tool_count_and_the_tokens_screen(): void
    {
        $tools = count((new \ReflectionProperty(Server::class, 'tools'))->getDefaultValue());

        $this->signIn([]);
        $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('aria-labelledby="widget-mcp_server-title"', false)
            ->assertSee('value="'.route('mainstay.api.mcp').'"', false)
            ->assertSee('<span class="font-medium">'.$tools.'</span>', false)
            ->assertSee('href="'.route('mainstay.tokens', absolute: false).'"', false);
        $this->assertGreaterThan(0, $tools);
    }

    #[Test]
    public function it_can_be_switched_off_in_screen_options(): void
    {
        $this->signIn([]);
        $this->post(route('mainstay.dashboard.screen'), ['widgets' => []])->assertRedirect();

        $this->get(route('mainstay.admin'))->assertOk()->assertDontSee('aria-labelledby="widget-mcp_server-title"', false);
    }
}
