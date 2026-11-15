<?php

namespace Mainstay\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Events\Saved;
use Mainstay\Facades\Mainstay;
use Mainstay\Hooks;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\Fixtures\Api\Colophon;
use Mainstay\Tests\Fixtures\Api\Essay;
use Mainstay\Tests\Fixtures\Api\Essayist;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;
use Mainstay\Tests\Fixtures\Plugins\Hooked;
use Mainstay\Tests\Fixtures\Signed\Banner;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/*
 | The hooks a plugin hangs on content, through the store itself and over the
 | REST API, which reach them by the same code: a before hook may change what
 | is written or refuse it, with nothing written, and an after hook is heard
 | once the write has committed.
 */
class HookedContentTest extends DatabaseTestCase
{
    private const API = '/api/mainstay';

    private const TOO_LONG = 'A title far past the limit';

    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, Hooked::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Hooked::$heard = [];
        Mainstay::types([Essay::class, Essayist::class, Colophon::class, Person::class, Story::class, Genre::class, Banner::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function token(array $held = ['*']): string
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $held]);
        $user = User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);

        return Mainstay::tokens()->issue($user->id, 'Build', $held, overrideAccess: true)[1];
    }

    private function send(string $method, string $path, array $body, string $secret): TestResponse
    {
        return $this->json($method, self::API.$path, $body, ['Authorization' => "Bearer {$secret}"]);
    }

    private function essay(string $title = 'On tokens'): Essay
    {
        return Mainstay::create(Essay::class, ['title' => $title, 'slug' => str($title)->slug()->toString()], overrideAccess: true);
    }

    #[Test]
    public function a_before_filter_changes_what_is_written_and_an_after_action_hears_the_id(): void
    {
        $essay = $this->essay('  Padded  ');

        $this->assertSame('Padded', $essay->title);
        $this->assertSame(["content.created:{$essay->id}"], Hooked::$heard);
    }

    #[Test]
    public function a_vetoing_before_hook_leaves_no_row_no_revision_and_no_event(): void
    {
        $essay = $this->essay();
        Hooked::$heard = [];
        $revisions = DB::table('mainstay_revisions')->count();
        Event::fake([Saved::class]);

        foreach ([
            fn () => Mainstay::create(Essay::class, ['title' => self::TOO_LONG, 'slug' => 'long'], overrideAccess: true),
            fn () => Mainstay::update(Essay::class, $essay->id, ['title' => self::TOO_LONG], overrideAccess: true),
            fn () => Mainstay::drafts()->save(Essay::class, ['title' => self::TOO_LONG], $essay->id, overrideAccess: true),
        ] as $write) {
            try {
                $write();
                $this->fail('The hook did not refuse.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('title', $exception->errors());
            }
        }

        $this->assertThrows(fn () => Mainstay::create(Essay::class, ['title' => 'forbidden', 'slug' => 'f'], overrideAccess: true), AuthorizationException::class);

        $this->assertSame(1, DB::table(Essay::handle())->count());
        $this->assertSame('On tokens', Mainstay::findById(Essay::class, $essay->id)->title);
        $this->assertSame($revisions, DB::table('mainstay_revisions')->count());
        $this->assertSame(0, DB::table('mainstay_drafts')->count());
        $this->assertSame([], Hooked::$heard);
        Event::assertNotDispatched(Saved::class);
    }

    #[Test]
    public function an_after_hook_waits_for_the_commit_and_never_hears_a_rollback(): void
    {
        try {
            DB::transaction(function () {
                $this->essay();
                $this->assertSame([], Hooked::$heard, 'Heard before the commit.');

                throw new RuntimeException('Rolled back.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], Hooked::$heard);

        DB::transaction(function () {
            $this->essay('Kept');
            $this->assertSame([], Hooked::$heard);
        });

        $this->assertCount(1, Hooked::$heard);
    }

    #[Test]
    public function every_write_has_its_before_and_after_hook(): void
    {
        $essay = $this->essay();
        Hooked::$heard = [];
        $id = $essay->id;

        Mainstay::update(Essay::class, $id, ['title' => 'Renamed'], overrideAccess: true);
        $draft = Mainstay::drafts()->save(Essay::class, ['title' => 'Drafted'], $id, overrideAccess: true);
        Mainstay::drafts()->publish($draft->id, overrideAccess: true);
        Mainstay::delete(Essay::class, $id, overrideAccess: true);
        Mainstay::restore(Essay::class, $id, overrideAccess: true);
        Mainstay::delete(Essay::class, $id, overrideAccess: true);
        Mainstay::destroy(Essay::class, $id, overrideAccess: true);

        $this->assertSame([
            "content.updated:{$id}", "content.draft-saved:{$draft->id}", 'content.publishing', "content.published:{$id}",
            'content.trashing', "content.trashed:{$id}", 'content.restoring', "content.restored:{$id}",
            'content.trashing', "content.trashed:{$id}", 'content.deleting', "content.deleted:{$id}",
        ], Hooked::$heard);
    }

    #[Test]
    public function the_rest_api_refuses_what_a_before_hook_refuses_as_it_refuses_its_own(): void
    {
        $secret = $this->token();

        $this->send('POST', '/entries/essay', ['data' => ['title' => self::TOO_LONG, 'slug' => 'long']], $secret)
            ->assertStatus(422)->assertJsonPath('errors.title.0', 'A title is at most 12 characters.');
        $this->send('POST', '/entries/essay', ['data' => ['title' => 'forbidden', 'slug' => 'f']], $secret)
            ->assertStatus(403)->assertJsonPath('message', 'Plugin says no.');

        $this->assertSame(0, DB::table(Essay::handle())->count());
    }

    #[Test]
    public function a_payload_filter_and_a_query_filter_shape_what_the_api_reads(): void
    {
        $shown = $this->essay('Shown');
        $this->essay('Hidden');

        $list = $this->getJson(self::API.'/entries/essay')->assertOk();
        $this->assertSame(['Shown'], array_column($list->json('data'), 'title'));
        $this->assertSame('SHOWN', $list->json('data.0.shout'));
        $this->getJson(self::API."/entries/essay/{$shown->id}")->assertOk()->assertJsonPath('shout', 'SHOWN');
    }

    #[Test]
    public function a_plugin_route_is_behind_the_apis_token_and_the_stores_gate(): void
    {
        $this->getJson(self::API.'/hooked/whoami')->assertOk()->assertJsonPath('user', null);
        $this->getJson(self::API.'/hooked/whoami', ['Authorization' => 'Bearer mst_unknown'])->assertUnauthorized();

        $this->send('POST', '/hooked/essays', ['data' => ['title' => 'Mine', 'slug' => 'mine']], $this->token([]))->assertForbidden();
        $this->send('POST', '/hooked/essays', ['data' => ['title' => ' Mine ', 'slug' => 'mine']], $this->token())->assertOk()->assertJsonPath('title', 'Mine');
    }

    #[Test]
    public function a_host_hooks_in_with_the_facade(): void
    {
        Mainstay::addFilter('content.payload', fn (array $payload) => [...$payload, 'host' => true], 99);

        $this->assertTrue(app(Hooks::class)->applyFilters('content.payload', [])['host']);
    }
}
