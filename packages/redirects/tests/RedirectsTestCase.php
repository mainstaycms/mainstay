<?php

namespace Mainstay\Redirects\Tests;

use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Plugin;
use Mainstay\Redirects\Redirect;
use Mainstay\Redirects\RedirectsPlugin;
use Mainstay\Redirects\RedirectStore;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Page;
use Mainstay\Tests\Fixtures\Post;

/*
 | A site with the site plugin and this one, in English and Dutch, whose
 | posts live at /blog/{slug} and /nieuws/{slug} and whose pages at
 | /{section}/{slug}: addresses to move, trash and restore.
 */
abstract class RedirectsTestCase extends DatabaseTestCase
{
    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', $this->plugins());
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
        $app['view']->addLocation(dirname(__DIR__, 2).'/cms/tests/Fixtures/views');
    }

    /** @return list<class-string<Plugin>> */
    protected function plugins(): array
    {
        return [SitePlugin::class, RedirectsPlugin::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mainstay::types([Post::class, Page::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    protected function makePost(string $slug = 'hello', array $data = []): Post
    {
        return Mainstay::create(Post::class, ['title' => ucfirst($slug), 'slug' => $slug, 'status' => 'live', ...$data], locale: 'en', overrideAccess: true);
    }

    protected function translate(Post $post, string $slug): void
    {
        Mainstay::update(Post::class, $post->id, ['title' => ucfirst($slug), 'slug' => $slug, 'status' => 'live'], locale: 'nl', overrideAccess: true);
    }

    protected function rename(Post $post, string $slug, string $locale = 'en'): void
    {
        Mainstay::update(Post::class, $post->id, ['slug' => $slug], locale: $locale, overrideAccess: true);
    }

    protected function rule(string $source, ?string $target, array $attributes = []): Redirect
    {
        return app(RedirectStore::class)->save(new Redirect([
            'source' => $source,
            'match_type' => Redirect::EXACT,
            'target' => $target,
            'status' => $target === null ? Redirect::GONE : Redirect::DEFAULT_STATUS,
            'origin' => Redirect::CUSTOM,
            'preserve_query' => true,
            'enabled' => true,
            'priority' => Redirect::DEFAULT_PRIORITY,
            ...$attributes,
        ]));
    }

    protected function pattern(string $pattern, string $target, array $attributes = []): Redirect
    {
        return $this->rule($pattern, $target, ['match_type' => Redirect::REGEX, ...$attributes]);
    }

    protected function signIn(array $capabilities): User
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user, 'mainstay');

        return $user;
    }
}
