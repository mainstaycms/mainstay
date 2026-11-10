<?php

namespace Mainstay\Parts\Tests;

use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\PageBuilder\PageBuilderPlugin;
use Mainstay\Parts\PartsPlugin;
use Mainstay\Parts\Tests\Fixtures\Footer;
use Mainstay\Parts\Tests\Fixtures\Menu;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/* The Template parts card: each part the user may edit and whether it is published. */
class PartsWidgetTest extends DatabaseTestCase
{
    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, PageBuilderPlugin::class, PartsPlugin::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mainstay::types([Footer::class, Menu::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function signIn(array $capabilities): void
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    private function content(string $line): array
    {
        return ['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]]]]];
    }

    /** @return array<string, string> the status shown by each part's link */
    private function statuses(): array
    {
        $page = $this->get(route('mainstay.admin'))->assertOk()->getContent();
        preg_match_all('#<a href="[^"]*/(footer|menu)"[^>]*>[^<]*</a>\s*<span[^>]*>(\w+)</span>#', $page, $found, PREG_SET_ORDER);

        return array_column($found, 2, 1);
    }

    #[Test]
    public function it_shows_each_part_as_published_changed_draft_or_empty_with_an_edit_link(): void
    {
        Mainstay::saveGlobal(Footer::class, $this->content('Live'), locale: 'en', overrideAccess: true);
        $this->signIn(['edit_footer', 'edit_menu']);

        $this->assertSame(['footer' => 'Published', 'menu' => 'Empty'], $this->statuses());

        Mainstay::drafts()->save(Footer::class, $this->content('Next'), locale: 'en', overrideAccess: true);
        Mainstay::drafts()->save(Menu::class, $this->content('First'), locale: 'en', overrideAccess: true);

        $this->assertSame(['footer' => 'Changed', 'menu' => 'Draft'], $this->statuses());
    }

    #[Test]
    public function it_lists_only_the_parts_the_user_may_edit(): void
    {
        $this->signIn(['edit_footer']);

        $this->assertSame(['footer' => 'Empty'], $this->statuses());
    }

    #[Test]
    public function it_is_neither_shown_nor_offered_to_someone_who_may_edit_no_part(): void
    {
        $this->signIn([]);
        $this->get(route('mainstay.admin'))->assertOk()
            ->assertDontSee('id="widget-parts"', false)
            ->assertDontSee('Template parts');

        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['parts']])->assertRedirect();
        $this->assertArrayNotHasKey('parts', auth('mainstay')->user()->fresh()->preference('dashboard.widgets'));
    }
}
