<?php

namespace Mainstay\PageBuilder\Tests;

use Illuminate\Support\Facades\Route;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Spread;
use PHPUnit\Framework\Attributes\Test;

/* A site with pages and without the plugin: Details edits everything and nothing answers the Content tab's endpoints. */
class WithoutPageBuilderTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class]);
        $app['view']->addLocation(__DIR__.'/../../cms/tests/Fixtures/views');
    }

    #[Test]
    public function a_routed_type_has_no_content_tab_and_no_canvas_or_block_routes(): void
    {
        Mainstay::types([Genre::class, Spread::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $admin = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse battery', 'role_id' => Role::query()->where('name', 'administrator')->sole()->id]);
        $this->actingAs($admin, 'mainstay');

        $this->get(route('mainstay.entries.create', 'spread'))->assertOk()
            ->assertDontSee('data-tabs', escape: false)
            ->assertDontSee('data-canvas', escape: false)
            ->assertDontSee('page-builder.js', escape: false);

        foreach (['mainstay.canvas.create', 'mainstay.block.create', 'mainstay.previews'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }

        $this->post('/admin/spread/new/canvas', ['title' => 'Flat'])->assertNotFound();
    }
}
