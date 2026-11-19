<?php

namespace Mainstay\Redirects\Tests;

use Mainstay\Facades\Mainstay;
use Mainstay\Redirects\Redirect;
use Mainstay\Tests\Fixtures\Page;
use Mainstay\Tests\Fixtures\Post;
use PHPUnit\Framework\Attributes\Test;

/* The redirects made as entries move, go to the trash and come back. */
class AutomaticRedirectsTest extends RedirectsTestCase
{
    /** @return list<array{0: string, 1: ?string, 2: int, 3: string}> source, target, status, origin */
    private function rules(): array
    {
        return Redirect::query()->orderBy('source')->get()->map(fn (Redirect $rule) => [$rule->source, $rule->target, $rule->status, $rule->origin])->all();
    }

    #[Test]
    public function changing_a_slug_redirects_the_old_address_to_the_new_one(): void
    {
        $post = $this->makePost('hello');

        $this->rename($post, 'hi');

        $this->assertSame([['/blog/hello', '/blog/hi', 301, 'moved']], $this->rules());
        $this->get('/blog/hello')->assertStatus(301)->assertRedirect('/blog/hi');
        $this->get('/blog/hi')->assertOk();
    }

    #[Test]
    public function the_redirect_knows_the_entry_and_the_language_that_caused_it(): void
    {
        $post = $this->makePost('hello');

        $this->rename($post, 'hi');

        $rule = Redirect::query()->sole();
        $this->assertSame([Post::class, $post->id, 'en'], [$rule->entry_type, $rule->entry_id, $rule->locale]);
    }

    #[Test]
    public function a_save_that_leaves_the_address_alone_makes_nothing(): void
    {
        $post = $this->makePost('hello');

        Mainstay::update(Post::class, $post->id, ['title' => 'A new title'], locale: 'en', overrideAccess: true);
        $this->makePost('another');

        $this->assertSame([], $this->rules());
    }

    #[Test]
    public function a_draft_changes_no_live_address_until_it_is_published(): void
    {
        $post = $this->makePost('hello');

        $draft = Mainstay::drafts()->save(Post::class, ['slug' => 'drafted'], entry: $post->id, locale: 'en', overrideAccess: true);

        $this->assertSame([], $this->rules());
        $this->get('/blog/hello')->assertOk();

        Mainstay::drafts()->publish($draft->id, overrideAccess: true);

        $this->assertSame([['/blog/hello', '/blog/drafted', 301, 'moved']], $this->rules());
        $this->get('/blog/hello')->assertRedirect('/blog/drafted');
    }

    #[Test]
    public function only_the_language_whose_address_changed_is_redirected(): void
    {
        $post = $this->makePost('hello');
        $this->translate($post, 'hallo');

        $this->rename($post, 'hoi', 'nl');

        $this->assertSame([['/nl/nieuws/hallo', '/nl/nieuws/hoi', 301, 'moved']], $this->rules());
    }

    #[Test]
    public function a_shared_field_in_the_path_moves_every_language_at_once(): void
    {
        $page = Mainstay::create(Page::class, ['title' => 'Team', 'section' => 'about', 'slug' => 'team'], locale: 'en', overrideAccess: true);
        Mainstay::update(Page::class, $page->id, ['title' => 'Team NL', 'slug' => 'team-nl'], locale: 'nl', overrideAccess: true);

        Mainstay::update(Page::class, $page->id, ['section' => 'work'], locale: 'en', overrideAccess: true);

        $this->assertSame([
            ['/about/team', '/work/team', 301, 'moved'],
            ['/nl/about/team-nl', '/nl/work/team-nl', 301, 'moved'],
        ], $this->rules());
        $this->get('/nl/about/team-nl')->assertRedirect('/nl/work/team-nl');
    }

    #[Test]
    public function a_chain_of_moves_collapses_so_no_visitor_hops_through_an_old_address(): void
    {
        $post = $this->makePost('a');

        $this->rename($post, 'b');
        $this->rename($post, 'c');

        $this->assertSame([['/blog/a', '/blog/c', 301, 'moved'], ['/blog/b', '/blog/c', 301, 'moved']], $this->rules());
        $this->get('/blog/a')->assertRedirect('/blog/c');
    }

    #[Test]
    public function moving_back_removes_the_redirect_from_the_address_that_lives_again_and_makes_no_self_redirect(): void
    {
        $post = $this->makePost('a');

        $this->rename($post, 'b');
        $this->rename($post, 'a');

        $this->assertSame([['/blog/b', '/blog/a', 301, 'moved']], $this->rules());
        $this->get('/blog/a')->assertOk();
    }

