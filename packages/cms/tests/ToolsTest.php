<?php

namespace Mainstay\Tests;

use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Plugins\Toolbox;
use PHPUnit\Framework\Attributes\Test;

/*
 | A plugin's tool, drawn by the admin's layout on every screen for whoever
 | holds the plugin's capability: the button in the top bar, its dialog at the
 | end of the page, and its entry in the command center.
 */
class ToolsTest extends DatabaseTestCase
{
    private const BUTTON = 'data-dialog-open="toolbox-dialog"';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [Toolbox::class]);
    }

    private function userHolding(array $capabilities): User
    {
        $role = Role::query()->create(['name' => 'role-'.implode('-', $capabilities), 'capabilities' => $capabilities]);

        return User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse battery', 'role_id' => $role->id]);
    }

    #[Test]
    public function a_tool_is_on_every_screen_of_someone_holding_the_capability(): void
    {
        $this->actingAs($this->userHolding(['use_toolbox']), 'mainstay');

        foreach (['mainstay.admin', 'mainstay.account'] as $screen) {
            $this->get(route($screen))->assertOk()->assertSee(self::BUTTON, false)->assertSee('<dialog id="toolbox-dialog">', false);
        }
    }

    #[Test]
    public function a_tool_is_not_there_without_the_capability(): void
    {
        $this->actingAs($this->userHolding(['view_dashboard']), 'mainstay');

        $this->get(route('mainstay.admin'))->assertOk()->assertDontSee(self::BUTTON, false)->assertDontSee('toolbox-dialog');
    }

    #[Test]
    public function a_tool_is_not_on_the_sign_in_screen(): void
    {
        $this->get(route('mainstay.login'))->assertOk()->assertDontSee('toolbox-dialog');
    }

    #[Test]
    public function a_tools_label_is_text_and_is_a_command(): void
    {
        $this->actingAs($this->userHolding(['use_toolbox']), 'mainstay');

        $page = $this->get(route('mainstay.admin'))->assertOk();
        $page->assertSee('Tool&lt;b&gt;box&lt;/b&gt;', false)->assertDontSee('Tool<b>box</b>', false);
        $page->assertSee('"dialog":"toolbox-dialog"', false);
    }

    #[Test]
    public function a_plugin_with_only_tools_has_a_capability_and_no_sidebar_item(): void
    {
        $this->assertSame([Toolbox::class => 'use_toolbox'], Capabilities::plugins());
        $this->assertContains('use_toolbox', Mainstay::capabilities());

        $this->actingAs($this->userHolding(['use_toolbox']), 'mainstay');
        $this->get(route('mainstay.admin'))->assertOk()->assertDontSee('/admin/toolbox');
    }
}
