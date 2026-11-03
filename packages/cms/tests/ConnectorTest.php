<?php

namespace Mainstay\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Mainstay\Ai\Connector;
use Mainstay\Facades\Mainstay;
use Mainstay\Mainstay as Registry;
use Mainstay\Tests\Fixtures\Plugins\Lab as LabPlugin;
use Mainstay\Tests\Fixtures\Plugins\Offered;
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

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [LabPlugin::class]);
        $app['config']->set('ai.providers.openai.key', 'sk-from-env');
    }

    protected function setUp(): void
    {
        parent::setUp();

        LabPlugin::$made = [];
        Offered::$providers = [];
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
        Mainstay::connectors()->save('anthropic', ' sk-ant-secret ', overrideAccess: true);

        $stored = DB::table('mainstay_connectors')->where('provider', 'anthropic')->value('key');
        $this->assertStringNotContainsString('sk-ant-secret', $stored);
        $this->assertSame('sk-ant-secret', decrypt($stored, unserialize: false));
        $this->assertArrayNotHasKey('key', Connector::query()->sole()->toArray());

        $this->fresh();
        Http::fake(['api.anthropic.com/*' => Http::response(self::ANSWER)]);
        $this->assertSame('OK', agent('Answer.')->prompt('Hello?', provider: 'anthropic')->text);
        Http::assertSent(fn (Request $request) => $request->header('x-api-key') === ['sk-ant-secret']);

        Mainstay::connectors()->forget('anthropic', overrideAccess: true);
        $this->assertNull(config('ai.providers.anthropic.key'));
        $this->assertSame(0, Connector::query()->count());
    }

    #[Test]
    public function a_key_in_the_hosts_config_wins_and_is_not_saved_beneath(): void
    {
        $this->assertSame("OpenAI's key is set in the site's config, which wins over one saved here. Change it there.", $this->refused(fn () => Mainstay::connectors()->save('openai', 'sk-other', overrideAccess: true), 'key'));
        $this->assertSame('sk-from-env', $this->fresh()->instance('openai')->providerCredentials()['key']);

        $listed = array_column(Mainstay::connectors()->all(overrideAccess: true), null, 'name');
        $this->assertSame('config', $listed['openai']['key']);
        $this->assertNull($listed['anthropic']['key']);
        $this->assertSame(['text'], $listed['anthropic']['uses']);
        $this->assertSame(['text', 'images', 'audio', 'transcription', 'embeddings', 'classification'], $listed['openai']['uses']);
        $this->assertSame(['embeddings', 'reranking'], $listed['voyageai']['uses']);
    }

    #[Test]
    public function the_provider_chosen_for_a_kind_of_work_is_laravel_ais_default(): void
    {
        $connectors = Mainstay::connectors();
        $connectors->choose('text', 'anthropic', overrideAccess: true);
        $connectors->choose('embeddings', 'voyageai', overrideAccess: true);

        $this->fresh();
        $this->assertSame('anthropic', config('ai.default'));
        $this->assertSame('voyageai', config('ai.default_for_embeddings'));
        $this->assertSame(['provider' => 'anthropic', 'chosen' => true], $connectors->defaults()['text']);
        $this->assertSame(['provider' => 'gemini', 'chosen' => false], $connectors->defaults()['images']);

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
        $listed = array_column(Mainstay::connectors()->all(overrideAccess: true), null, 'name');
        $this->assertSame('Together AI', $listed['together']['label']);
        $this->assertSame('Echo', $listed['echo']['label']);

        Mainstay::connectors()->save('echo', 'echo-key', overrideAccess: true);
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

        $this->assertSame('config', array_column(Mainstay::connectors()->all(overrideAccess: true), 'key', 'name')['together']);
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
                (new Registry)->loadPlugins($plugins);
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
    public function every_call_needs_manage_connectors(): void
    {
        $connectors = Mainstay::connectors();

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
        $connectors = Mainstay::connectors();
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
    public function the_hosts_config_wins_over_what_was_saved_beneath_it_or_for_what_is_gone(): void
    {
        Connector::query()->create(['provider' => 'openai', 'key' => 'sk-stale']);
        Connector::query()->create(['provider' => 'gone', 'key' => 'k', 'defaults' => ['text']]);

        $this->assertSame('sk-from-env', $this->fresh()->instance('openai')->providerCredentials()['key']);
        $this->assertSame('openai', config('ai.default'));
        $this->assertSame(['provider' => 'openai', 'chosen' => false], Mainstay::connectors()->defaults()['text']);
    }

    #[Test]
    public function an_empty_line_in_the_env_is_no_key(): void
    {
        config(['ai.providers.anthropic.key' => '']);
        Mainstay::connectors()->save('anthropic', 'sk-ant-secret', overrideAccess: true);

        $this->assertSame('sk-ant-secret', $this->fresh()->instance('anthropic')->providerCredentials()['key']);
        $this->assertSame('saved', array_column(Mainstay::connectors()->all(overrideAccess: true), 'key', 'name')['anthropic']);
    }

    #[Test]
    public function a_default_the_host_gives_as_an_enum_or_a_failover_list_is_named(): void
    {
        config(['ai.default' => Lab::Anthropic, 'ai.default_for_images' => ['openai', 'gemini'], 'ai.default_for_embeddings' => ['voyageai' => 'voyage-3']]);

        $defaults = Mainstay::connectors()->defaults();
        $this->assertSame('anthropic', $defaults['text']['provider']);
        $this->assertSame('openai', $defaults['images']['provider']);
        $this->assertSame('voyageai', $defaults['embeddings']['provider']);
    }

    #[Test]
    public function a_test_of_a_provider_doing_no_text_embeds_a_word(): void
    {
        Mainstay::connectors()->save('voyageai', 'pa-secret', overrideAccess: true);
        Http::fake(['api.voyageai.com/*' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3], 'index' => 0]], 'usage' => ['total_tokens' => 1]])]);

        $this->assertSame('3 dimensions', Mainstay::connectors()->test('voyageai', overrideAccess: true));
    }
}