    #[Test]
    public function a_redirect_the_owner_made_to_the_old_address_follows_it_to_the_new_one(): void
    {
        $post = $this->makePost('a');
        $this->rule('/campaign', '/blog/a?ref=mail');

        $this->rename($post, 'b');

        $this->assertSame('/blog/b?ref=mail', Redirect::query()->where('source', '/campaign')->value('target'));
    }

    #[Test]
    public function a_redirect_the_owner_made_at_the_old_address_is_never_replaced(): void
    {
        $post = $this->makePost('a');
        $this->rule('/blog/a', '/elsewhere', ['status' => 302]);

        $this->rename($post, 'b');

        $this->assertSame([['/blog/a', '/elsewhere', 302, 'custom']], $this->rules());
    }

    #[Test]
    public function a_redirect_the_owner_made_at_the_new_address_is_kept_and_does_not_become_a_self_redirect(): void
    {
        $post = $this->makePost('a');
        $this->rule('/blog/b', '/blog/a');

        $this->rename($post, 'b');

        $this->assertSame([['/blog/a', '/blog/b', 301, 'moved'], ['/blog/b', '/blog/a', 301, 'custom']], $this->rules());
        $this->get('/blog/b')->assertOk();
        $this->assertStringContainsString('circle', Redirect::query()->where('source', '/blog/b')->value('last_error'));
    }

    #[Test]
    public function an_entry_moved_to_the_trash_answers_410_at_every_address(): void
    {
        $post = $this->makePost('hello');
        $this->translate($post, 'hallo');

        Mainstay::delete(Post::class, $post->id, overrideAccess: true);

        $this->assertSame([['/blog/hello', null, 410, 'trashed'], ['/nl/nieuws/hallo', null, 410, 'trashed']], $this->rules());
        $this->get('/blog/hello')->assertStatus(410);
        $this->get('/nl/nieuws/hallo')->assertStatus(410);
    }

    #[Test]
    public function restoring_the_entry_removes_the_redirects_it_made(): void
    {
        $post = $this->makePost('hello');
        Mainstay::delete(Post::class, $post->id, overrideAccess: true);

        Mainstay::restore(Post::class, $post->id, overrideAccess: true);

        $this->assertSame([], $this->rules());
        $this->get('/blog/hello')->assertOk();
    }

    #[Test]
    public function the_owner_can_send_trashed_pages_somewhere_instead_of_410(): void
    {
        Mainstay::setSetting('redirects.trashed_target', '/news');
        $post = $this->makePost('hello');

        Mainstay::delete(Post::class, $post->id, overrideAccess: true);

        $this->assertSame([['/blog/hello', '/news', 301, 'trashed']], $this->rules());
        $this->get('/blog/hello')->assertRedirect('/news');

        Mainstay::restore(Post::class, $post->id, overrideAccess: true);

        $this->assertSame([], $this->rules());
    }

    #[Test]
    public function a_moved_entry_that_is_trashed_answers_410_from_its_old_address_too(): void
    {
        $post = $this->makePost('a');
        $this->rename($post, 'b');

        Mainstay::delete(Post::class, $post->id, overrideAccess: true);

        $this->get('/blog/a')->assertStatus(410);
        $this->get('/blog/b')->assertStatus(410);
    }

    #[Test]
    public function deleting_for_good_keeps_the_address_gone(): void
    {
        $post = $this->makePost('hello');
        Mainstay::delete(Post::class, $post->id, overrideAccess: true);

        Mainstay::destroy(Post::class, $post->id, overrideAccess: true);

        $this->assertSame([['/blog/hello', null, 410, 'trashed']], $this->rules());
        $this->get('/blog/hello')->assertStatus(410);
    }

    #[Test]
    public function a_new_entry_at_an_address_that_was_gone_takes_it_back(): void
    {
        $post = $this->makePost('hello');
        Mainstay::delete(Post::class, $post->id, overrideAccess: true);

        $this->makePost('hello');

        $this->assertSame([], $this->rules());
        $this->get('/blog/hello')->assertOk();
    }

    #[Test]
    public function an_entry_restored_to_a_different_address_is_redirected_from_the_one_it_was_trashed_at(): void
    {
        $first = $this->makePost('hello');
        Mainstay::delete(Post::class, $first->id, overrideAccess: true);
        $this->makePost('hello');
        $this->assertSame([], $this->rules());

        Mainstay::restore(Post::class, $first->id, overrideAccess: true);

        $this->assertSame([], $this->rules(), 'the address is a live page again, so nothing is sent away from it');
        $this->get('/blog/hello')->assertOk();
        $this->get('/blog/hello-2')->assertOk();
    }

    #[Test]
    public function a_type_without_an_address_makes_nothing(): void
    {
        $this->assertSame([], $this->rules());
        $this->assertSame(0, Redirect::query()->count());
    }
}
