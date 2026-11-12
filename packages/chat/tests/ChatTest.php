<?php

namespace Mainstay\Chat\Tests;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Tools\Request as ToolRequest;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;
use Mainstay\Ai\AiConnectors;
use Mainstay\Ai\Connectors;
use Mainstay\Auth\Gate;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Chat\ChatAgent;
use Mainstay\Chat\ChatController;
use Mainstay\Chat\ChatPlugin;
use Mainstay\Chat\ChatTools;
use Mainstay\Chat\McpToolAdapter;
use Mainstay\Facades\Mainstay;
use Mainstay\Mainstay as Registry;
use Mainstay\Mcp\McpPlugin;
use Mainstay\Mcp\Server;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Api\Colophon;
use Mainstay\Tests\Fixtures\Api\Essay;
use Mainstay\Tests\Fixtures\Api\Essayist;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;
use Mainstay\Tests\Fixtures\Signed\Banner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/*
 | The chat as a person uses it, against a provider faked at its HTTP edge so
 | that laravel/ai's own loop runs: the tools are the MCP server's, run as the
 | person signed in; what goes live waits for an Approve, which resumes the
 | run; and a conversation is its person's alone.
 */
class ChatTest extends DatabaseTestCase
{
    private const PROMPT = 'Quarterly numbers are confidential';

