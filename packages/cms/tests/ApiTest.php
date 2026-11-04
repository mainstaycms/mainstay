<?php

namespace Mainstay\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mainstay\Auth\Role;
use Mainstay\Auth\Token;
use Mainstay\Auth\User;
use Mainstay\Events\Saved;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Api\Colophon;
use Mainstay\Tests\Fixtures\Api\Essay;
use Mainstay\Tests\Fixtures\Api\Essayist;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;
use Mainstay\Tests\Fixtures\Policies\HoldingPolicy;
use Mainstay\Tests\Fixtures\Policies\OpenPolicy;
use Mainstay\Tests\Fixtures\Signed\Banner;
use PHPUnit\Framework\Attributes\Test;

/*
 | The phase 13 check over HTTP: who reads what with no token and with one,
 | a token holding no more than it was given nor than its account holds,
 | the payload -- moments, images, relations by depth, blocks -- held to the
 | schema /schema serves, and the writes, drafts among them, refused as the
 | admin's are.
 */
class ApiTest extends DatabaseTestCase
{
    private const API = '/api/mainstay';

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Essay::class, Essayist::class, Colophon::class, Person::class, Story::class, Genre::class, Banner::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function user(array $capabilities = []): User
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);

        return User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
    }

    /* A token's secret, for an account holding `$held`. */
    private function token(array $capabilities = [], array $held = ['*'], ?User $user = null): string
    {
        return Mainstay::tokens()->issue(($user ?? $this->user($held))->id, 'Build', $capabilities, overrideAccess: true)[1];
    }

    private static function bearer(?string $secret): array
    {
        return $secret === null ? [] : ['Authorization' => "Bearer {$secret}"];
    }

    private function read(string $path, ?string $secret = null): TestResponse
    {
        return $this->getJson(self::API.$path, self::bearer($secret));
    }

    /* An image row, as RelationTest writes one. */
    private function image(): int
    {
        return DB::table('mainstay_media')->insertGetId([
            'hash' => hash('sha256', Str::random()), 'type' => 'image/png', 'name' => 'cover.png', 'bytes' => 1, 'width' => 64, 'height' => 64,
            'focal_x' => 50, 'focal_y' => 50, 'alt' => json_encode(['en' => 'A cover']),
            'created_at' => '2026-10-07 00:00:00', 'updated_at' => '2026-10-07 00:00:00',
        ]);
    }

    /* An essay pointing at everything it can: its author, anyone may read;
       a person and a genre, and a story two blocks down, only a token may. */
    private function essay(array $data = []): Essay
    {
        $n = ++$this->made;
        $author = Mainstay::create(Essayist::class, ['title' => 'Ada', 'slug' => "ada-{$n}"], overrideAccess: true);
        $person = Mainstay::create(Person::class, ['title' => 'Grace', 'slug' => "grace-{$n}"], overrideAccess: true);
        $genre = Mainstay::create(Genre::class, ['title' => 'Noir', 'slug' => "noir-{$n}"], overrideAccess: true);
        $story = Mainstay::create(Story::class, ['title' => 'Told', 'slug' => "told-{$n}"], overrideAccess: true);

        return Mainstay::create(Essay::class, [
            'title' => 'On tokens', 'slug' => "on-tokens-{$n}", 'publishedAt' => '2026-09-10 08:30:00+02:00',
            'cover' => $this->image(), 'author' => $author->id,
            'picks' => [['type' => 'essayist', 'id' => $author->id], ['type' => 'person', 'id' => $person->id]],
            'genres' => [$genre->id], 'note' => 'Quiet',
            'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]]],
            'content' => ['type' => 'doc', 'content' => [['type' => 'block', 'attrs' => ['type' => 'shelf'], 'content' => [['type' => 'block', 'attrs' => ['type' => 'spine', 'data' => ['story' => $story->id]]]]]]],
            ...$data,
        ], overrideAccess: true);
    }

    #[Test]
    public function nobody_reads_only_the_types_marked_public_read(): void
    {
        $essay = $this->essay();

        $this->read('/entries/essay')->assertOk()->assertJsonPath('data.0.id', $essay->id)->assertJsonPath('meta.total', 1);
        $this->read('/entries/person')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        $this->read('/globals/banner')->assertUnauthorized();
        $this->read('/globals/colophon')->assertNotFound();
        $this->assertSame(['essay', 'essayist', 'colophon'], array_column($this->read('/types')->json('data'), 'handle'));
        $this->read('/types/person')->assertUnauthorized();

        $this->assertSame(['essay', 'essayist', 'colophon', 'person', 'story', 'genre', 'banner'], array_column($this->read('/types', $this->token())->json('data'), 'handle'));
    }

    #[Test]
    public function what_only_a_token_reads_is_its_id_to_nobody(): void
    {
        $essay = $this->essay();
        $secret = $this->token();

        $public = $this->read("/entries/essay/{$essay->id}")->assertOk();
        $this->assertSame('Ada', $public->json('author.title'));
        $this->assertSame('Ada', $public->json('picks.0.title'));
        $this->assertSame(['type' => 'person', 'id' => $essay->picks[1]->id], $public->json('picks.1'));
        $this->assertSame([$essay->genres[0]->id], $public->json('genres'));
        $this->assertIsInt($public->json('content.content.0.content.0.attrs.data.story'));
        $this->assertEquals((object) [], json_decode($public->getContent())->content->content[0]->attrs->data, 'A block with no fields has data all the same.');

        $read = $this->read("/entries/essay/{$essay->id}", $secret)->assertOk();
        $this->assertSame('Grace', $read->json('picks.1.title'));
        $this->assertSame('Noir', $read->json('genres.0.title'));
        $this->assertSame('Told', $read->json('content.content.0.content.0.attrs.data.story.title'));
    }

    #[Test]
    public function a_path_into_a_type_nobody_reads_is_not_there_to_nobody(): void
    {
        $person = Mainstay::create(Person::class, ['title' => 'Grace', 'slug' => 'grace'], overrideAccess: true);

        $this->read('/path?uri=/people/grace')->assertNotFound();
        $this->read('/path?uri=/people/grace', $this->token())->assertOk()->assertJsonPath('id', $person->id)->assertJsonPath('type', 'person');
    }

    #[Test]
    public function nobody_never_reads_an_internal_field_whatever_the_policy_says(): void
    {
        Gate::policy(Essay::class, OpenPolicy::class);
        $essay = $this->essay();

        $this->assertArrayNotHasKey('note', $this->read("/entries/essay/{$essay->id}")->json());
        $this->assertArrayNotHasKey('note', $this->read('/schema')->json('$defs.essay.properties'));
        $this->assertArrayNotHasKey('note', $this->read('/types/essay')->json('input.properties'));
        $this->assertSame('Quiet', $this->read("/entries/essay/{$essay->id}", $this->token())->json('note'));
    }

    #[Test]
    public function nobody_writes_nothing_and_an_unknown_token_is_refused(): void
    {
        $this->postJson(self::API.'/entries/essay', ['data' => ['title' => 'Mine', 'slug' => 'mine']])
            ->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        $this->assertSame(0, DB::table('essay')->count());

        $this->read('/entries/essay', 'mst_'.Str::random(48))->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        $this->read('/entries/essay', 'not-even-ours')->assertUnauthorized();
    }

    #[Test]
    public function a_token_holds_only_what_it_was_given(): void
    {
        $user = $this->user(['edit_essays', 'publish_essays']);
        $drafts = $this->token(['edit_essays'], user: $user);
        $publishes = $this->token(['edit_essays', 'publish_essays'], user: $user);
        $write = ['data' => ['title' => 'Mine', 'slug' => 'mine']];

        $this->postJson(self::API.'/entries/essay', $write, self::bearer($drafts))->assertForbidden();
        $this->postJson(self::API.'/drafts', ['type' => 'essay', ...$write], self::bearer($drafts))->assertOk();
        $this->postJson(self::API.'/entries/essay', $write, self::bearer($publishes))->assertCreated()->assertJsonPath('title', 'Mine');
    }

    #[Test]
    public function a_token_sees_internal_fields_only_holding_what_shows_them(): void
    {
        $essay = $this->essay();
        $user = $this->user(['edit_essays']);
        $reads = $this->token(user: $user);
        $edits = $this->token(['edit_essays'], user: $user);

        $this->assertArrayNotHasKey('note', $this->read("/entries/essay/{$essay->id}", $reads)->json());
        $this->assertSame('Quiet', $this->read("/entries/essay/{$essay->id}", $edits)->json('note'));

        /* What the account no longer holds, its tokens no longer do. */
        $user->role->update(['capabilities' => []]);
        $this->assertArrayNotHasKey('note', $this->read("/entries/essay/{$essay->id}", $edits)->json());
    }

    #[Test]
    public function a_host_policy_asking_holds_is_bound_by_the_token(): void
    {
        Gate::policy(Essay::class, HoldingPolicy::class);
        $essay = $this->essay();
        $user = $this->user(['edit_essays']);

        $this->assertArrayNotHasKey('note', $this->read("/entries/essay/{$essay->id}", $this->token(user: $user))->json());
        $this->assertSame('Quiet', $this->read("/entries/essay/{$essay->id}", $this->token(['edit_essays'], user: $user))->json('note'));
    }

    #[Test]
    public function a_revoked_token_and_one_whose_account_is_deleted_are_refused(): void
    {
        $user = $this->user();
        [$token, $secret] = Mainstay::tokens()->issue($user->id, 'Build', [], overrideAccess: true);
        $this->read('/entries/essay', $secret)->assertOk();

        Mainstay::tokens()->revoke($token->id, overrideAccess: true);
        $this->read('/entries/essay', $secret)->assertUnauthorized();

        $other = $this->token(user: $user);
        $user->delete();
        $this->assertSame(0, Token::query()->count());
        $this->read('/entries/essay', $other)->assertUnauthorized();
    }

    #[Test]
    public function a_token_is_stamped_used_once_a_minute_at_most(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
        $secret = $this->token();
        $this->assertNull(Token::query()->sole()->last_used_at);

        $this->read('/entries/essay', $secret);
        $this->assertSame('2026-11-02 10:00:00', Token::query()->sole()->last_used_at->format('Y-m-d H:i:s'));

        $this->travel(30)->seconds();
        $this->read('/entries/essay', $secret);
        $this->assertSame('2026-11-02 10:00:00', Token::query()->sole()->last_used_at->format('Y-m-d H:i:s'));

        $this->travel(31)->seconds();
        $this->read('/entries/essay', $secret);
        $this->assertSame('2026-11-02 10:01:01', Token::query()->sole()->last_used_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_payload_writes_moments_in_utc_and_images_with_their_sizes(): void
    {
        $essay = $this->essay();
        $read = $this->read("/entries/essay/{$essay->id}")->assertOk();

        $this->assertSame('2026-09-10T06:30:00Z', $read->json('publishedAt'));
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $read->json('createdAt'));
        $this->assertSame('/essays/'.$essay->slug, $read->json('uri'));
        $this->assertSame('A cover', $read->json('cover.alt'));
        $this->assertSame(32, $read->json('cover.sizes.card.width'));
        $this->assertStringEndsWith('.webp', $read->json('cover.sizes.card.webp'));
        $this->assertSame($read->json('cover.sizes.card.webp'), $read->json('cover.sizes.card.src'));
        $this->assertStringEndsWith('.avif', $read->json('cover.sizes.card.avif'));
        $this->assertSame('Hello', $read->json('body.content.0.content.0.text'));
    }

    #[Test]
    public function depth_says_how_far_relations_are_loaded_up_to_a_cap(): void
    {
        $essay = $this->essay();
        $secret = $this->token();

        $this->assertSame($essay->author->id, $this->read("/entries/essay/{$essay->id}?depth=0")->json('author'));
        $this->read("/entries/essay/{$essay->id}?depth=2")->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'without a token'));

        Mainstay::update(Essayist::class, $essay->author->id, ['essays' => [$essay->id]], overrideAccess: true);
        $this->assertSame([$essay->id], $this->read("/entries/essay/{$essay->id}")->json('author.essays'));
        $this->assertSame('On tokens', $this->read("/entries/essay/{$essay->id}?depth=2", $secret)->json('author.essays.0.title'));
        $this->read("/entries/essay/{$essay->id}?depth=4", $secret)->assertUnprocessable();
        $this->read('/entries/essay?perPage=101', $secret)->assertUnprocessable();
        $this->read('/entries/essay?page=0', $secret)->assertUnprocessable();
        $this->read('/entries/essay?depth=deep', $secret)->assertUnprocessable();
    }

    #[Test]
    public function every_payload_is_what_the_schema_says(): void
    {
        $essay = $this->essay();
        Mainstay::saveGlobal(Colophon::class, ['text' => 'Set in Inter'], locale: 'en', overrideAccess: true);

        $public = ["/entries/essay/{$essay->id}" => 'essay', "/entries/essayist/{$essay->author->id}" => 'essayist', '/globals/colophon' => 'colophon'];
        $tokened = [...$public, '/entries/story/'.DB::table('story')->value('id') => 'story', '/entries/person/'.DB::table('person')->value('id') => 'person'];

        foreach ([[null, $public], [$this->token(), $tokened]] as [$secret, $paths]) {
            $defs = $this->read('/schema', $secret)->assertOk()->json('$defs');

            foreach ($paths as $path => $handle) {
                $payload = json_decode($this->read($path, $secret)->assertOk()->getContent(), false);
                $this->assertNull(self::mismatch($payload, $defs[$handle], $defs, $handle), "{$path} as ".($secret === null ? 'nobody' : 'a token'));
            }
        }
    }

    #[Test]
    public function the_payloads_are_written_out_as_typescript(): void
    {
        $path = 'mainstay-'.Str::random(8).'.d.ts';
        $this->artisan('mainstay:typescript', ['path' => $path])->assertSuccessful();
        $declared = file_get_contents(base_path($path));
        unlink(base_path($path));

        $this->assertStringContainsString("export interface Essay {\n    id: number;\n    type: \"essay\";\n", $declared);
        $this->assertStringContainsString("    publishedAt: string | null;\n", $declared);
        $this->assertStringContainsString("    author: number | null | Essayist;\n", $declared);
        $this->assertStringContainsString("    cover: number | null | Media;\n", $declared);
        $this->assertStringContainsString("    genres: (number | Genre)[];\n", $declared);
        $this->assertStringContainsString("export interface Node {\n", $declared);
        $this->assertStringNotContainsString('note', $declared, 'Written as a token holding nothing reads it.');
    }

    #[Test]
    public function writes_go_through_the_query_layer_and_are_heard(): void
    {
        Event::fake([Saved::class]);
        $secret = $this->token(['*']);

        $made = $this->postJson(self::API.'/entries/essay', ['data' => ['title' => 'Mine', 'slug' => 'mine', 'publishedAt' => '2026-09-10T06:30:00Z']], self::bearer($secret))
            ->assertCreated()->assertJsonPath('publishedAt', '2026-09-10T06:30:00Z');
        $id = $made->json('id');
        Event::assertDispatched(Saved::class, fn (Saved $event) => $event->type === Essay::class && $event->id === $id);

        $this->patchJson(self::API."/entries/essay/{$id}", ['data' => ['title' => 'Ours']], self::bearer($secret))->assertOk()->assertJsonPath('title', 'Ours');
        $this->putJson(self::API.'/globals/colophon', ['data' => ['text' => 'Set in Inter'], 'locale' => 'en'], self::bearer($secret))->assertOk()->assertJsonPath('text', 'Set in Inter');
        $this->deleteJson(self::API."/entries/essay/{$id}", [], self::bearer($secret))->assertNoContent();
        $this->read("/entries/essay/{$id}", $secret)->assertNotFound();

        $this->postJson(self::API.'/entries/essay', ['data' => ['title' => 'Mine']], self::bearer($secret))
            ->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $this->postJson(self::API.'/entries/essay', ['data' => ['title' => 'Mine', 'slug' => 'mine', 'colour' => 'red']], self::bearer($secret))->assertUnprocessable();
        $this->postJson(self::API.'/entries/essay', ['data' => [1, 2]], self::bearer($secret))->assertUnprocessable();
        $this->postJson(self::API.'/entries/colophon', ['data' => ['text' => 'No']], self::bearer($secret))->assertNotFound();
        $this->read('/entries/nothing', $secret)->assertNotFound();
    }

    #[Test]
    public function a_draft_is_saved_read_published_and_discarded(): void
    {
        $secret = $this->token(['*']);

        $draft = $this->postJson(self::API.'/drafts', ['type' => 'essay', 'data' => ['title' => 'Soon', 'slug' => 'soon']], self::bearer($secret))->assertOk();
        $this->assertNull($draft->json('entryId'));
        $this->assertArrayNotHasKey('id', $draft->json('entry'));
        $this->assertSame('Soon', $draft->json('entry.title'));
        $this->read('/entries/essay', $secret)->assertJsonPath('meta.total', 0);

        $read = $this->read('/drafts/'.$draft->json('id'), $secret)->assertOk()->assertJsonPath('entry.title', 'Soon');
        $this->assertEqualsCanonicalizing(['title', 'slug'], $read->json('locales.en'));
        $live = $this->postJson(self::API.'/drafts/'.$draft->json('id').'/publish', [], self::bearer($secret))->assertOk()->assertJsonPath('title', 'Soon');
        $this->read('/entries/essay', $secret)->assertJsonPath('meta.total', 1);

        $changed = $this->postJson(self::API.'/drafts', ['type' => 'essay', 'entry' => $live->json('id'), 'data' => ['title' => 'Later']], self::bearer($secret))->assertOk();
        $this->read('/drafts?type=essay&entry='.$live->json('id'), $secret)->assertOk()->assertJsonPath('id', $changed->json('id'));
        $this->deleteJson(self::API.'/drafts/'.$changed->json('id'), [], self::bearer($secret))->assertNoContent();
        $this->read('/drafts/'.$changed->json('id'), $secret)->assertNotFound();
        $this->read('/entries/essay/'.$live->json('id'), $secret)->assertJsonPath('title', 'Soon');

        /* A draft changing nothing is none. */
        $this->postJson(self::API.'/drafts', ['type' => 'essay', 'entry' => $live->json('id'), 'data' => ['title' => 'Soon']], self::bearer($secret))->assertNoContent();
    }

    #[Test]
    public function a_listing_takes_where_and_sort_from_the_query_string(): void
    {
        $secret = $this->token();

        foreach (['Bravo' => '2026-01-02 00:00:00', 'Alpha' => '2026-01-03 00:00:00', 'Charlie' => '2026-01-01 00:00:00'] as $title => $at) {
            Mainstay::create(Essay::class, ['title' => $title, 'slug' => Str::slug($title), 'publishedAt' => $at], overrideAccess: true);
        }

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($this->read('/entries/essay?sort=title', $secret)->json('data'), 'title'));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($this->read('/entries/essay?sort=-publishedAt', $secret)->json('data'), 'title'));
        $this->assertSame(['Bravo'], array_column($this->read('/entries/essay?where='.urlencode('{"title": "Bravo"}'), $secret)->json('data'), 'title'));
        $this->assertSame(['Alpha'], array_column($this->read('/entries/essay?where='.urlencode('{"publishedAt": {">": "2026-01-02 12:00:00"}}'), $secret)->json('data'), 'title'));
        $this->read('/entries/essay?perPage=2&page=2&sort=title', $secret)->assertJsonPath('data.0.title', 'Charlie')->assertJsonPath('meta.lastPage', 2);

        $this->read('/entries/essay?where='.urlencode('{"title": {"~": "B"}}'), $secret)->assertUnprocessable();
        $this->read('/entries/essay?where='.urlencode('{"colour": "red"}'), $secret)->assertUnprocessable();
        $this->read('/entries/essay?where={nope', $secret)->assertUnprocessable();
        $this->read('/entries/essay?sort=colour', $secret)->assertUnprocessable();
    }

    /*
     | Where a payload departs from its schema, by path, or null: the part of
     | JSON Schema /schema is written in, which is all a payload needs holding
     | to. The payload is decoded with its objects as objects, so `{}` is told
     | from `[]`.
     */
    private static function mismatch(mixed $value, array $schema, array $defs, string $path): ?string
    {
        if (isset($schema['$ref'])) {
            return self::mismatch($value, $defs[substr($schema['$ref'], strlen('#/$defs/'))], $defs, $path);
        }

        if (isset($schema['anyOf'])) {
            $each = array_map(fn (array $one) => self::mismatch($value, $one, $defs, $path), $schema['anyOf']);

            return in_array(null, $each, true) ? null : implode('; or ', $each);
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return "{$path} is not ".json_encode($schema['const']);
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return "{$path} is none of ".json_encode($schema['enum']);
        }

        $is = match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => 'object',
        };
        $types = (array) ($schema['type'] ?? []);

        if ($types !== [] && ! in_array($is, $types, true) && ! ($is === 'integer' && in_array('number', $types, true))) {
            return "{$path} is {$is}, not ".implode(' or ', $types);
        }

        if (is_object($value)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! property_exists($value, $key)) {
                    return "{$path}.{$key} is missing";
                }
            }

            foreach (get_object_vars($value) as $key => $item) {
                $property = $schema['properties'][$key] ?? $schema['additionalProperties'] ?? true;

                if ($property === false) {
                    return "{$path}.{$key} is not in the schema";
                }

                if (is_array($property) && ($mismatch = self::mismatch($item, $property, $defs, "{$path}.{$key}")) !== null) {
                    return $mismatch;
                }
            }
        }

        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                if (($mismatch = self::mismatch($item, $schema['items'], $defs, "{$path}[{$i}]")) !== null) {
                    return $mismatch;
                }
            }
        }

        return null;
    }
}
