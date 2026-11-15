<?php

namespace Mainstay\Ai\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Mainstay\Ai\AiConnectors;
use Mainstay\Ai\Connector;
use Mainstay\Ai\Connectors;
use Mainstay\Ai\Tests\Fixtures\Lab as LabPlugin;
use Mainstay\Ai\Tests\Fixtures\MainstayConnectors;
use Mainstay\Ai\Tests\Fixtures\Offered;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Database\ContentSchema;
use Mainstay\Mainstay as Registry;
use Mainstay\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

use function Laravel\Ai\agent;

/*
 | The AI providers as the Connectors screen keeps them: a key saved there
 | encrypted and handed to laravel/ai only where the host's config gives
 | none, the provider chosen for each kind of work written into laravel/ai's
 | defaults, a plugin's providers and drivers beside laravel/ai's own, and the
 | screen there only to whoever manages connectors.
 */
class ConnectorTest extends DatabaseTestCase
{
    private const ANSWER = ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'content' => [['type' => 'text', 'text' => 'OK']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 9, 'output_tokens' => 1]];

    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [AiConnectors::class, LabPlugin::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        /* After laravel/ai's own config is merged in, which a plugin's boot
           does, so the one key does not replace its providers. */
        config(['ai.providers.openai.key' => 'sk-from-env']);
        LabPlugin::$made = [];
        Offered::$providers = [];
    }

    private function signIn(array $capabilities): User
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user, 'mainstay');

        return $user;
    }

    /* laravel/ai as a later process makes it, from what was saved. */
    private function fresh(): AiManager
    {
        $this->app->forgetInstance(AiManager::class);
        Ai::clearResolvedInstances();

        return $this->app->make(AiManager::class);
    }

    private function refused(callable $call, string $field): string
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            return $exception->validator->errors()->first($field);
        }

        $this->fail('Nothing was refused.');
    }

    #[Test]
    public function a_saved_key_is_kept_encrypted_and_sent_by_laravel_ai(): void
    {
        app(Connectors::class)->save('anthropic', ' sk-ant-secret ', overrideAccess: true);

        $stored = DB::table('mainstay_connectors')->where('provider', 'anthropic')->value('key');
        $this->assertStringNotContainsString('sk-ant-secret', $stored);
        $this->assertSame('sk-ant-secret', decrypt($stored, unserialize: false));
        $this->assertArrayNotHasKey('key', Connector::query()->sole()->toArray());

        $this->fresh();
        Http::fake(['api.anthropic.com/*' => Http::response(self::ANSWER)]);
        $this->assertSame('OK', agent('Answer.')->prompt('Hello?', provider: 'anthropic')->text);
        Http::assertSent(fn (Request $request) => $request->header('x-api-key') === ['sk-ant-secret']);

        app(Connectors::class)->forget('anthropic', overrideAccess: true);
        $this->assertNull(config('ai.providers.anthropic.key'));
        $this->assertSame(0, Connector::query()->count());
    }

    #[Test]
    public function a_key_in_the_hosts_config_wins_and_is_not_saved_beneath(): void
    {
        $this->assertSame("OpenAI's key is set in the site's config, which wins over one saved here. Change it there.", $this->refused(fn () => app(Connectors::class)->save('openai', 'sk-other', overrideAccess: true), 'key'));
        $this->assertSame('sk-from-env', $this->fresh()->instance('openai')->providerCredentials()['key']);

        $listed = array_column(app(Connectors::class)->all(overrideAccess: true), null, 'name');
        $this->assertSame('config', $listed['openai']['key']);
        $this->assertNull($listed['anthropic']['key']);
        $this->assertSame(['text'], $listed['anthropic']['uses']);
        $this->assertSame(['text', 'images', 'audio', 'transcription', 'embeddings', 'classification'], $listed['openai']['uses']);
        $this->assertSame(['embeddings', 'reranking'], $listed['voyageai']['uses']);
    }

    #[Test]
    public function the_provider_chosen_for_a_kind_of_work_is_laravel_ais_default(): void
    {
        $connectors = app(Connectors::class);
        $connectors->choose('text', 'anthropic', overrideAccess: true);
        $connectors->choose('embeddings', 'voyageai', overrideAccess: true);

        $this->fresh();
        $this->assertSame('anthropic', config('ai.default'));
        $this->assertSame('voyageai', config('ai.default_for_embeddings'));
        $this->assertSame(['provider' => 'anthropic', 'chosen' => true, 'model' => null], $connectors->defaults()['text']);
        $this->assertSame(['provider' => 'gemini', 'chosen' => false, 'model' => null], $connectors->defaults()['images']);

        $this->assertSame('Anthropic does no images.', $this->refused(fn () => $connectors->choose('images', 'anthropic', overrideAccess: true), 'defaults.images'));

        $connectors->choose('text', 'openai', overrideAccess: true);
        $this->assertSame([['provider' => 'openai', 'defaults' => ['text']], ['provider' => 'voyageai', 'defaults' => ['embeddings']]], Connector::query()->orderBy('provider')->get(['provider', 'defaults'])->toArray());

        $connectors->choose('text', 'anthropic', overrideAccess: true);
        $connectors->choose('text', null, overrideAccess: true);
        $connectors->choose('embeddings', null, overrideAccess: true);
        $this->assertSame('openai', config('ai.default'), "The host's default is back.");
        $this->assertSame('openai', config('ai.default_for_embeddings'));
        $this->assertSame(0, Connector::query()->count(), 'A row with no key and nothing chosen goes.');
    }

    #[Test]
    public function a_plugin_brings_providers_and_a_driver_of_its_own(): void
    {
        $listed = array_column(app(Connectors::class)->all(overrideAccess: true), null, 'name');
        $this->assertSame('Together AI', $listed['together']['label']);
        $this->assertSame('Echo', $listed['echo']['label']);

        app(Connectors::class)->save('echo', 'echo-key', overrideAccess: true);
        $echo = $this->fresh()->instance('echo');
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $echo);
        $this->assertSame('echo-key', $echo->providerCredentials()['key']);
        $this->assertSame('https://echo.test/v1', LabPlugin::$made[0]['url']);
        $this->assertSame('https://api.together.xyz/v1', config('ai.providers.together.url'));
    }

    #[Test]
    public function a_hosts_entry_of_a_plugins_provider_wins_key_by_key(): void
    {
        config(['ai.providers.together' => ['key' => 'tg-from-env', 'url' => 'https://eu.together.test/v1']]);

        $this->assertSame('config', array_column(app(Connectors::class)->all(overrideAccess: true), 'key', 'name')['together']);
        $together = $this->fresh()->instance('together');
        $this->assertSame('tg-from-env', $together->providerCredentials()['key']);
        $this->assertSame(['label' => 'Together AI', 'driver' => 'openai-compatible', 'url' => 'https://eu.together.test/v1', 'key' => 'tg-from-env', 'name' => 'together'], config('ai.providers.together') + ['name' => 'together']);
    }

    #[Test]
    public function a_plugins_provider_is_refused_for_its_name_its_driver_or_a_twin(): void
    {
        $refused = function (array $providers, array $plugins, string $message) {
            Offered::$providers = $providers;

            try {
                $registry = new Registry;
                $registry->loadPlugins($plugins);
                (new Connectors($registry))->assertDeclared();
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());

                return;
            }

            $this->fail("Nothing was refused; expected: {$message}");
        };

        $refused(['Together AI' => ['driver' => 'openai']], [Offered::class], "a provider's name is lowercase letters, digits, dashes and underscores");
        $refused(['together' => ['url' => 'https://api.together.xyz/v1']], [Offered::class], Offered::class."'s AI provider together names no driver");
        $refused(['together' => ['driver' => 'openai-compatible']], [LabPlugin::class, Offered::class], 'Two plugins declare the AI provider "together": '.LabPlugin::class.' and '.Offered::class);
    }

    #[Test]
    public function the_screen_is_there_only_to_whoever_manages_connectors(): void
    {
        $this->signIn(['edit_entries', 'manage_users']);
        $this->get(route('mainstay.plugins.ai.index'))->assertNotFound();
        $this->post(route('mainstay.plugins.ai.save', 'anthropic'), ['key' => 'sk-ant-secret'])->assertNotFound();
        $this->post(route('mainstay.plugins.ai.test', 'anthropic'))->assertNotFound();
        $this->post(route('mainstay.plugins.ai.forget', 'anthropic'))->assertNotFound();
        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'anthropic']])->assertNotFound();
        $this->get(route('mainstay.admin'))->assertDontSee('Connectors');
        $this->assertSame(0, Connector::query()->count());

        try {
            app(Connectors::class)->all();
            $this->fail('The providers were listed.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This needs use_ai.', $exception->getMessage());
        }

        $this->signIn(['use_ai']);
        $this->get(route('mainstay.admin'))->assertSee('Connectors');
        $this->post(route('mainstay.plugins.ai.save', 'anthropic'), ['key' => 'sk-ant-secret'])->assertRedirect(route('mainstay.plugins.ai.index'));
        $this->get(route('mainstay.plugins.ai.index'))->assertOk()
            ->assertSee("Anthropic's key saved.")
            ->assertSee('Together AI')
            ->assertSee('Key saved here, encrypted')
            ->assertSee("Key set in the site's config")
            ->assertDontSee('sk-ant-secret')
            ->assertDontSee('sk-from-env');

        $this->post(route('mainstay.plugins.ai.save', 'nobody'), ['key' => 'x'])->assertNotFound()->assertSee('There is nothing here');
        foreach (['anthropic' => 'sk-refused-'.str_repeat('k', 4090), 'openai' => 'sk-refused-secret'] as $provider => $refused) {
            $this->post(route('mainstay.plugins.ai.save', $provider), ['key' => $refused])->assertSessionHasErrors('key');
            $this->assertStringNotContainsString('sk-refused-', serialize(session()->all()), 'A refused key is nowhere in the session.');
        }
        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'nobody']])->assertNotFound()->assertSee('There is nothing here');
        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'anthropic', 'images' => '']])->assertRedirect(route('mainstay.plugins.ai.index'));
        $this->assertSame(['text'], Connector::query()->where('provider', 'anthropic')->sole()->defaults);

        $page = $this->get(route('mainstay.plugins.ai.index'))->getContent();
        $select = fn (string $use) => preg_match('/<select[^>]*id="default-'.$use.'".*?<\/select>/s', $page, $found) ? $found[0] : '';
        $this->assertMatchesRegularExpression('/<input(?=[^>]*id="key-anthropic")(?=[^>]*type="password")[^>]*>/', $page);
        $this->assertStringNotContainsString('id="key-openai"', $page, 'A key the config sets is changed there.');
        $this->assertStringContainsString('data-dialog-open="forget-anthropic"', $page);
        $this->assertStringNotContainsString('data-dialog-open="forget-groq"', $page);
        $this->assertMatchesRegularExpression('/<option value="anthropic"\s+selected/', $select('text'));
        $this->assertStringNotContainsString('value="anthropic"', $select('images'), 'Anthropic makes no images.');
        $this->assertStringContainsString('value="gemini"', $select('images'));
        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'voyageai']])->assertSessionHasErrors('defaults.text');
        $this->post(route('mainstay.plugins.ai.forget', 'anthropic'))->assertRedirect(route('mainstay.plugins.ai.index'));
        $this->get(route('mainstay.plugins.ai.index'))->assertSee("Anthropic's key removed.");
    }

    #[Test]
    public function the_screen_lists_providers_by_label_and_asks_only_for_work_one_does(): void
    {
        config(['ai.providers' => ['anthropic' => ['driver' => 'anthropic', 'key' => null]]]);
        $this->assertSame(['Anthropic', 'Echo', 'Together AI'], array_column(app(Connectors::class)->all(overrideAccess: true), 'label'));

        $this->signIn(['use_ai']);
        $this->get(route('mainstay.plugins.ai.index'))->assertOk()
            ->assertSee('id="default-text"', false)
            ->assertDontSee('id="default-images"', false);

        $this->signIn(['manage_users']);
        $this->get(route('mainstay.roles.create'))->assertOk()->assertSeeInOrder(['Plugins', 'Connectors', 'value="use_ai"'], false);
    }

    #[Test]
    public function a_key_saved_under_another_app_key_reads_as_none_until_replaced(): void
    {
        Exceptions::fake();
        app(Connectors::class)->save('anthropic', 'sk-ant-old', overrideAccess: true);
        app(Connectors::class)->choose('text', 'anthropic', overrideAccess: true);
        $elsewhere = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
        DB::table('mainstay_connectors')->update(['key' => $elsewhere->encryptString('sk-ant-old')]);

        $this->assertNull($this->fresh()->instance('anthropic')->providerCredentials()['key']);
        $this->assertSame('anthropic', config('ai.default'), 'Its choice outlasts its key.');
        Exceptions::assertReported(DecryptException::class);

        $this->signIn(['use_ai']);
        $this->get(route('mainstay.plugins.ai.index'))->assertOk()
            ->assertSee('Key saved here under another APP_KEY, so unreadable')
            ->assertSee('data-dialog-open="forget-anthropic"', false);

        app(Connectors::class)->choose('text', 'openai', overrideAccess: true);
        $this->assertTrue(Connector::query()->where('provider', 'anthropic')->exists(), 'Its key keeps the row, readable or not.');

        app(Connectors::class)->save('anthropic', 'sk-ant-new', overrideAccess: true);
        $this->assertSame('sk-ant-new', $this->fresh()->instance('anthropic')->providerCredentials()['key']);
    }

    #[Test]
    public function a_test_says_what_the_provider_answered_or_why_not_without_the_key(): void
    {
        $this->signIn(['use_ai']);
        app(Connectors::class)->save('anthropic', 'sk-ant-secret', overrideAccess: true);

        Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => [['id' => 'claude-haiku-5-5']]]), 'api.anthropic.com/*' => Http::sequence()
            ->push(self::ANSWER)
            ->push(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key sk-ant-secret']], 401)]);
        $this->post(route('mainstay.plugins.ai.test', 'anthropic'))->assertRedirect(route('mainstay.plugins.ai.index'));
        $this->get(route('mainstay.plugins.ai.index'))->assertSee('Anthropic answered: OK');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['model'] === 'claude-haiku-5-5');

        $this->post(route('mainstay.plugins.ai.test', 'anthropic'))->assertSessionHasErrors('test');
        $error = session('errors')->first('test');
        $this->assertStringContainsString('[key]', $error);
        $this->assertStringNotContainsString('sk-ant-secret', $error);

        $this->assertStringContainsString('does neither', $this->refused(fn () => app(Connectors::class)->test('eleven', overrideAccess: true), 'test'));
    }

    #[Test]
    public function every_call_needs_use_ai(): void
    {
        $connectors = app(Connectors::class);

        foreach ([
            fn () => $connectors->all(),
            fn () => $connectors->save('anthropic', 'sk-ant-secret'),
            fn () => $connectors->forget('anthropic'),
            fn () => $connectors->choose('text', 'anthropic'),
            fn () => $connectors->test('anthropic'),
        ] as $call) {
            try {
                $call();
                $this->fail('Nobody was let through.');
            } catch (AuthorizationException $exception) {
                $this->assertStringContainsString('needs a Mainstay user', $exception->getMessage());
            }
        }

        $this->assertSame(0, Connector::query()->count());
    }

    #[Test]
    public function a_key_and_the_defaults_a_provider_holds_outlast_each_other(): void
    {
        $connectors = app(Connectors::class);
        $connectors->save('anthropic', 'sk-ant-secret', overrideAccess: true);
        $connectors->choose('text', 'anthropic', overrideAccess: true);
        $connectors->choose('text', 'openai', overrideAccess: true);
        $this->assertSame([], Connector::query()->where('provider', 'anthropic')->sole()->defaults, 'Its key keeps the row.');

        $connectors->choose('text', 'anthropic', overrideAccess: true);
        $connectors->forget('anthropic', overrideAccess: true);
        $kept = Connector::query()->where('provider', 'anthropic')->sole();
        $this->assertNull($kept->key);
        $this->assertSame(['text'], $kept->defaults, 'Its default keeps the row.');
        $this->assertNull(array_column($connectors->all(overrideAccess: true), 'key', 'name')['anthropic'], 'A row kept for its default holds no key.');

        $this->assertSame('The key field must not be greater than 4096 characters.', $this->refused(fn () => $connectors->save('anthropic', str_repeat('k', 4097), overrideAccess: true), 'key'));
        $this->expectException(RecordNotFoundException::class);
        $connectors->choose('text', 'nobody', overrideAccess: true);
    }

    #[Test]
    public function a_write_reaches_laravel_ai_as_made_in_this_process_once_it_commits(): void
    {
        $ai = $this->app->make(AiManager::class);
        $this->assertNull($ai->instance('anthropic')->providerCredentials()['key']);

        app(Connectors::class)->save('anthropic', 'sk-ant-secret', overrideAccess: true);
        $this->assertSame('sk-ant-secret', $ai->instance('anthropic')->providerCredentials()['key']);

        $this->signIn(['use_ai']);
        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'anthropic', 'images' => 'anthropic']])->assertSessionHasErrors('defaults.images');
        $this->assertSame([], Connector::query()->where('provider', 'anthropic')->sole()->defaults);
        $this->assertSame('openai', config('ai.default'), 'A rolled back choice is not laravel/ai\'s either.');
    }

    #[Test]
    public function the_hosts_config_wins_over_what_was_saved_beneath_it_or_for_what_is_gone(): void
    {
        Connector::query()->create(['provider' => 'openai', 'key' => 'sk-stale']);
        Connector::query()->create(['provider' => 'gone', 'key' => 'k', 'defaults' => ['text']]);

        $this->assertSame('sk-from-env', $this->fresh()->instance('openai')->providerCredentials()['key']);
        $this->assertSame('openai', config('ai.default'));
        $this->assertSame(['provider' => 'openai', 'chosen' => false, 'model' => null], app(Connectors::class)->defaults()['text']);
    }

    #[Test]
    public function an_empty_line_in_the_env_is_no_key(): void
    {
        config(['ai.providers.anthropic.key' => '']);
        app(Connectors::class)->save('anthropic', 'sk-ant-secret', overrideAccess: true);

        $this->assertSame('sk-ant-secret', $this->fresh()->instance('anthropic')->providerCredentials()['key']);
        $this->assertSame('saved', array_column(app(Connectors::class)->all(overrideAccess: true), 'key', 'name')['anthropic']);
    }

    #[Test]
    public function a_default_the_host_gives_as_an_enum_or_a_failover_list_is_named(): void
    {
        config(['ai.default' => Lab::Anthropic, 'ai.default_for_images' => ['openai', 'gemini'], 'ai.default_for_embeddings' => ['voyageai' => 'voyage-3']]);

        $defaults = app(Connectors::class)->defaults();
        $this->assertSame('anthropic', $defaults['text']['provider']);
        $this->assertSame('openai', $defaults['images']['provider']);
        $this->assertSame('voyageai', $defaults['embeddings']['provider']);
    }

    #[Test]
    public function a_provider_its_config_does_not_make_is_shown_broken_beside_the_rest(): void
    {
        config(['ai.providers.broken' => ['driver' => 'nonsense', 'key' => 'sk-broken-secret']]);
        $this->signIn(['use_ai']);

        $this->get(route('mainstay.plugins.ai.index'))->assertOk()
            ->assertSee('Its config makes no provider: Instance driver [nonsense] is not supported.')
            ->assertSee('Anthropic');
        $this->post(route('mainstay.plugins.ai.test', 'broken'))->assertSessionHasErrors('test');
        $this->post(route('mainstay.plugins.ai.save', 'anthropic'), ['key' => ['sk-ant-secret']])->assertSessionHasErrors('key');
    }

    #[Test]
    public function a_test_of_a_provider_doing_no_text_embeds_a_word(): void
    {
        app(Connectors::class)->save('voyageai', 'pa-secret', overrideAccess: true);
        Http::fake(['api.voyageai.com/*' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3], 'index' => 0]], 'usage' => ['total_tokens' => 1]])]);

        $this->assertSame('3 dimensions', app(Connectors::class)->test('voyageai', overrideAccess: true));
    }

    #[Test]
    public function the_model_chosen_for_a_kind_of_work_is_the_one_laravel_ai_prompts_with(): void
    {
        $connectors = app(Connectors::class);
        $connectors->save('anthropic', 'sk-ant-secret', overrideAccess: true);
        $connectors->choose('text', 'anthropic', ' claude-opus-5-5 ', overrideAccess: true);
        $connectors->choose('images', 'openai', 'gpt-image-9', overrideAccess: true);
        $connectors->choose('embeddings', 'voyageai', 'voyage-9', overrideAccess: true);

        $this->assertSame(['text' => 'claude-opus-5-5'], Connector::query()->where('provider', 'anthropic')->sole()->models);
        $this->assertSame('claude-opus-5-5', $connectors->defaults()['text']['model']);
        $this->assertNull($connectors->defaults()['audio']['model']);

        $manager = $this->fresh();
        $this->assertSame('claude-opus-5-5', config('ai.providers.anthropic.models.text.default'));
        $this->assertSame('gpt-image-9', config('ai.providers.openai.models.image.default'));
        $this->assertSame('voyage-9', config('ai.providers.voyageai.models.embeddings.default'));
        $this->assertSame('claude-opus-5-5', $manager->instance('anthropic')->defaultTextModel());
        $this->assertSame('claude-haiku-5-5', $manager->instance('anthropic')->cheapestTextModel(), 'Only the default is set.');
        $this->assertNull(config('ai.providers.openai.models.text.default'), 'A kind of work with no model keeps laravel/ai\'s own.');
        $this->assertSame('gpt-6.1-sol', $manager->instance('openai')->defaultTextModel());

        Http::fake(['api.anthropic.com/*' => Http::response(self::ANSWER)]);
        $this->assertSame('OK', agent('Answer.')->prompt('Hello?')->text);
        Http::assertSent(fn (Request $request) => $request['model'] === 'claude-opus-5-5');

        $connectors->choose('text', 'anthropic', overrideAccess: true);
        $this->fresh();
        $this->assertNull(config('ai.providers.anthropic.models.text.default'), 'A blank model is the provider\'s own again.');
        $this->assertSame([], Connector::query()->where('provider', 'anthropic')->sole()->models);
    }

    #[Test]
    public function azure_takes_its_model_as_the_deployment(): void
    {
        $connectors = app(Connectors::class);
        $connectors->choose('text', 'azure', 'my-chat', overrideAccess: true);
        $connectors->choose('images', 'azure', 'my-image', overrideAccess: true);
        $connectors->choose('embeddings', 'azure', 'my-embed', overrideAccess: true);

        $azure = $this->fresh()->instance('azure');
        $this->assertSame(['my-chat', 'my-image', 'my-embed'], [$azure->defaultTextModel(), $azure->defaultImageModel(), $azure->defaultEmbeddingsModel()]);
        $this->assertNull(config('ai.providers.azure.models'));
    }

    #[Test]
    public function a_model_moves_with_its_kind_of_work_and_goes_with_it(): void
    {
        $connectors = app(Connectors::class);
        $connectors->save('anthropic', 'sk-ant-secret', overrideAccess: true);
        $connectors->choose('text', 'anthropic', 'claude-opus-5-5', overrideAccess: true);
        $connectors->choose('text', 'openai', overrideAccess: true);

        $this->assertSame([], Connector::query()->where('provider', 'anthropic')->sole()->models, "The old provider's model goes with its default.");
        $this->assertNull(Connector::query()->where('provider', 'openai')->sole()->modelFor('text'));

        $connectors->choose('text', null, 'claude-opus-5-5', overrideAccess: true);
        $this->assertSame(0, Connector::query()->where('provider', 'openai')->count(), 'A model with no provider is kept nowhere.');
    }

    #[Test]
    public function a_row_saved_before_models_reads_as_none(): void
    {
        DB::table('mainstay_connectors')->insert(['provider' => 'anthropic', 'defaults' => '["text"]', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNull(Connector::query()->sole()->modelFor('text'));
        $this->assertSame('claude-sonnet-5-5', $this->fresh()->instance('anthropic')->defaultTextModel());
    }

    #[Test]
    public function a_model_is_refused_for_its_shape_and_nothing_is_saved(): void
    {
        $connectors = app(Connectors::class);

        foreach (['two words', 'a"b', '<script>', "line\nbreak", str_repeat('m', 256)] as $model) {
            $this->assertStringContainsString('text model', $this->refused(fn () => $connectors->choose('text', 'anthropic', $model, overrideAccess: true), 'models.text'), $model);
        }

        foreach (['gpt-6-luna', 'llama3.2:latest', 'anthropic/claude-sonnet-5.5', '~typesafe/jev-latest', 'global.anthropic.claude-haiku-4-5-20251001-v1:0', str_repeat('m', 255)] as $model) {
            $connectors->choose('text', 'anthropic', $model, overrideAccess: true);
            $this->assertSame($model, $connectors->defaults()['text']['model']);
        }

        $connectors->choose('text', 'anthropic', '   ', overrideAccess: true);
        $this->assertNull($connectors->defaults()['text']['model'], 'Blank is the provider\'s own.');

        $connectors->choose('text', 'anthropic', 'claude-opus-5-5', overrideAccess: true);
        $this->refused(fn () => $connectors->choose('text', 'anthropic', 'two words', overrideAccess: true), 'models.text');
        $this->assertSame('claude-opus-5-5', $connectors->defaults()['text']['model'], 'A refused model changes nothing.');
    }

    #[Test]
    public function a_test_asks_the_model_chosen_for_text_on_that_provider(): void
    {
        $connectors = app(Connectors::class);
        $connectors->save('anthropic', 'sk-ant-secret', overrideAccess: true);
        Http::fake(['api.anthropic.com/*' => Http::response(self::ANSWER)]);

        $connectors->choose('text', 'anthropic', 'claude-opus-5-5', overrideAccess: true);
        $connectors->test('anthropic', overrideAccess: true);
        Http::assertSent(fn (Request $request) => $request['model'] === 'claude-opus-5-5');

        $connectors->choose('text', 'openai', overrideAccess: true);
        $connectors->test('anthropic', overrideAccess: true);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['model'] === 'claude-haiku-5-5');
    }

    #[Test]
    public function the_screen_saves_a_model_for_whoever_holds_use_ai_and_escapes_it(): void
    {
        $this->signIn(['edit_entries']);
        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'anthropic'], 'models' => ['text' => 'claude-opus-5-5']])->assertNotFound();
        $this->assertSame(0, Connector::query()->count());

        $this->signIn(['use_ai']);
        $this->from(route('mainstay.plugins.ai.index'))->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'anthropic'], 'models' => ['text' => 'two words']])
            ->assertRedirect(route('mainstay.plugins.ai.index'))
            ->assertSessionHasErrors('models.text');
        $this->assertSame(0, Connector::query()->count(), 'Nothing is saved when one model is refused.');

        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'anthropic', 'images' => 'openai'], 'models' => ['text' => 'claude-opus-5-5', 'audio' => 'ignored']])
            ->assertRedirect(route('mainstay.plugins.ai.index'));
        $this->assertSame('claude-opus-5-5', $this->app->make(Connectors::class)->defaults()['text']['model']);
        $this->assertNull($this->app->make(Connectors::class)->defaults()['images']['model']);

        $this->get(route('mainstay.plugins.ai.index'))->assertOk()
            ->assertSee('id="model-text"', false)
            ->assertSee('value="claude-opus-5-5"', false)
            ->assertSee('placeholder="gpt-image-2.5-flare"', false);

        DB::table('mainstay_connectors')->where('provider', 'anthropic')->update(['models' => json_encode(['text' => '"><script>alert(1)</script>'])]);
        $this->get(route('mainstay.plugins.ai.index'))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&quot;&gt;&lt;script&gt;', false);
    }

    #[Test]
    public function a_model_posted_as_anything_but_text_is_refused_and_clears_nothing(): void
    {
        $this->signIn(['use_ai']);
        app(Connectors::class)->choose('text', 'anthropic', 'claude-opus-5-5', overrideAccess: true);

        $this->from(route('mainstay.plugins.ai.index'))->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'anthropic'], 'models' => ['text' => ['x']]])
            ->assertRedirect(route('mainstay.plugins.ai.index'))
            ->assertSessionHasErrors(['models.text' => 'The text model must be text.']);
        $this->assertSame('claude-opus-5-5', app(Connectors::class)->defaults()['text']['model']);
    }

    #[Test]
    public function after_a_refusal_the_posted_provider_and_model_return_together_and_the_rest_show_what_is_saved(): void
    {
        $this->signIn(['use_ai']);
        app(Connectors::class)->choose('text', 'anthropic', 'claude-opus-5-5', overrideAccess: true);
        app(Connectors::class)->choose('images', 'openai', 'gpt-image-9', overrideAccess: true);

        $this->from(route('mainstay.plugins.ai.index'))->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'openai', 'audio' => ''], 'models' => ['text' => 'two words', 'audio' => 'stale words']])
            ->assertSessionHasErrors('models.text');

        $page = $this->followingRedirects()->get(route('mainstay.plugins.ai.index'))->getContent();
        $select = fn (string $use) => preg_match('/<select[^>]*id="default-'.$use.'".*?<\/select>/s', $page, $found) ? $found[0] : '';
        $input = fn (string $use) => preg_match('/<input[^>]*id="model-'.$use.'"[^>]*>/s', $page, $found) ? $found[0] : '';

        $this->assertMatchesRegularExpression('/<option value="openai"\s+selected/', $select('text'));
        $this->assertStringContainsString('value="two words"', $input('text'));
        $this->assertStringContainsString('aria-invalid="true"', $input('text'));
        $this->assertDoesNotMatchRegularExpression('/<option value="[a-z]+"\s+selected/', $select('audio'), 'A blank provider posted is the config again.');
        $this->assertStringContainsString('value="stale words"', $input('audio'));
        $this->assertMatchesRegularExpression('/<option value="openai"\s+selected/', $select('images'), 'A kind not posted shows what is saved.');
        $this->assertStringContainsString('value="gpt-image-9"', $input('images'));
        $this->assertStringContainsString('Use a model id that this provider offers.', $page);
        $this->assertStringContainsString('Default now: ', $page);
    }

    #[Test]
    public function the_connectors_table_is_one_no_type_may_be_stored_in(): void
    {
        $this->app->make(Registry::class)->types([MainstayConnectors::class]);

        $this->assertThrows(
            fn () => app(ContentSchema::class)->diff(),
            InvalidArgumentException::class,
            'would be stored in mainstay_connectors, a table Mainstay keeps for itself',
        );
    }
}