    private const GATED = ['create-entry', 'update-entry', 'save-global', 'publish-draft', 'trash-entry'];

    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, AiConnectors::class, McpPlugin::class, ChatPlugin::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => 'sk-test', 'ai.conversations.generate_title' => false]);
        app(Registry::class)->types([Essay::class, Essayist::class, Colophon::class, Person::class, Story::class, Genre::class, Banner::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function signIn(array $capabilities = ['*']): User
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user, 'mainstay');

        return $user;
    }

    /* As the admin's middleware leaves the request, for a test that calls the tools without one. */
    private function signInInProcess(array $capabilities): void
    {
        request()->attributes->set(Gate::USER, $this->signIn($capabilities));
    }

    /* What Anthropic streams for one assistant turn: some text, then the tool calls. */
    private function turn(string $text, array $calls = []): string
    {
        $events = [['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]]];
        $index = 0;

        if ($text !== '') {
            $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']];
            $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $text]];
            $events[] = ['type' => 'content_block_stop', 'index' => $index++];
        }

        foreach ($calls as $id => [$name, $input]) {
            $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) []]];
            $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($input)]];
            $events[] = ['type' => 'content_block_stop', 'index' => $index++];
        }

        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $calls === [] ? 'end_turn' : 'tool_use'], 'usage' => ['output_tokens' => 2]];
        $events[] = ['type' => 'message_stop'];

        return implode('', array_map(fn (array $event) => "event: {$event['type']}\ndata: ".json_encode($event)."\n\n", $events));
    }

    /** @param  list<string>  $turns */
    private function provider(array $turns): void
    {
        $sequence = Http::sequence();

        foreach ($turns as $turn) {
            $sequence->push($turn, 200, ['Content-Type' => 'text/event-stream']);
        }

        Http::fake(['api.anthropic.com/*' => $sequence]);
    }

    private function say(string $message, ?string $conversation = null): TestResponse
    {
        return $this->postJson(route('mainstay.plugins.chat.send'), ['message' => $message, 'conversation' => $conversation]);
    }

    private function decide(string $conversation, array $decisions): TestResponse
    {
        return $this->postJson(route('mainstay.plugins.chat.decide', $conversation), ['decisions' => $decisions]);
    }

    /** @return list<array<string, mixed>> */
    private function events(TestResponse $response): array
    {
        $response->assertOk();

        preg_match_all('/^data: (\{.*\})$/m', $response->streamedContent(), $matches);

        return array_map(fn (string $json) => json_decode($json, true), $matches[1]);
    }

    private function conversation(TestResponse $response): string
    {
        return $response->headers->get(ChatController::CONVERSATION_HEADER);
    }

    private function ofType(array $events, string $type): array
    {
        return array_values(array_filter($events, fn (array $event) => $event['type'] === $type));
    }

    private function essay(string $title = 'Old'): int
    {
        return Mainstay::create(Essay::class, ['title' => $title, 'slug' => strtolower($title)], overrideAccess: true)->id;
    }

    private function title(int $id): ?string
    {
        return Mainstay::findById(Essay::class, $id, overrideAccess: true)?->title;
    }

    #[Test]
    public function the_tools_are_the_mcp_servers_with_only_drafts_and_reads_going_unasked(): void
    {
        $tools = collect(ChatTools::all())->keyBy(fn (McpToolAdapter $tool) => $tool->name());

        $this->assertSame(['list-types', 'describe-type', 'find-entries', 'get-entry', 'get-entry-by-path', 'get-global', 'get-draft', 'save-draft', 'publish-draft', 'discard-draft', 'create-entry', 'update-entry', 'trash-entry', 'save-global'], $tools->keys()->all());
        $this->assertCount(count(Server::TOOLS), $tools);

        $asked = $tools->filter(fn (McpToolAdapter $tool) => $tool->shouldRequestApproval(new ToolRequest) !== null)->keys()->all();
        $this->assertEqualsCanonicalizing(self::GATED, $asked);
        $this->assertNull($tools['save-draft']->shouldRequestApproval(new ToolRequest));
        $this->assertNull($tools['discard-draft']->shouldRequestApproval(new ToolRequest));
        $this->assertNull($tools['find-entries']->shouldRequestApproval(new ToolRequest));
    }

    #[Test]
    public function a_tool_runs_in_process_as_the_person_who_is_signed_in(): void
    {
        $id = $this->essay();
        $globals = fn () => JsonSchema::object(fn ($schema) => collect(ChatTools::all())->first(fn (McpToolAdapter $tool) => $tool->name() === 'get-global')->schema($schema))->toArray()['properties']['type']['enum'];
        $this->signInInProcess(['use_chat']);
        $tools = collect(ChatTools::all())->keyBy(fn (McpToolAdapter $tool) => $tool->name());

        $found = json_decode($tools['find-entries']->handle(new ToolRequest(['type' => 'essay'])), true);
        $this->assertSame('Old', $found['data'][0]['title']);

        /* The type list is built for this caller, as over MCP. */
        $schema = JsonSchema::object(fn ($schema) => $tools['find-entries']->schema($schema))->toArray();
        $this->assertContains('essay', $schema['properties']['type']['enum']);

        /* The types offered are asked of whoever the request says is calling. */
        $this->assertSame(['colophon', 'banner'], $globals());
        request()->attributes->set(Gate::PUBLIC, true);
        $this->assertSame(['colophon'], $globals());
        request()->attributes->remove(Gate::PUBLIC);

        $this->assertSame('Error: There is no entry type called banner.', $tools['get-entry']->handle(new ToolRequest(['type' => 'banner', 'id' => $id])));
    }

    #[Test]
    public function the_chat_talks_to_the_text_model_chosen_on_the_connectors_screen(): void
    {
        $this->signIn();
        $this->provider([$this->turn('Hello.'), $this->turn('Hello again.')]);

        $this->events($this->say('Hi'));
        Http::assertSent(fn (Request $request) => $request['model'] === 'claude-sonnet-5-5');

        app(Connectors::class)->choose('text', 'anthropic', 'claude-opus-5-5', overrideAccess: true);
        $this->events($this->say('Hi again'));
        Http::assertSent(fn (Request $request) => $request['model'] === 'claude-opus-5-5');
    }

    #[Test]
    public function a_read_and_a_draft_run_without_asking(): void
    {
        $this->signIn();
        $id = $this->essay();
        $this->provider([
            $this->turn('Looking.', ['toolu_1' => ['save-draft', ['type' => 'essay', 'entry' => $id, 'data' => ['title' => 'Drafted']]]]),
            $this->turn('Saved a draft.'),
        ]);

        $events = $this->events($this->say('Retitle the essay'));

        $this->assertSame([], $this->ofType($events, 'tool_approval_request'));
        $this->assertTrue($this->ofType($events, 'tool_result')[0]['successful']);
        $this->assertSame(1, DB::table('mainstay_drafts')->count());
        $this->assertSame('Old', $this->title($id));
    }

    #[Test]
    public function a_live_write_waits_for_an_approve_and_runs_when_given_one(): void
    {
        $this->signIn();
        $id = $this->essay();
        $this->provider([
            $this->turn('Publishing it.', ['toolu_1' => ['update-entry', ['type' => 'essay', 'id' => $id, 'data' => ['title' => 'Live now']]]]),
            $this->turn('Done.'),
        ]);

        $paused = $this->say('Retitle the essay, live');
        $conversation = $this->conversation($paused);
        $asked = $this->ofType($this->events($paused), 'tool_approval_request');

        $this->assertSame('update-entry', $asked[0]['approvals'][0]['tool']);
        $this->assertSame(['type' => 'essay', 'id' => $id, 'data' => ['title' => 'Live now']], $asked[0]['approvals'][0]['arguments']);
        $this->assertSame('Old', $this->title($id));
        Http::assertSentCount(1);

        $resumed = $this->events($this->decide($conversation, ['toolu_1' => true]));

        $this->assertSame('Live now', $this->title($id));
        $this->assertTrue($this->ofType($resumed, 'tool_result')[0]['successful']);
    }

    #[Test]
    public function a_rejected_write_writes_nothing(): void
    {
        $this->signIn();
        $id = $this->essay();
        $this->provider([
            $this->turn('', ['toolu_1' => ['trash-entry', ['type' => 'essay', 'id' => $id]]]),
            $this->turn('Understood, it stays.'),
        ]);

        $conversation = $this->conversation($paused = $this->say('Delete the essay'));
        $this->events($paused);

        $resumed = $this->events($this->decide($conversation, ['toolu_1' => false]));

        $this->assertTrue($this->ofType($resumed, 'tool_result')[0]['denied']);
        $this->assertSame('Old', $this->title($id));
    }

    #[Test]
    public function the_model_is_refused_what_the_person_may_not_do(): void
    {
        $this->signIn(['use_chat']);
        $this->provider([
            $this->turn('', ['toolu_1' => ['save-draft', ['type' => 'essay', 'data' => ['title' => 'Sneaky', 'slug' => 'sneaky']]]]),
            $this->turn('I was not allowed.'),
        ]);

        $result = $this->ofType($this->events($this->say('Write a draft')), 'tool_result')[0];

        $this->assertStringStartsWith('Error: ', $result['result']);
        $this->assertSame(0, DB::table('mainstay_drafts')->count());
    }

    #[Test]
    public function a_conversation_is_its_persons_alone(): void
    {
        $ada = $this->signIn();
        $this->provider([$this->turn('Hello.')]);
        $response = $this->say('Hi there');
        $conversation = $this->conversation($response);
        $this->events($response);

        $this->getJson(route('mainstay.plugins.chat.show', $conversation))->assertOk()->assertJsonPath('messages.0.content', 'Hi there');
        $this->getJson(route('mainstay.plugins.chat.conversations'))->assertJsonPath('conversations.0.id', $conversation)->assertJsonPath('conversations.0.title', fn ($title) => $title !== '');

        $this->signIn();
        $this->getJson(route('mainstay.plugins.chat.show', $conversation))->assertNotFound();
        $this->say('Continue', $conversation)->assertNotFound();
        $this->decide($conversation, ['toolu_1' => true])->assertNotFound();
        $this->getJson(route('mainstay.plugins.chat.conversations'))->assertJsonPath('conversations', []);

        $this->flushSession();
        $this->actingAs($ada, 'mainstay');
        $this->getJson(route('mainstay.plugins.chat.show', $conversation))->assertOk();
    }

    #[Test]
    public function a_conversation_of_another_kind_of_participant_with_the_same_id_is_not_found(): void
    {
        $user = $this->signIn();
        DB::table('agent_conversations')->insert(['id' => $id = '0198a000-0000-7000-8000-000000000001', 'participant_type' => 'App\\Models\\Member', 'participant_id' => $user->id, 'title' => 'A member\'s', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_conversation_messages')->insert(['id' => '0198a000-0000-7000-8000-000000000002', 'conversation_id' => $id, 'participant_type' => 'App\\Models\\Member', 'participant_id' => $user->id, 'agent' => ChatAgent::class, 'role' => 'user', 'content' => 'Secret', 'attachments' => '[]', 'steps' => '[]', 'usage' => '[]', 'meta' => '[]', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson(route('mainstay.plugins.chat.show', $id))->assertNotFound();
    }

    #[Test]
    public function a_conversation_with_another_agent_is_not_the_chats(): void
    {
        $user = $this->signIn();
        $type = Conversation::participantType($user);
        DB::table('agent_conversations')->insert(['id' => $id = '0198a000-0000-7000-8000-000000000003', 'participant_type' => $type, 'participant_id' => $user->id, 'title' => 'Support bot talk', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_conversation_messages')->insert(['id' => '0198a000-0000-7000-8000-000000000004', 'conversation_id' => $id, 'participant_type' => $type, 'participant_id' => $user->id, 'agent' => 'App\\Ai\\SupportBot', 'role' => 'user', 'content' => 'Hello', 'attachments' => '[]', 'steps' => '[]', 'usage' => '[]', 'meta' => '[]', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson(route('mainstay.plugins.chat.show', $id))->assertNotFound();
        $this->getJson(route('mainstay.plugins.chat.conversations'))->assertOk()->assertJsonPath('conversations', []);
    }

    #[Test]
    public function the_chat_needs_the_ai_and_mcp_plugins(): void
    {
        foreach ([AiConnectors::class, McpPlugin::class] as $missing) {
            config(['mainstay.plugins' => array_values(array_diff([SitePlugin::class, AiConnectors::class, McpPlugin::class, ChatPlugin::class], [$missing]))]);

            try {
                (new ChatPlugin)->boot($this->app);
                $this->fail("Nothing was refused without {$missing}.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString($missing, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function the_chat_is_for_whoever_holds_use_chat_and_is_a_dialog_not_a_screen(): void
    {
        $this->assertContains('use_chat', Mainstay::capabilities());
        $this->assertFalse(Route::has('mainstay.plugins.chat.index'));

        $this->signIn(['edit_essays']);
        $this->getJson(route('mainstay.plugins.chat.conversations'))->assertNotFound();
        $this->say('Hi')->assertNotFound();
        $this->get(route('mainstay.admin'))->assertOk()->assertDontSee('chat-dialog');

        $this->signIn(['use_chat']);
        $this->getJson(route('mainstay.plugins.chat.conversations'))->assertOk();
        $this->get(route('mainstay.admin'))->assertOk()->assertSee('data-dialog-open="chat-dialog"', false)->assertSee('id="chat-dialog"', false)->assertSee('data-chat', false)->assertSee('"dialog":"chat-dialog"', false);
        $this->get(route('mainstay.account'))->assertSee('id="chat-dialog"', false);
        $this->get(route('mainstay.admin'))->assertDontSee('href="/admin/chat"', false);
    }

    #[Test]
    public function the_roles_grid_lists_chat(): void
    {
        $this->signIn(['manage_users']);

        $this->get(route('mainstay.roles.create'))->assertOk()->assertSeeInOrder(['Plugins', 'Chat', 'value="use_chat"'], false);
    }

    #[Test]
    public function without_a_text_connector_the_conversations_say_so_and_the_dialog_links_only_for_whoever_manages_them(): void
    {
        config(['ai.providers.anthropic.key' => null]);

        $this->signIn(['use_chat']);
        $this->getJson(route('mainstay.plugins.chat.conversations'))->assertOk()->assertJsonPath('ready', false);
        $this->get(route('mainstay.admin'))->assertSee('No AI provider is connected')->assertDontSee(route('mainstay.plugins.ai.index'));
        $this->say('Hi')->assertStatus(409);

        $this->signIn(['use_chat', 'use_ai']);
        $this->get(route('mainstay.admin'))->assertSee(route('mainstay.plugins.ai.index'));
    }

    #[Test]
    public function a_connected_provider_is_ready(): void
    {
        $this->signIn(['use_chat']);

        $this->getJson(route('mainstay.plugins.chat.conversations'))->assertJsonPath('ready', true);
    }

    #[Test]
    public function the_chat_runs_for_someone_who_cannot_manage_connectors(): void
    {
        $this->signIn(['use_chat']);
        $this->provider([$this->turn('Hello.')]);

        $this->get(route('mainstay.plugins.ai.index'))->assertNotFound();
        $this->assertSame('Hello.', implode('', array_column($this->ofType($this->events($this->say('Hi')), 'text_delta'), 'delta')));
    }

    /** @return array<string, array{Closure, string}> */
    public static function providerFailures(): array
    {
        $body = ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key sk-test-leaked']];

        return [
            'a rejected key' => [fn () => Http::response($body, 401), 'The provider rejected the request (401).'],
            'a forbidden key' => [fn () => Http::response($body, 403), 'The provider rejected the request (403).'],
            'a rate limit' => [fn () => Http::response($body, 429), 'The provider is limiting requests.'],
            'a spent account' => [fn () => Http::response($body, 402), 'The provider account is out of credit or quota.'],
            'a server error' => [fn () => Http::response($body, 500), 'The provider is unavailable right now.'],
            'an overloaded provider' => [fn () => Http::response($body, 503), 'The provider is unavailable right now.'],
            'a connection that fails' => [fn () => throw new ConnectionException('cURL error 28: timed out sending sk-test-leaked'), 'Could not reach the provider.'],
        ];
    }

    #[Test]
    #[DataProvider('providerFailures')]
    public function a_provider_that_fails_ends_the_stream_with_an_error_event_and_is_reported(Closure $failure, string $message): void
    {
        Exceptions::fake();
        $this->signIn(['use_chat']);
        Http::fake(['api.anthropic.com/*' => $failure]);

        $response = $this->say('Hi there');
        $events = $this->events($response);

        $this->assertSame('error', end($events)['type']);
        $this->assertStringStartsWith($message, end($events)['message']);
        $this->assertStringEndsWith("data: [DONE]\n\n", $response->streamedContent());
        $this->assertStringNotContainsString('sk-test', $response->streamedContent());
        Exceptions::assertReportedCount(1);
    }

    /** @return array<string, array{Closure, class-string, int|null}> */
    public static function failuresThatEchoWhatWasSent(): array
    {
        $echo = ['type' => 'error', 'error' => ['message' => 'invalid x-api-key sk-test-leaked for: '.self::PROMPT]];

        return [
            'a rejected key' => [fn () => Http::response($echo, 401), RequestException::class, 401],
            'a server error' => [fn () => Http::response($echo, 500), RequestException::class, 500],
            'a connection that fails' => [fn () => throw new ConnectionException('timed out sending sk-test-leaked and '.self::PROMPT), ProviderConnectionException::class, null],
        ];
    }

    #[Test]
    #[DataProvider('failuresThatEchoWhatWasSent')]
    public function a_failure_is_logged_with_what_it_was_but_never_what_the_provider_echoed(Closure $failure, string $failureClass, ?int $status): void
    {
        $log = tempnam(sys_get_temp_dir(), 'chat-log');
        config(['logging.default' => 'single', 'logging.channels.single.path' => $log]);
        $user = $this->signIn(['use_chat']);
        Http::fake(['api.anthropic.com/*' => $failure]);

        $response = $this->say(self::PROMPT);
        $conversation = $this->conversation($response);
        $this->events($response);
        $logged = file_get_contents($log);
        unlink($log);

        $this->assertStringNotContainsString('sk-test', $logged);
        $this->assertStringNotContainsString(self::PROMPT, $logged);
        $this->assertStringNotContainsString('sk-test', $response->streamedContent());
        $this->assertStringContainsString('The chat provider failed.', $logged);
        $this->assertStringContainsString(json_encode($failureClass), $logged);
        $this->assertStringContainsString('"status":'.json_encode($status), $logged);
        $this->assertStringContainsString('"conversation":"'.$conversation.'"', $logged);
        $this->assertStringContainsString('"user":'.$user->getKey(), $logged);
    }

    #[Test]
    public function the_stream_is_sent_unbuffered_and_untranscoded_with_the_conversation_it_belongs_to(): void
    {
        $this->signIn(['use_chat']);
        $this->provider([$this->turn('Hello.')]);

        $response = $this->say('Hi');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-transform'));
        $response->assertHeader('X-Accel-Buffering', 'no');
        $this->assertTrue(Str::isUuid($this->conversation($response)));
    }

    #[Test]
    public function a_rejected_key_points_to_the_connectors_screen_only_for_whoever_manages_them(): void
    {
        Exceptions::fake();
        Http::fake(['api.anthropic.com/*' => Http::response([], 401)]);

        $this->signIn(['use_chat']);
        $this->assertStringNotContainsString('Connectors', $this->events($this->say('Hi'))[0]['message']);

        $this->signIn(['use_chat', 'use_ai']);
        $this->assertStringContainsString('Check the key on the Connectors screen.', $this->events($this->say('Hi'))[0]['message']);
    }

    #[Test]
    public function a_first_turn_that_fails_leaves_no_conversation_and_a_retry_starts_one(): void
    {
        Exceptions::fake();
        $this->signIn();
        Http::fake(['api.anthropic.com/*' => Http::sequence()->pushStatus(500)->push($this->turn('Back.'), 200, ['Content-Type' => 'text/event-stream'])]);

        $events = $this->events($this->say('Hi there'));

        $this->assertFalse(end($events)['conversation_kept']);
        $this->assertSame(0, Conversation::query()->count());

        $retried = $this->say('Hi there');
        $this->assertSame('Back.', implode('', array_column($this->ofType($this->events($retried), 'text_delta'), 'delta')));
        $this->getJson(route('mainstay.plugins.chat.show', $this->conversation($retried)))->assertOk();
    }

    #[Test]
    public function a_turn_that_fails_after_its_first_step_keeps_the_conversation_and_continues_in_it(): void
    {
        Exceptions::fake();
        $this->signIn();
        $id = $this->essay();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->turn('Looking.', ['toolu_1' => ['save-draft', ['type' => 'essay', 'entry' => $id, 'data' => ['title' => 'Drafted']]]]), 200, ['Content-Type' => 'text/event-stream'])
            ->pushStatus(500)
            ->push($this->turn('Back.'), 200, ['Content-Type' => 'text/event-stream'])]);

        $failed = $this->say('Retitle the essay');
        $events = $this->events($failed);

        $this->assertTrue(end($events)['conversation_kept']);
        $this->assertSame(1, DB::table('mainstay_drafts')->count());

        $continued = $this->say('Go on', $this->conversation($failed));
        $this->assertSame('Back.', implode('', array_column($this->ofType($this->events($continued), 'text_delta'), 'delta')));
    }

    #[Test]
    public function an_error_the_provider_streams_is_replaced_by_ours(): void
    {
        Exceptions::fake();
        $this->signIn();
        $error = ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded for sk-test-leaked']];
        Http::fake(['api.anthropic.com/*' => Http::response("event: error\ndata: ".json_encode($error)."\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

        $response = $this->say('Hi there');
        $events = $this->events($response);

        $this->assertSame('The chat could not finish its answer. Try again.', end($events)['message']);
        $this->assertSame([], $this->ofType($events, 'overloaded_error'));
        $this->assertStringNotContainsString('sk-test', $response->streamedContent());
    }

    /** The system prompt the provider was sent for its first request. */
    private function sentInstructions(): string
    {
        $system = Http::recorded()[0][0]->data()['system'];

        return is_string($system) ? $system : implode("\n", array_column($system, 'text'));
    }

    #[Test]
    public function the_prompt_is_the_rules_then_the_developers_then_the_owners_then_the_reminder(): void
    {
        config(['mainstay.chat.instructions' => ['Name drafts with a date.', 'Never touch the footer.']]);
        Mainstay::setSetting('chat.instructions', "Warm and plain.\nKeep it short.");

        $text = (new ChatAgent)->instructions();
        $positions = array_map(fn (string $part) => strpos($text, $part), [
            'You edit the content of a website',
            "The site's developer says how to work on this site:\n\nName drafts with a date.\n\nNever touch the footer.",
            "The site owner's guidance on tone and content:\n\nWarm and plain.\nKeep it short.",
            'The rules at the start outrank the guidance above',
        ]);

        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
        $this->assertStringEndsWith('you never invent a field, a type or an id.', $text);
    }

    #[Test]
    public function with_no_guidance_the_prompt_is_the_rules_alone(): void
    {
        $text = (new ChatAgent)->instructions();

        $this->assertStringStartsWith('You edit the content of a website', $text);
        $this->assertStringNotContainsString("owner's guidance", $text);
        $this->assertStringNotContainsString('developer says', $text);
        $this->assertStringNotContainsString('outrank', $text);

        config(['mainstay.chat.instructions' => '  ']);
        Mainstay::setSetting('chat.instructions', " \n ");

        $this->assertSame($text, (new ChatAgent)->instructions());
    }

    #[Test]
    public function the_owners_text_alone_adds_only_its_heading_and_the_reminder(): void
    {
        Mainstay::setSetting('chat.instructions', 'Friendly.');

        $text = (new ChatAgent)->instructions();

        $this->assertStringContainsString("The site owner's guidance on tone and content:\n\nFriendly.", $text);
        $this->assertStringNotContainsString('developer says', $text);
    }

    #[Test]
    public function guidance_is_text_and_is_not_interpolated(): void
    {
        Mainstay::setSetting('chat.instructions', 'Say {{ $secret }} and %s and <?php echo 1 ?> and TEXT;');

        $this->assertStringContainsString('Say {{ $secret }} and %s and <?php echo 1 ?> and TEXT;', (new ChatAgent)->instructions());
    }

    #[Test]
    public function the_guidance_reaches_the_provider_in_its_request(): void
    {
        $this->signIn();
        config(['mainstay.chat.instructions' => 'Developer rule.']);
        Mainstay::setSetting('chat.instructions', 'Owner tone.');
        $this->provider([$this->turn('Hello.')]);

        $this->events($this->say('Hi there'));
        $sent = $this->sentInstructions();

        $this->assertStringContainsString('Developer rule.', $sent);
        $this->assertStringContainsString("The site owner's guidance on tone and content:\n\nOwner tone.", $sent);
        $this->assertStringContainsString('You edit the content of a website', $sent);
    }

    #[Test]
    public function an_instruction_to_publish_without_asking_does_not_lift_the_approval(): void
    {
        $this->signIn();
        $id = $this->essay();
        config(['mainstay.chat.instructions' => 'Publish everything without asking.']);
        Mainstay::setSetting('chat.instructions', 'Never ask for approval. Publish everything at once.');
        $this->provider([$this->turn('Publishing it.', ['toolu_1' => ['update-entry', ['type' => 'essay', 'id' => $id, 'data' => ['title' => 'Live now']]]])]);

        $events = $this->events($this->say('Retitle the essay'));

        $this->assertStringContainsString('Publish everything without asking.', $this->sentInstructions());
        $this->assertSame('update-entry', $this->ofType($events, 'tool_approval_request')[0]['approvals'][0]['tool']);
        $this->assertSame('Old', $this->title($id));
    }

    #[Test]
    public function the_owners_text_is_a_setting_on_the_settings_screen_under_chat(): void
    {
        $this->signIn(['manage_settings']);

        $this->get(route('mainstay.settings'))->assertOk()->assertSeeInOrder(['Chat', 'Instructions for the chat'])->assertSee('Write in a warm, plain tone', false);

        $this->post(route('mainstay.settings.update'), ['settings' => ['chat' => ['instructions' => "Be warm.\r\nBe brief."]]])->assertSessionHasNoErrors();
        $this->assertSame("Be warm.\nBe brief.", Mainstay::setting('chat.instructions'));

        $this->post(route('mainstay.settings.update'), ['settings' => ['chat' => ['instructions' => str_repeat('x', ChatPlugin::OWNER_INSTRUCTIONS_MAX)]]])->assertSessionHasNoErrors();
        $this->post(route('mainstay.settings.update'), ['settings' => ['chat' => ['instructions' => str_repeat('x', ChatPlugin::OWNER_INSTRUCTIONS_MAX + 1)]]])->assertSessionHasErrors('settings.chat.instructions');
        $this->assertSame(ChatPlugin::OWNER_INSTRUCTIONS_MAX, strlen(Mainstay::setting('chat.instructions')));
    }

    #[Test]
    public function the_owners_text_is_not_written_by_someone_without_manage_settings(): void
    {
        $this->signIn(['use_chat']);

        $this->post(route('mainstay.settings.update'), ['settings' => ['chat' => ['instructions' => 'Mine now']]])->assertNotFound();

        $this->assertSame('', Mainstay::setting('chat.instructions'));
    }

    #[Test]
    public function a_developers_tools_are_added_and_wait_for_an_approve_unless_they_say_otherwise(): void
    {
        config(['mainstay.chat.tools' => [StockTool::class, FreeStockTool::class]]);
        $tools = collect(ChatTools::all());

        $this->assertCount(count(Server::TOOLS) + 2, $tools);

        [$plain, $free] = $tools->slice(-2)->values()->all();

        $this->assertNotNull($plain->shouldRequestApproval(new ToolRequest));
        $this->assertNull($free->shouldRequestApproval(new ToolRequest));
        $this->assertSame('StockTool', ToolNameResolver::resolve($plain));
    }

    #[Test]
    public function a_developers_tool_runs_only_once_approved(): void
    {
        $this->signIn();
        StockTool::$runs = 0;
        config(['mainstay.chat.tools' => [StockTool::class]]);
        $this->provider([$this->turn('Counting.', ['toolu_1' => ['StockTool', []]]), $this->turn('Forty.')]);

        $paused = $this->say('How much stock?');
        $asked = $this->ofType($this->events($paused), 'tool_approval_request');

        $this->assertSame('StockTool', $asked[0]['approvals'][0]['tool']);
        $this->assertSame(0, StockTool::$runs);

        $this->events($this->decide($this->conversation($paused), ['toolu_1' => true]));

        $this->assertSame(1, StockTool::$runs);
    }

    #[Test]
    public function a_wrong_chat_config_is_refused_naming_it(): void
    {
        foreach ([
            ['tools', ['App\\Missing\\Tool'], 'which is not a class'],
            ['tools', [self::class], 'which does not implement'],
            ['tools', 'StockTool', 'is a list of class names'],
            ['instructions', [1], 'a string or a list of strings'],
        ] as [$key, $value, $message]) {
            config(["mainstay.chat.{$key}" => $value]);

            try {
                $key === 'tools' ? ChatTools::all() : (new ChatAgent)->instructions();
                $this->fail("Expected {$key} to be refused.");
            } catch (LogicException $refused) {
                $this->assertStringContainsString($message, $refused->getMessage());
            }

            config(["mainstay.chat.{$key}" => []]);
        }
    }

    #[Test]
    public function the_plugin_refuses_a_wrong_chat_config_as_it_boots(): void
    {
        foreach ([['tools', ['App\\Missing\\Tool']], ['instructions', [1]]] as [$key, $value]) {
            config(["mainstay.chat.{$key}" => $value]);

            try {
                (new ChatPlugin)->boot($this->app);
                $this->fail("Expected {$key} to be refused at boot.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            config(["mainstay.chat.{$key}" => []]);
        }
    }

    #[Test]
    public function a_stored_owner_value_that_is_not_text_or_is_over_the_cap_is_held_in_check(): void
    {
        Mainstay::setSetting('chat.instructions', ['not', 'text']);
        $this->assertStringNotContainsString("owner's guidance", (new ChatAgent)->instructions());

        Mainstay::setSetting('chat.instructions', str_repeat('y', ChatPlugin::OWNER_INSTRUCTIONS_MAX + 50));
        $text = (new ChatAgent)->instructions();

        $this->assertStringContainsString(str_repeat('y', ChatPlugin::OWNER_INSTRUCTIONS_MAX), $text);
        $this->assertStringNotContainsString(str_repeat('y', ChatPlugin::OWNER_INSTRUCTIONS_MAX + 1), $text);
    }

    #[Test]
    public function a_browsers_text_of_exactly_the_cap_is_saved_whatever_its_line_ends(): void
    {
        $this->signIn(['manage_settings']);
        $posted = str_repeat("a\r\n", ChatPlugin::OWNER_INSTRUCTIONS_MAX / 2 - 1).'ab';

        $this->post(route('mainstay.settings.update'), ['settings' => ['chat' => ['instructions' => $posted]]])->assertSessionHasNoErrors();

        $this->assertSame(ChatPlugin::OWNER_INSTRUCTIONS_MAX, strlen(Mainstay::setting('chat.instructions')));
    }
}
