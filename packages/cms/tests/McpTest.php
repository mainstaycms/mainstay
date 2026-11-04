<?php

namespace Mainstay\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Enums\MetaKey;
use Mainstay\Api\Operations;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Api\Colophon;
use Mainstay\Tests\Fixtures\Api\Essay;
use Mainstay\Tests\Fixtures\Api\Essayist;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;
use Mainstay\Tests\Fixtures\Signed\Banner;
use PHPUnit\Framework\Attributes\Test;

/*
 | The API as MCP tools, over HTTP as a client posts JSON-RPC: the tools a
 | caller is offered and the types each takes, a call's payload, and every
 | refusal an error result the model reads -- nobody writing, a capability
 | the token lacks, a field that did not take its value, a call the query
 | layer does not take.
 */
class McpTest extends DatabaseTestCase
{
    private const MCP = '/api/mainstay/mcp';

    private const VERSION = '2026-07-28';

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Essay::class, Essayist::class, Colophon::class, Person::class, Story::class, Genre::class, Banner::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function token(array $capabilities = [], array $held = ['*']): string
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $held]);
        $user = User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);

        return Mainstay::tokens()->issue($user->id, 'Agent', $capabilities, overrideAccess: true)[1];
    }

    /* A request as a client on the current protocol sends one: the method
       and the name in headers beside the body, as a proxy routes on. */
    private function rpc(string $method, array $params = [], ?string $secret = null): TestResponse
    {
        return $this->postJson(self::MCP, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => [...$params, '_meta' => [MetaKey::PROTOCOL_VERSION->value => self::VERSION, MetaKey::CLIENT_CAPABILITIES->value => (object) []]],
        ], [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::VERSION,
            'Mcp-Method' => $method,
            ...(isset($params['name']) ? ['Mcp-Name' => $params['name']] : []),
            ...($secret === null ? [] : ['Authorization' => "Bearer {$secret}"]),
        ]);
    }

    private function tool(string $tool, array $arguments = [], ?string $secret = null): TestResponse
    {
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments], $secret)->assertOk();
    }

    /** @return array<string, array<string, mixed>> the tools offered, by name */
    private function tools(?string $secret = null): array
    {
        return array_column($this->rpc('tools/list', secret: $secret)->assertOk()->json('result.tools'), null, 'name');
    }

    private function refusal(TestResponse $response): string
    {
        $this->assertTrue($response->json('result.isError'), $response->getContent());

        return $response->json('result.content.0.text');
    }

    #[Test]
    public function a_tool_takes_only_the_types_its_caller_may_read(): void
    {
        $nobody = $this->tools();
        $token = $this->tools($this->token());

        $this->assertSame(['list-types', 'describe-type', 'find-entries', 'get-entry', 'get-entry-by-path', 'get-global', 'get-draft', 'save-draft', 'publish-draft', 'discard-draft', 'create-entry', 'update-entry', 'trash-entry', 'save-global'], array_keys($nobody));
        $this->assertSame(['essay', 'essayist'], $nobody['find-entries']['inputSchema']['properties']['type']['enum']);
        $this->assertSame(['colophon'], $nobody['get-global']['inputSchema']['properties']['type']['enum']);
        $this->assertSame(['essay', 'essayist', 'person', 'story', 'genre'], $token['find-entries']['inputSchema']['properties']['type']['enum']);
        $this->assertSame(['colophon', 'banner'], $token['save-global']['inputSchema']['properties']['type']['enum']);
        $this->assertContains('type', $token['find-entries']['inputSchema']['required']);

        $this->assertTrue($nobody['find-entries']['annotations']['readOnlyHint']);
        $this->assertTrue($nobody['trash-entry']['annotations']['destructiveHint']);
        $this->assertArrayNotHasKey('readOnlyHint', $nobody['save-draft']['annotations'] ?? []);
    }

    #[Test]
    public function a_read_answers_with_the_payload_as_structured_content(): void
    {
        $author = Mainstay::create(Essayist::class, ['title' => 'Ada', 'slug' => 'ada'], overrideAccess: true);
        Mainstay::create(Essay::class, ['title' => 'On tokens', 'slug' => 'on-tokens', 'author' => $author->id], overrideAccess: true);

        $found = $this->tool('find-entries', ['type' => 'essay', 'sort' => ['-title']]);
        $this->assertSame('On tokens', $found->json('result.structuredContent.data.0.title'));
        $this->assertSame('Ada', $found->json('result.structuredContent.data.0.author.title'));
        $this->assertSame(1, $found->json('result.structuredContent.meta.total'));

        $this->assertSame('Ada', $this->tool('get-entry-by-path', ['path' => '/essayists/ada'])->json('result.structuredContent.title'));
        $this->assertSame('essay', $this->tool('list-types')->json('result.structuredContent.types.0.handle'));
    }

    #[Test]
    public function a_token_that_drafts_cannot_publish(): void
    {
        $secret = $this->token(['edit_essays'], held: ['edit_essays', 'publish_essays']);

        $draft = $this->tool('save-draft', ['type' => 'essay', 'data' => ['title' => 'Soon', 'slug' => 'soon']], $secret);
        $id = $draft->json('result.structuredContent.id');
        $this->assertSame('Soon', $draft->json('result.structuredContent.entry.title'));
        $this->assertSame('Soon', $this->tool('get-draft', ['id' => $id], $secret)->json('result.structuredContent.entry.title'));

        $this->assertStringContainsString('publish_essays', $this->refusal($this->tool('publish-draft', ['id' => $id], $secret)));
        $this->assertStringContainsString('publish_essays', $this->refusal($this->tool('create-entry', ['type' => 'essay', 'data' => ['title' => 'Now', 'slug' => 'now']], $secret)));
        $this->assertSame(0, DB::table('essay')->count());

        $this->assertSame('Done.', $this->tool('discard-draft', ['id' => $id], $secret)->json('result.content.0.text'));
    }

    #[Test]
    public function every_refusal_is_an_error_result_saying_why(): void
    {
        $secret = $this->token(['*']);

        $this->assertSame(Operations::NOBODY, $this->refusal($this->tool('create-entry', ['type' => 'essay', 'data' => ['title' => 'Mine', 'slug' => 'mine']])));
        $this->assertStringContainsString('slug: ', $this->refusal($this->tool('create-entry', ['type' => 'essay', 'data' => ['title' => 'Mine']], $secret)));
        $this->assertStringContainsString('colour', $this->refusal($this->tool('find-entries', ['type' => 'essay', 'where' => ['colour' => 'red']], $secret)));
        $this->assertSame('There is no essay 999.', $this->refusal($this->tool('get-entry', ['type' => 'essay', 'id' => 999], $secret)));
        $this->assertSame('There is no entry type called banner.', $this->refusal($this->tool('get-entry', ['type' => 'banner', 'id' => 1], $secret)));
        $this->assertStringContainsString('at most 1 without a token', $this->refusal($this->tool('get-entry', ['type' => 'essay', 'id' => 1, 'depth' => 2])));
    }

    #[Test]
    public function a_token_the_site_did_not_issue_is_refused_before_any_tool(): void
    {
        $this->rpc('tools/list', secret: 'mst_unknown')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
    }
}
