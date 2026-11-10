<?php

namespace Mainstay\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Gate;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Events\Deleted;
use Mainstay\Events\Published;
use Mainstay\Events\Restored;
use Mainstay\Events\Saved;
use Mainstay\Facades\Mainstay;
use Mainstay\Mainstay as Registry;
use Mainstay\Navigation;
use Mainstay\Tests\Fixtures\Plugins\Heard;
use Mainstay\Tests\Fixtures\Plugins\Late;
use Mainstay\Tests\Fixtures\Plugins\Named;
use Mainstay\Tests\Fixtures\Plugins\NamedToo;
use Mainstay\Tests\Fixtures\Plugins\Seo;
use Mainstay\Tests\Fixtures\Plugins\SeoFaq;
use Mainstay\Tests\Fixtures\Plugins\SeoRedirect;
use Mainstay\Tests\Fixtures\Plugins\SeoSettings;
use Mainstay\Tests\Fixtures\Plugins\Unfinished;
use Mainstay\Tests\Fixtures\Post;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/*
 | A plugin named in config, end to end: its types synced, its block and
 | field drawn from its own views, its screens behind the admin's sign-in and
 | its own capability, its listener told of every live write once it commits
 | -- and every name it could take from Mainstay, the host or another plugin
 | refused. The booted registry throughout, since config is read as Mainstay
 | boots and declare() would swap in one that never loaded the plugin.
 */
