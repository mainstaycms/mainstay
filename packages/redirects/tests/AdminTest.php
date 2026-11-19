<?php

namespace Mainstay\Redirects\Tests;

use Mainstay\Redirects\Address;
use Mainstay\Redirects\Miss;
use Mainstay\Redirects\Redirect;
use PHPUnit\Framework\Attributes\Test;

/* The screens under Plugins: who may open them and what they change. */
class AdminTest extends RedirectsTestCase
{
    private const INDEX = 'mainstay.plugins.redirects.index';

    private function form(array $changes = []): array
    {
        return ['source' => '/old', 'match_type' => 'exact', 'status' => 301, 'target' => '/new', 'priority' => 100, 'preserve_query' => 1, 'enabled' => 1, ...$changes];
    }

    private function store(array $changes = [])
    {
        return $this->post(route('mainstay.plugins.redirects.store'), $this->form($changes));
    }

    #[Test]
    public function the_screens_are_for_whoever_may_use_redirects(): void
    {
        $this->signIn(['use_site']);
        $this->get(route(self::INDEX))->assertNotFound();

        $this->signIn(['use_redirects']);
        $this->get(route(self::INDEX))->assertOk()->assertSee('Redirects');
        $this->get(route('mainstay.plugins.redirects.misses'))->assertOk();
        $this->get(route('mainstay.plugins.redirects.create'))->assertOk();
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route(self::INDEX))->assertRedirect(route('mainstay.login'));
    }

    #[Test]
    public function a_redirect_is_made_and_applied(): void
    {
        $this->signIn(['use_redirects']);

        $this->store()->assertRedirect(route(self::INDEX))->assertSessionHasNoErrors();

        $this->assertSame('custom', Redirect::query()->sole()->origin);
        $this->get('/old')->assertRedirect('/new');
    }

    #[Test]
    public function a_pattern_is_checked_before_it_is_saved(): void
    {
        $this->signIn(['use_redirects']);

        $this->store(['match_type' => 'regex', 'source' => '/old/(', 'target' => '/x'])->assertSessionHasErrors('source');
        $this->store(['match_type' => 'regex', 'source' => '/old/(.*)', 'target' => '/x/$3'])->assertSessionHasErrors('source');
        $this->store(['match_type' => 'regex', 'source' => '/old/(.*)', 'target' => '/x/$1'])->assertSessionHasNoErrors();

        $this->assertSame(1, Redirect::query()->count());
    }

    #[Test]
    public function a_target_that_could_leave_the_site_unseen_is_refused(): void
    {
        $this->signIn(['use_redirects']);

        $this->store(['target' => '//evil.example'])->assertSessionHasErrors('target');
        $this->store(['target' => 'javascript:alert(1)'])->assertSessionHasErrors('target');
        $this->store(['status' => 301, 'target' => ''])->assertSessionHasErrors('target');
        $this->assertSame(0, Redirect::query()->count());
    }

    #[Test]
    public function an_address_held_twice_or_sent_in_a_circle_is_refused(): void
    {
        $this->signIn(['use_redirects']);
        $this->store(['source' => '/a', 'target' => '/b'])->assertSessionHasNoErrors();

        $this->store(['source' => '/A/', 'target' => '/c'])->assertSessionHasErrors('source');
        $this->store(['source' => '/b', 'target' => '/a'])->assertSessionHasErrors('target');
        $this->store(['source' => '/self', 'target' => '/self'])->assertSessionHasErrors('target');
        $this->assertSame(1, Redirect::query()->count());
    }

    #[Test]
    public function a_gone_redirect_needs_no_target(): void
    {
        $this->signIn(['use_redirects']);

        $this->store(['source' => '/gone', 'status' => 410, 'target' => '/ignored'])->assertSessionHasNoErrors();

        $this->assertNull(Redirect::query()->sole()->target);
        $this->get('/gone')->assertStatus(410);
    }

    #[Test]
    public function a_redirect_is_edited_turned_off_and_deleted(): void
    {
        $this->signIn(['use_redirects']);
        $rule = $this->rule('/old', '/new');

        $this->get(route('mainstay.plugins.redirects.edit', $rule->id))->assertOk()->assertSee('/old');
        $this->post(route('mainstay.plugins.redirects.update', $rule->id), $this->form(['target' => '/newer']))->assertSessionHasNoErrors();
        $this->get('/old')->assertRedirect('/newer');

        $this->post(route('mainstay.plugins.redirects.toggle', $rule->id));
        $this->get('/old')->assertNotFound();

        $this->post(route('mainstay.plugins.redirects.destroy', $rule->id));
        $this->assertSame(0, Redirect::query()->count());
    }

    #[Test]
    public function an_automatic_redirect_edited_by_hand_becomes_the_owners(): void
    {
        $this->signIn(['use_redirects']);
        $post = $this->makePost('a');
        $this->rename($post, 'b');
        $rule = Redirect::query()->sole();

        $this->post(route('mainstay.plugins.redirects.update', $rule->id), $this->form(['source' => '/blog/a', 'target' => '/elsewhere']))->assertSessionHasNoErrors();
        $this->rename($post, 'c');

        $this->assertSame('/elsewhere', $rule->refresh()->target);
        $this->assertSame('custom', $rule->origin);
    }

    #[Test]
    public function the_list_is_searched_and_filtered(): void
    {
        $this->signIn(['use_redirects']);
        $this->rule('/alpha', '/one');
        $this->rule('/beta', '/two');
        $this->pattern('/gamma/(.*)', '/three/$1');

        $this->get(route(self::INDEX, ['query' => 'alpha']))->assertSee('/alpha')->assertDontSee('/beta');
        $this->get(route(self::INDEX, ['type' => 'regex']))->assertSee('/gamma/(.*)', false)->assertDontSee('/beta');
        $this->get(route(self::INDEX, ['origin' => 'moved']))->assertDontSee('/alpha');
    }

    #[Test]
    public function a_whole_selection_is_turned_on_off_or_deleted_at_once(): void
    {
        $this->signIn(['use_redirects']);
        $ids = [$this->rule('/a', '/x')->id, $this->rule('/b', '/y')->id];
        $kept = $this->rule('/c', '/z');

        $this->post(route('mainstay.plugins.redirects.bulk'), ['action' => 'disable', 'ids' => $ids]);
        $this->assertSame([false, false, true], Redirect::query()->orderBy('source')->get()->map->enabled->all());

        $this->post(route('mainstay.plugins.redirects.bulk'), ['action' => 'enable', 'ids' => $ids]);
        $this->get('/a')->assertRedirect('/x');

        $this->post(route('mainstay.plugins.redirects.bulk'), ['action' => 'delete', 'ids' => $ids]);
        $this->assertSame([$kept->id], Redirect::query()->pluck('id')->all());

        $this->post(route('mainstay.plugins.redirects.bulk'), ['action' => 'delete', 'ids' => []])->assertSessionHasErrors('ids');
        $this->post(route('mainstay.plugins.redirects.bulk'), ['action' => 'explode', 'ids' => [$kept->id]])->assertSessionHasErrors('action');
    }

    #[Test]
    public function the_test_box_says_where_an_address_would_go_without_visiting_it(): void
    {
        $this->signIn(['use_redirects']);
        $this->pattern('/old-blog/(.*)', '/articles/$1');
        $this->rule('/gone', null);

        $this->get(route(self::INDEX, ['test' => '/old-blog/post?utm=1']))->assertSee('/articles/post?utm=1')->assertSee('301');
        $this->get(route(self::INDEX, ['test' => '/gone']))->assertSee('410');
        $this->get(route(self::INDEX, ['test' => '/nothing']))->assertSee('No redirect');
        $this->assertSame(0, Redirect::query()->sum('hits'));
    }

    #[Test]
    public function a_miss_is_listed_ignored_and_forgotten_once_it_has_a_redirect(): void
    {
        $this->signIn(['use_redirects']);
        $this->get('/lost');
        $this->get('/also-lost');
        $miss = Miss::query()->where('address', '/lost')->sole();

        $this->get(route('mainstay.plugins.redirects.misses'))->assertSee('/lost')->assertSee('/also-lost');

        $this->post(route('mainstay.plugins.redirects.misses.ignore', $miss->id));
        $this->get(route('mainstay.plugins.redirects.misses'))->assertDontSee('/lost')->assertSee('/also-lost');
        $this->get(route('mainstay.plugins.redirects.misses', ['show' => 'ignored']))->assertSee('/lost');

        $this->post(route('mainstay.plugins.redirects.misses.unignore', $miss->id));
        $this->assertFalse($miss->refresh()->ignored);

        $this->store(['source' => '/also-lost', 'target' => '/found']);
        $this->assertNull(Miss::query()->where('address_hash', Address::hash('exact', '/also-lost'))->first());
    }

    #[Test]
    public function the_settings_are_on_the_settings_screen(): void
    {
        $this->signIn(['manage_settings']);

        $this->get(route('mainstay.settings'))->assertSee('Record addresses that lead to a 404');
        $this->post(route('mainstay.settings.update'), ['settings' => ['redirects' => ['trashed_target' => '//evil.example']]])->assertSessionHasErrors();
        $this->post(route('mainstay.settings.update'), ['settings' => ['redirects' => ['trashed_target' => '/news']]])->assertSessionHasNoErrors();
    }
}
