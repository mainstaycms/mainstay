<?php

namespace Mainstay\Tests;

use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Post;
use PHPUnit\Framework\Attributes\Test;

/* Core with no plugin: a type that declares a #[Route] is stored as any
   other, and nothing in core knows what an address is. */
class NoPathsTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Post::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function entry(string $slug = 'hello'): Post
    {
        return Mainstay::create(Post::class, ['title' => 'Hello', 'slug' => $slug, 'status' => 'live'], locale: 'en', overrideAccess: true);
    }

    #[Test]
    public function a_routed_type_saves_and_reads_back_without_a_path(): void
    {
        $created = $this->entry();

        $this->assertNull($created->uri);
        $this->assertNull($created->url());
        $this->assertNull(Mainstay::findById(Post::class, $created->id, locale: 'en', overrideAccess: true)->uri);
        $this->assertNull(Mainstay::find(Post::class, locale: 'en', overrideAccess: true)->first()->uri);
    }

    #[Test]
    public function core_keeps_no_lookup_table_and_no_front_page(): void
    {
        $created = $this->entry();

        $this->assertFalse(Schema::hasTable('uris'));
        $this->assertFalse(Schema::hasColumn('sites', 'front_type'));
        $this->assertNull(Mainstay::findByUri('/blog/hello', locale: 'en', overrideAccess: true));
        $this->assertNull(Mainstay::frontPage());
        $this->assertThrows(fn () => Mainstay::setFrontPage(Post::class, $created->id, overrideAccess: true), InvalidArgumentException::class, 'has no path');
        Mainstay::setFrontPage(null);
    }

    #[Test]
    public function the_trash_and_a_restore_work_with_no_path_to_take_or_give_back(): void
    {
        $created = $this->entry();

        Mainstay::delete(Post::class, $created->id, overrideAccess: true);

        $this->assertSame([], Mainstay::restore(Post::class, $created->id, overrideAccess: true));

        Mainstay::delete(Post::class, $created->id, overrideAccess: true);
        Mainstay::destroy(Post::class, $created->id, overrideAccess: true);

        $this->assertNull(Mainstay::findById(Post::class, $created->id, locale: 'en', overrideAccess: true));
    }

    #[Test]
    public function a_slug_is_not_held_to_a_path_segment(): void
    {
        $this->assertSame('Not A Slug', $this->entry('Not A Slug')->slug);
    }
}