class PluginTest extends DatabaseTestCase
{
    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [Seo::class]);
        $app['config']->set('view.paths', [...$app['config']->get('view.paths'), __DIR__.'/Fixtures/Plugins/host-views']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Heard::$events = [];
        Named::$handle = 'named';
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function user(array $capabilities = [], ?string $role = null): User
    {
        $n = ++$this->made;
        $role = $role === null
            ? Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities])
            : Role::query()->where('name', $role)->sole();

        return User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
    }

    private function signIn(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    private function refused(callable $load, string $message): void
    {
        try {
            $load();
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }

        $this->fail("Nothing was refused; expected: {$message}");
    }

    /** @return list<array{0: class-string, 1: class-string, 2: int}> */
    private function heard(): array
    {
        return array_map(fn (object $event) => [$event::class, $event->type, $event->id], Heard::$events);
    }

    #[Test]
    public function a_plugin_adds_a_tab_to_the_forms_it_applies_to_and_its_files_to_every_screen(): void
    {
        $this->signIn($this->user(role: 'administrator'));

        $redirect = $this->get(route('mainstay.entries.create', 'seo_redirect'))->assertOk()
            ->assertSee('id="notes-tab"', escape: false)
            ->assertSee('data-notes-panel', escape: false)
            ->assertSee('data-notes-toolbar', escape: false)
            ->assertSee('data-notes-aside', escape: false)
            ->assertSee('vendor/seo/seo.css?v=', escape: false)
            ->assertSee('vendor/seo/seo.js?v=', escape: false);
        $this->assertStringContainsString('seo_redirect /said', html_entity_decode(strip_tags($redirect->getContent())));

        $this->get(route('mainstay.entries', 'seo_settings'))->assertOk()->assertDontSee('data-tabs', escape: false)->assertSee('vendor/seo/seo.js?v=', escape: false);
    }

    #[Test]
    public function a_plugin_config_names_brings_its_types_block_field_and_views(): void
    {
        $this->assertSame(['seo'], array_keys(Mainstay::plugins()));
        $this->assertInstanceOf(Seo::class, Mainstay::plugins()['seo']);
        $this->assertSame(SeoRedirect::class, Mainstay::registered()['seo_redirect']);
        $this->assertSame(SeoSettings::class, Mainstay::registered()['seo_settings']);
        $this->assertTrue(Schema::hasColumn('seo_redirect', 'meta'));

        $faq = new SeoFaq;
        $faq->question = 'Why <now>?';
        $this->assertSame('<dl>Why &lt;now&gt;?</dl>', trim($faq->toHtml()));

        $this->assertSame('From the host', trim(view('seo::screens.note')->render()), 'The host overrides a plugin view under resources/views/vendor/<handle>.');

        $this->signIn($this->user(role: 'administrator'));
        $this->get(route('mainstay.entries.create', 'seo_redirect'))->assertOk()->assertSee('Search description')->assertSee('data-seo-meta', false);
    }

    #[Test]
    public function a_plugin_is_refused_a_name_something_else_has(): void
    {
        $this->refused(fn () => (new Registry)->loadPlugins([SeoRedirect::class]), SeoRedirect::class.' is not a Mainstay plugin');
        $this->refused(fn () => (new Registry)->loadPlugins([42]), 'int is not a Mainstay plugin');
        $this->refused(fn () => (new Registry)->loadPlugins([Unfinished::class]), Unfinished::class.' is abstract');

        Named::$handle = 'Seo-Tools';
        $this->refused(fn () => (new Registry)->loadPlugins([Named::class]), "a plugin's handle is lowercase letters, digits and underscores");

        /* An admin screen's segment, Mainstay's views, Laravel's mail, a
           error pages, a namespace Laravel registered, and one a plugin's
           views took. */
        foreach (['media', 'account', 'mainstay', 'mail', 'errors', 'pagination', 'seo'] as $taken) {
            Named::$handle = $taken;
            $this->refused(fn () => (new Registry)->loadPlugins([Named::class]), "is called \"{$taken}\", which the admin or a view namespace uses already");
        }

        Named::$handle = 'twin';
        $this->refused(fn () => (new Registry)->loadPlugins([Named::class, NamedToo::class]), 'Two plugins are called "twin": '.Named::class.' and '.NamedToo::class);

        $registry = new Registry;
        $registry->types([Post::class]);
        Named::$handle = 'post';
        $this->refused(fn () => $registry->loadPlugins([Named::class]), 'which the content type '.Post::class.' is called');

        $registry = new Registry;
        $registry->loadPlugins([Named::class]);
        $this->refused(fn () => $registry->types([Post::class]), Post::class.' would be called "post", which the plugin '.Named::class.' is called');
    }

    #[Test]
    public function a_plugins_screens_answer_inside_the_sign_in_to_whoever_holds_its_capability(): void
    {
        $this->assertSame('/admin/seo', route('mainstay.plugins.seo.index', absolute: false));
        $this->get('/admin/seo')->assertRedirect(route('mainstay.login'));

        $this->signIn($this->user(['edit_entries']));
        $this->get('/admin/seo')->assertNotFound()->assertDontSee('Every redirect');
        $this->get(route('mainstay.admin'))->assertOk()->assertDontSee('href="/admin/seo"', false);

        $this->signIn($this->user(['use_seo']));
        $this->get('/admin/seo')->assertOk()->assertSee('Every redirect');
        $this->get(route('mainstay.admin'))->assertOk()->assertSee('href="/admin/seo"', false)->assertSee('Seo');

        $this->signIn($this->user(role: 'administrator'));
        $this->get('/admin/seo')->assertOk();
        $this->post('/admin/seo')->assertOk()->assertSee('Saved the redirects');
    }

    #[Test]
    public function a_post_to_a_plugins_screen_without_the_forms_token_is_refused(): void
    {
        /* Outside tests, where Laravel's check would otherwise step aside. */
        $this->app['env'] = 'local';
        $this->signIn($this->user(['use_seo']));

        $this->post('/admin/seo')->assertStatus(419);
    }

    #[Test]
    public function a_plugins_routes_refuse_once_it_is_no_longer_loaded(): void
    {
        $this->signIn($this->user(role: 'administrator'));

        /* As a route cache built before the plugin left the config would. */
        $this->app->instance(Registry::class, new Registry);

        $this->get('/admin/seo')->assertNotFound()->assertDontSee('Every redirect');
    }

    #[Test]
    public function a_plugin_the_routes_never_took_in_is_left_out_of_the_sidebar_only_while_they_are_cached(): void
    {
        $this->signIn($admin = $this->user(role: 'administrator'));
        $registry = new Registry;
        $registry->loadPlugins([Late::class]);
        $this->app->instance(Registry::class, $registry);

        /* Cached before the config named it: the admin draws, without it,
           until route:cache runs again. */
        $this->app->instance('routes.cached', true);
        $this->get(route('mainstay.admin'))->assertOk()->assertDontSee('href="/admin/late"', false);

        /* Uncached, the routes are the config's, so a plugin with screens and
           no index is its author's mistake. Asked of the sidebar itself, as
           the admin's layout would ask it. */
        $this->app->instance('routes.cached', false);
        $this->app['request']->attributes->set(Gate::USER, $admin);
        $this->assertThrows(fn () => Navigation::sections(), LogicException::class, Late::class." has screens and no route named index, which is its item in the admin's sidebar. Name its first screen's route index.");
    }

    #[Test]
    public function a_plugins_capability_is_in_the_roles_grid_and_handed_out_only_by_a_holder(): void
    {
        $this->assertSame([Seo::class => 'use_seo'], Capabilities::plugins());
        $this->assertContains('use_seo', Mainstay::capabilities());

        $this->signIn($this->user(role: 'administrator'));
        $this->get(route('mainstay.roles.create'))->assertOk()->assertSeeInOrder(['Plugins', 'Use', 'Seo', 'value="use_seo"'], false);

        $manager = $this->user([Capabilities::USERS]);
        $this->app['request']->attributes->set(Gate::USER, $manager);
        $this->assertSame('use_seo', Capabilities::uncovered($manager, ['use_seo']));
        $this->assertThrows(fn () => Mainstay::accounts()->saveRole(null, 'seo', ['use_seo']), AuthorizationException::class, 'This hands out use_seo, which you do not hold.');

        $holder = $this->user([Capabilities::USERS, 'use_seo']);
        $this->app['request']->attributes->set(Gate::USER, $holder);
        $this->assertSame(['use_seo'], Mainstay::accounts()->saveRole(null, 'seo', ['use_seo'])->capabilities);

        /* A plugin without screens has nothing for a capability to open. */
        $registry = new Registry;
        $registry->loadPlugins([Named::class]);
        $this->app->instance(Registry::class, $registry);
        $this->assertSame([], Capabilities::plugins());
        $this->assertNotContains('use_named', $registry->capabilities());
    }

    #[Test]
    public function every_live_write_is_heard_by_the_plugin_with_its_type_and_id(): void
    {
        $id = Mainstay::create(SeoRedirect::class, data: ['title' => 'Old', 'target' => '/new'], overrideAccess: true)->id;
        Mainstay::update(SeoRedirect::class, $id, data: ['target' => '/newer'], overrideAccess: true);
        $global = Mainstay::saveGlobal(SeoSettings::class, data: ['siteName' => 'Mainstay'], locale: 'en', overrideAccess: true)->id;

        $draft = Mainstay::drafts()->save(SeoRedirect::class, ['target' => '/newest'], entry: $id, overrideAccess: true);
        $this->assertCount(3, Heard::$events, 'A draft is not live, so saving one is not heard.');

        Mainstay::drafts()->publish($draft->id, overrideAccess: true);
        Mainstay::delete(SeoRedirect::class, $id, overrideAccess: true);
        Mainstay::restore(SeoRedirect::class, $id, overrideAccess: true);

        $this->assertSame([
            [Saved::class, SeoRedirect::class, $id],
            [Saved::class, SeoRedirect::class, $id],
            [Saved::class, SeoSettings::class, $global],
            [Published::class, SeoRedirect::class, $id],
            [Deleted::class, SeoRedirect::class, $id],
            [Restored::class, SeoRedirect::class, $id],
        ], $this->heard());
    }

    #[Test]
    public function a_write_is_heard_after_its_callers_commit_and_never_when_it_rolls_back(): void
    {
        $id = DB::transaction(function () {
            $id = Mainstay::create(SeoRedirect::class, data: ['title' => 'Old', 'target' => '/new'], overrideAccess: true)->id;
            $this->assertSame([], Heard::$events, 'Heard before the commit made it so.');

            return $id;
        });
        $this->assertSame([[Saved::class, SeoRedirect::class, $id]], $this->heard());

        $this->assertThrows(fn () => DB::transaction(function () {
            Mainstay::create(SeoRedirect::class, data: ['title' => 'Gone', 'target' => '/gone'], overrideAccess: true);

            throw new RuntimeException('Rolled back.');
        }), RuntimeException::class);

        $this->assertSame([[Saved::class, SeoRedirect::class, $id]], $this->heard(), 'A rolled-back write is never heard.');

        $draft = Mainstay::drafts()->save(SeoRedirect::class, ['target' => '/newer'], entry: $id, overrideAccess: true);
        DB::transaction(function () use ($draft, $id) {
            Mainstay::drafts()->publish($draft->id, overrideAccess: true);
            Mainstay::delete(SeoRedirect::class, $id, overrideAccess: true);
            Mainstay::restore(SeoRedirect::class, $id, overrideAccess: true);
            $this->assertCount(1, Heard::$events, 'Each is heard after the commit, not before.');
        });
        $this->assertSame([Saved::class, Published::class, Deleted::class, Restored::class], array_column($this->heard(), 0));
    }
}
