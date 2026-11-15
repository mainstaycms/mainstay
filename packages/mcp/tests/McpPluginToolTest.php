<?php

namespace Mainstay\Mcp\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Enums\MetaKey;
use Mainstay\Api\Operations;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Mcp\McpPlugin;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Api\Colophon;
use Mainstay\Tests\Fixtures\Api\Essay;
use Mainstay\Tests\Fixtures\Api\Essayist;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;
use Mainstay\Tests\Fixtures\Plugins\Hooked;
use Mainstay\Tests\Fixtures\Signed\Banner;
use PHPUnit\Framework\Attributes\Test;

/*
 | What a plugin adds to the MCP server and what it hangs on content: its
 | tool is listed and gated as the core's are, and its before hooks refuse a
 | tool's write as they refuse any other, as an error result.
 */
class McpPluginToolTest extends DatabaseTestCase
{
    private const MCP = '/api/mainstay/mcp';

    private const VERSION = '2026-07-28';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, McpPlugin::class, Hooked::class]);
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
        $role = Role::query()->create(['name' => 'role-'.count(Role::query()->get()), 'capabilities' => $held]);
        $user = User::query()->create(['name' => 'Ada', 'email' => "ada-{$role->id}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);

        return Mainstay::tokens()->issue($user->id, 'Agent', $held, overrideAccess: true)[1];
    }

    private function rpc(string $method, array $params, ?string $secret = null): TestResponse
    {
        return $this->postJson(self::MCP, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
            'params' => [...$params, '_meta' => [MetaKey::PROTOCOL_VERSION->value => self::VERSION, MetaKey::CLIENT_CAPABILITIES->value => (object) []]],
        ], [
            'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => self::VERSION, 'Mcp-Method' => $method,
            ...(isset($params['name']) ? ['Mcp-Name' => $params['name']] : []),
            ...($secret === null ? [] : ['Authorization' => "Bearer {$secret}"]),
        ])->assertOk();
    }

    #[Test]
    public function a_plugins_tool_is_listed_after_the_cores_and_runs_as_its_caller(): void
    {
        $names = array_column($this->rpc('tools/list', [])->json('result.tools'), 'name');
        $this->assertSame('count-people', end($names));

        Mainstay::create(Person::class, ['title' => 'Grace', 'slug' => 'grace'], overrideAccess: true);

        $this->assertSame(1, $this->rpc('tools/call', ['name' => 'count-people', 'arguments' => []], $this->token())->json('result.structuredContent.people'));
    }

    #[Test]
    public function a_plugins_tool_is_refused_what_the_caller_may_not_read(): void
    {
        $nobody = $this->rpc('tools/call', ['name' => 'count-people', 'arguments' => []]);

        $this->assertTrue($nobody->json('result.isError'));
        $this->assertSame(Operations::NOBODY, $nobody->json('result.content.0.text'));
    }

    #[Test]
    public function a_before_hooks_refusal_is_an_error_result_and_writes_nothing(): void
    {
        $secret = $this->token();

        $long = $this->rpc('tools/call', ['name' => 'create-entry', 'arguments' => ['type' => 'essay', 'data' => ['title' => 'A title far past the limit', 'slug' => 'long']]], $secret);
        $this->assertTrue($long->json('result.isError'));
        $this->assertSame('title: A title is at most 12 characters.', $long->json('result.content.0.text'));

        $no = $this->rpc('tools/call', ['name' => 'create-entry', 'arguments' => ['type' => 'essay', 'data' => ['title' => 'forbidden', 'slug' => 'f']]], $secret);
        $this->assertSame('Plugin says no.', $no->json('result.content.0.text'));

        $this->assertSame(0, DB::table(Essay::handle())->count());
        $this->assertSame([], Hooked::$heard);
    }

    #[Test]
    public function a_write_a_hook_allows_is_heard_after_it_commits(): void
    {
        $made = $this->rpc('tools/call', ['name' => 'create-entry', 'arguments' => ['type' => 'essay', 'data' => ['title' => 'Fine', 'slug' => 'fine']]], $this->token());

        $this->assertSame('FINE', $made->json('result.structuredContent.shout'));
        $this->assertSame(['content.created:'.$made->json('result.structuredContent.id')], Hooked::$heard);
    }
}
