<?php

namespace Mainstay\PageBuilder\Tests;

use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\PageBuilder\PageBuilderPlugin;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Post;
use Mainstay\Tests\Fixtures\Signed\Note;
use Mainstay\Tests\Fixtures\Signed\Topic;
use PHPUnit\Framework\Attributes\Test;

/* The Pages in the builder card: the entries changed last of the types with the Content tab. */
class PagesWidgetTest extends DatabaseTestCase
{
    private const SHOWN = 5;

    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, PageBuilderPlugin::class]);
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mainstay::types([Post::class, Note::class, Topic::class]);
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

    private function writePost(string $slug): Post
    {
        return Mainstay::create(Post::class, data: ['title' => $slug, 'slug' => $slug, 'status' => 'live'], overrideAccess: true);
    }

    #[Test]
    public function it_lists_a_routed_taxonomys_terms_without_asking_for_their_drafts(): void
    {
        Mainstay::create(Topic::class, data: ['title' => 'Travel', 'slug' => 'travel'], overrideAccess: true);
        $this->signIn(['*']);

        $this->get(route('mainstay.admin'))->assertOk()->assertSee('Travel');
    }

    #[Test]
    public function it_says_there_are_no_pages_yet(): void
    {
        $this->signIn(['edit_entries']);

        $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('id="widget-page_builder"', false)
            ->assertSee('There are no pages yet.');
    }

    #[Test]
    public function it_shows_the_five_changed_last_with_their_status_and_a_link_each(): void
    {
        foreach (range(1, self::SHOWN + 1) as $n) {
            $this->travel(1)->seconds();
            $post = $this->writePost("post-{$n}");
        }

        $this->travel(1)->seconds();
        Mainstay::drafts()->save(Post::class, ['title' => 'Post six, reworded'], entry: $post->id, locale: 'en', overrideAccess: true);
        $this->signIn(['edit_entries', 'edit_published_entries', 'edit_others_entries']);

        $page = $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('Post six, reworded')
            ->assertSee('Changed')
            ->assertSee('Published');

        $this->assertSame(self::SHOWN, substr_count($page->getContent(), 'min-w-0 truncate font-medium text-accent hover:underline'));
        $this->assertStringNotContainsString('>post-1<', $page->getContent());
    }

    #[Test]
    public function it_is_neither_shown_nor_offered_to_someone_who_may_edit_no_such_type(): void
    {
        $this->writePost('secret');

        $this->signIn([]);
        $this->get(route('mainstay.admin'))->assertOk()
            ->assertDontSee('id="widget-page_builder"', false)
            ->assertDontSee('secret');

        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['page_builder']])->assertRedirect();
        $this->assertArrayNotHasKey('page_builder', auth('mainstay')->user()->fresh()->preference('dashboard.widgets'));
    }
}
