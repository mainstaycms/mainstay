<?php

namespace Mainstay\Ai\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Mainstay\Ai\AiConnectors;
use Mainstay\Ai\Connector;
use Mainstay\Ai\Connectors;
use Mainstay\Ai\ModelCatalog;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Http\PreventRequestForgery;
use Mainstay\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/*
 | The models a provider offers, asked of its own list with its key: what
 | each provider is asked and how its answer is sorted by kind of work, what
 | is kept and for how long, and that the key goes nowhere but the request.
 */
class ModelCatalogTest extends DatabaseTestCase
{
    private const KEY = 'sk-live-0123456789secret';

    private int $made = 0;

    /** @var list<array<string, mixed>> */
    private array $logged = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [AiConnectors::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        $this->logged = [];
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logged[] = ['message' => $log->message, 'context' => $log->context]);

        foreach (['anthropic', 'azure', 'openai', 'gemini', 'ollama', 'cohere', 'jina', 'openai-compatible', 'groq', 'eleven', 'openrouter'] as $name) {
            config(["ai.providers.{$name}.key" => null]);
        }
    }

    private function catalog(): ModelCatalog
    {
        return app(ModelCatalog::class);
    }

    private function signIn(array $capabilities): void
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    private function openAiList(): array
    {
        return ['object' => 'list', 'data' => array_map(fn (string $id) => ['id' => $id, 'object' => 'model', 'owned_by' => 'openai'], [
            'gpt-6-luna', 'gpt-6-sol', 'o4-mini', 'gpt-image-2.5-flare', 'dall-e-3', 'whisper-1', 'gpt-4o-transcribe', 'tts-1', 'gpt-4o-mini-tts',
            'text-embedding-3-small', 'omni-moderation-latest', 'gpt-realtime',
        ])];
    }

    #[Test]
    public function openai_models_are_sorted_into_the_kinds_of_work_by_their_ids(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList())]);

        $lists = $this->catalog()->forUses(['text' => 'openai', 'images' => 'openai', 'audio' => 'openai', 'transcription' => 'openai', 'embeddings' => 'openai', 'classification' => 'openai', 'reranking' => 'openai']);

        $this->assertSame(['gpt-6-luna', 'gpt-6-sol', 'o4-mini'], $lists['text']->models);
        $this->assertSame(['gpt-image-2.5-flare', 'dall-e-3'], $lists['images']->models);
        $this->assertSame(['tts-1', 'gpt-4o-mini-tts'], $lists['audio']->models);
        $this->assertSame(['whisper-1', 'gpt-4o-transcribe'], $lists['transcription']->models);
        $this->assertSame(['text-embedding-3-small'], $lists['embeddings']->models);
        $this->assertSame($lists['text']->models, $lists['classification']->models);
        $this->assertSame([], $lists['reranking']->models);
        $this->assertStringContainsString('lists no reranking models', $lists['reranking']->note);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/models' && $request->header('Authorization') === ['Bearer '.self::KEY]);
    }

    #[Test]
    public function anthropic_is_asked_with_its_header_scheme_and_its_models_are_text(): void
    {
        config(['ai.providers.anthropic.key' => self::KEY, 'ai.providers.anthropic.url' => 'https://proxy.test/anthropic/v1']);
        Http::fake(['proxy.test/*' => Http::response(['data' => [['id' => 'claude-haiku-5-5', 'type' => 'model'], ['id' => 'claude-sonnet-5-5', 'type' => 'model']], 'has_more' => false])]);

        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], $this->catalog()->for('anthropic', 'text')->models);
        $this->assertStringContainsString('lists no images models', $this->catalog()->for('anthropic', 'images')->note);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://proxy.test/anthropic/v1/models') && $request->header('x-api-key') === [self::KEY] && $request->header('anthropic-version') === ['2023-06-01'] && $request->header('Authorization') === []);
    }

    #[Test]
    public function gemini_models_are_sorted_by_what_they_support(): void
    {
        config(['ai.providers.gemini.key' => self::KEY]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['models' => [
            ['name' => 'models/gemini-3-pro', 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
            ['name' => 'models/gemini-3-pro-image', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/imagen-5', 'supportedGenerationMethods' => ['predict']],
            ['name' => 'models/gemini-embedding-002', 'supportedGenerationMethods' => ['embedContent']],
            ['name' => 'models/gemini-3-flash-tts', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/aqa', 'supportedGenerationMethods' => ['generateAnswer']],
        ]])]);

        $lists = $this->catalog()->forUses(['text' => 'gemini', 'images' => 'gemini', 'embeddings' => 'gemini', 'audio' => 'gemini']);

        $this->assertSame(['gemini-3-pro'], $lists['text']->models);
        $this->assertSame(['gemini-3-pro-image', 'imagen-5'], $lists['images']->models);
        $this->assertSame(['gemini-embedding-002'], $lists['embeddings']->models);
        $this->assertSame(['gemini-3-flash-tts'], $lists['audio']->models);
        Http::assertSent(fn (Request $request) => $request->header('x-goog-api-key') === [self::KEY] && ! str_contains($request->url(), self::KEY));
    }

    #[Test]
    public function ollama_is_asked_at_its_url_without_a_key_and_embedding_models_are_told_by_name(): void
    {
        config(['ai.providers.ollama.url' => 'http://gpu.lan:11434']);
        Http::fake(['gpu.lan:11434/*' => Http::response(['models' => [['name' => 'llama3.2:latest'], ['name' => 'nomic-embed-text:latest']]])]);

        $lists = $this->catalog()->forUses(['text' => 'ollama', 'embeddings' => 'ollama']);

        $this->assertSame(['llama3.2:latest'], $lists['text']->models);
        $this->assertSame(['nomic-embed-text:latest'], $lists['embeddings']->models);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://gpu.lan:11434/api/tags' && $request->header('Authorization') === []);
    }

    #[Test]
    public function an_openai_compatible_host_is_asked_at_its_models_with_its_configured_headers(): void
    {
        config(['ai.providers.openai-compatible' => ['driver' => 'openai-compatible', 'url' => 'https://llm.example.test/v1/', 'key' => self::KEY, 'headers' => ['X-Tenant' => 'acme']]]);
        Http::fake(['llm.example.test/*' => Http::response(['data' => [['id' => 'meta/llama-4-70b'], ['id' => 'bge-m3'], ['id' => 'flux-2-dev']]])]);

        $lists = $this->catalog()->forUses(['text' => 'openai-compatible', 'embeddings' => 'openai-compatible', 'images' => 'openai-compatible']);

        $this->assertSame(['meta/llama-4-70b'], $lists['text']->models);
        $this->assertSame(['bge-m3'], $lists['embeddings']->models);
        $this->assertSame(['flux-2-dev'], $lists['images']->models);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://llm.example.test/v1/models' && $request->header('X-Tenant') === ['acme']);
    }

    #[Test]
    public function an_openai_compatible_provider_with_no_url_asks_nothing(): void
    {
        config(['ai.providers.openai-compatible.url' => null]);
        Http::fake();

        $this->assertStringContainsString('has no list of models to fetch', $this->catalog()->for('openai-compatible', 'text')->note);
        Http::assertNothingSent();
    }

    #[Test]
    public function cohere_models_are_sorted_by_the_endpoints_they_serve_and_asked_at_v1(): void
    {
        config(['ai.providers.cohere.key' => self::KEY]);
        Http::fake(['api.cohere.com/*' => Http::response(['models' => [['name' => 'command-a', 'endpoints' => ['chat']], ['name' => 'embed-v4', 'endpoints' => ['embed', 'classify']], ['name' => 'rerank-v4', 'endpoints' => ['rerank']]]])]);

        $lists = $this->catalog()->forUses(['text' => 'cohere', 'embeddings' => 'cohere', 'reranking' => 'cohere', 'classification' => 'cohere']);

        $this->assertSame(['command-a'], $lists['text']->models);
        $this->assertSame(['embed-v4'], $lists['embeddings']->models);
        $this->assertSame(['rerank-v4'], $lists['reranking']->models);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.cohere.com/v1/models'));
    }

    #[Test]
    public function elevenlabs_speech_models_are_the_ones_that_can_do_text_to_speech(): void
    {
        config(['ai.providers.eleven.key' => self::KEY]);
        Http::fake(['api.elevenlabs.io/*' => Http::response([['model_id' => 'eleven_turbo_v3', 'can_do_text_to_speech' => true], ['model_id' => 'eleven_sts_v2', 'can_do_text_to_speech' => false]])]);

        $this->assertSame(['eleven_turbo_v3'], $this->catalog()->for('eleven', 'audio')->models);
        Http::assertSent(fn (Request $request) => $request->header('xi-api-key') === [self::KEY]);
    }

    #[Test]
    public function openrouter_is_asked_for_every_modality_and_sorts_by_what_a_model_outputs(): void
    {
        config(['ai.providers.openrouter.key' => self::KEY]);
        Http::fake(['openrouter.ai/*' => Http::response(['data' => [
            ['id' => 'anthropic/claude-sonnet-5.5', 'architecture' => ['output_modalities' => ['text']]],
            ['id' => 'google/gemini-3-flash', 'architecture' => ['output_modalities' => ['text', 'image']]],
            ['id' => 'google/imagen-5', 'architecture' => ['output_modalities' => ['image']]],
            ['id' => 'openai/text-embedding-3-small', 'architecture' => ['output_modalities' => ['embeddings']]],
            ['id' => 'openai/gpt-4o-mini-tts', 'architecture' => ['output_modalities' => ['speech']]],
            ['id' => 'cohere/rerank-v4', 'architecture' => ['output_modalities' => ['rerank']]],
        ]])]);

        $lists = $this->catalog()->forUses(['text' => 'openrouter', 'images' => 'openrouter', 'embeddings' => 'openrouter', 'audio' => 'openrouter', 'reranking' => 'openrouter']);

        $this->assertSame(['anthropic/claude-sonnet-5.5', 'google/gemini-3-flash'], $lists['text']->models);
        $this->assertSame(['google/imagen-5'], $lists['images']->models);
        $this->assertSame(['openai/text-embedding-3-small'], $lists['embeddings']->models);
        $this->assertSame(['openai/gpt-4o-mini-tts'], $lists['audio']->models);
        $this->assertSame(['cohere/rerank-v4'], $lists['reranking']->models);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'output_modalities=all'));
    }

    #[Test]
    public function gemini_transcribes_with_its_ordinary_models_and_elevenlabs_lists_no_transcription_models(): void
    {
        config(['ai.providers.gemini.key' => self::KEY, 'ai.providers.eleven.key' => self::KEY]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['models' => [['name' => 'models/gemini-3-pro', 'supportedGenerationMethods' => ['generateContent']], ['name' => 'models/gemini-embedding-002', 'supportedGenerationMethods' => ['embedContent']]]]),
            'api.elevenlabs.io/*' => Http::response([['model_id' => 'eleven_turbo_v3', 'can_do_text_to_speech' => true]]),
        ]);

        $lists = $this->catalog()->forUses(['transcription' => 'gemini', 'audio' => 'eleven']);
        $this->assertSame(['gemini-3-pro'], $lists['transcription']->models);

        $eleven = $this->catalog()->for('eleven', 'transcription');
        $this->assertSame([], $eleven->models);
        $this->assertSame('ElevenLabs lists no transcription models: type the model id.', $eleven->note);
    }

    /** @return array<string, array{string, array<string, mixed>, string, string}> */
    public static function requests(): array
    {
        $list = ['data' => [['id' => 'some-model']]];

        return [
            'groq at its default' => ['groq', [], 'https://api.groq.com/openai/v1/models', 'Authorization'],
            'groq at a proxy' => ['groq', ['url' => 'https://groq.proxy.test/openai/v1/'], 'https://groq.proxy.test/openai/v1/models', 'Authorization'],
            'mistral' => ['mistral', [], 'https://api.mistral.ai/v1/models', 'Authorization'],
            'xai' => ['xai', [], 'https://api.x.ai/v1/models', 'Authorization'],
            'deepseek' => ['deepseek', [], 'https://api.deepseek.com/v1/models', 'Authorization'],
            'cohere at a v2 base' => ['cohere', ['url' => 'https://cohere.proxy.test/v2'], 'https://cohere.proxy.test/v1/models', 'Authorization'],
            'ollama with a key' => ['ollama', ['url' => 'http://gpu.lan:11434'], 'http://gpu.lan:11434/api/tags', 'Authorization'],
        ];
    }

    #[Test]
    #[DataProvider('requests')]
    public function each_provider_is_asked_at_its_own_url_with_its_key(string $name, array $config, string $url, string $header): void
    {
        config(["ai.providers.{$name}" => [...config("ai.providers.{$name}"), ...$config, 'key' => self::KEY]]);
        Http::fake(['*' => Http::response(['data' => [['id' => 'some-model']], 'models' => [['name' => 'some-model', 'endpoints' => ['chat']]]])]);

        $this->assertTrue($this->catalog()->for($name, 'text')->isPickable());
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), $url) && $request->header($header) === ['Bearer '.self::KEY]);
    }

    #[Test]
    public function a_url_that_is_not_http_is_not_asked(): void
    {
        config(['ai.providers.ollama.url' => 'ftp://gpu.lan/models']);
        Http::fake();

        $this->assertStringContainsString('has no list of models to fetch', $this->catalog()->for('ollama', 'text')->note);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_list_is_read_as_data_or_as_a_bare_array(): void
    {
        config(['ai.providers.mistral.key' => self::KEY, 'ai.providers.xai.key' => self::KEY]);
        Http::fake([
            'api.mistral.ai/*' => Http::response([['id' => 'mistral-large-3'], ['id' => 'mistral-embed']]),
            'api.x.ai/*' => Http::response(['data' => [['id' => 'grok-5']]]),
        ]);

        $lists = $this->catalog()->forUses(['text' => 'mistral', 'embeddings' => 'mistral', 'images' => 'xai']);

        $this->assertSame(['mistral-large-3'], $lists['text']->models);
        $this->assertSame(['mistral-embed'], $lists['embeddings']->models);
        $this->assertSame([], $lists['images']->models);
    }

    #[Test]
    public function a_body_too_large_to_be_a_list_is_refused(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response(json_encode(['data' => [['id' => 'gpt-6']], 'pad' => str_repeat('x', 2_000_001)]))]);

        $list = $this->catalog()->for('openai', 'text');

        $this->assertSame([], $list->models);
        $this->assertStringContainsString("Could not fetch OpenAI's list", $list->note);
    }

    #[Test]
    public function an_answer_is_asked_once_a_request_even_where_the_cache_keeps_nothing(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList())]);
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('store down'));

        $this->catalog()->for('openai', 'text');
        $this->catalog()->for('openai', 'images');

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_provider_with_no_list_to_ask_is_not_asked(): void
    {
        config(['ai.providers.azure.key' => self::KEY, 'ai.providers.jina.key' => self::KEY]);
        Http::fake();

        foreach (['azure', 'jina'] as $name) {
            $list = $this->catalog()->for($name, 'text');
            $this->assertSame([], $list->models);
            $this->assertStringContainsString('has no list of models to fetch: type the model id', $list->note);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function only_providers_with_a_key_or_needing_none_are_asked(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList())]);

        $lists = $this->catalog()->forUses(['text' => 'openai', 'images' => 'anthropic', 'audio' => 'groq', 'embeddings' => null]);

        Http::assertSentCount(1);
        $this->assertTrue($lists['text']->isPickable());
        $this->assertStringContainsString('No key for Anthropic yet', $lists['images']->note);
        $this->assertStringContainsString('No key for Groq yet', $lists['audio']->note);
        $this->assertSame('Choose a provider to see its models.', $lists['embeddings']->note);
    }

    #[Test]
    public function a_saved_key_is_the_one_asked_with(): void
    {
        app(Connectors::class)->save('anthropic', self::KEY, overrideAccess: true);
        $this->app->forgetInstance(AiManager::class);
        Ai::clearResolvedInstances();
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => [['id' => 'claude-haiku-5-5']]])]);

        $this->assertSame(['claude-haiku-5-5'], $this->catalog()->for('anthropic', 'text')->models);
        Http::assertSent(fn (Request $request) => $request->header('x-api-key') === [self::KEY]);
    }

    /** @return array<string, array{callable}> */
    public static function failures(): array
    {
        return [
            'rejected' => [fn () => Http::response(['error' => ['message' => 'Incorrect API key provided: '.self::KEY]], 401), 'rejected the key'],
            'forbidden' => [fn () => Http::response('no '.self::KEY, 403), 'rejected the key'],
            'server error' => [fn () => Http::response('upstream '.self::KEY, 500), "Could not fetch OpenAI's list"],
            'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: timed out for Bearer '.self::KEY), 'Could not reach OpenAI'],
            'malformed' => [fn () => Http::response('<html>'.self::KEY.'</html>', 200), "Could not fetch OpenAI's list"],
            'wrong shape' => [fn () => Http::response(['data' => 'nope']), "Could not fetch OpenAI's list"],
        ];
    }

    #[Test]
    #[DataProvider('failures')]
    public function a_failed_fetch_falls_back_to_typing_with_a_sentence_that_never_holds_the_key(callable $answer, string $sentence): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => $answer()]);

        $list = $this->catalog()->for('openai', 'text');

        $this->assertSame([], $list->models);
        $this->assertStringContainsString($sentence, $list->note);
        $this->assertStringContainsString('type the model id', $list->note);
        $this->assertStringNotContainsString(self::KEY, json_encode([$list, $this->logged, Cache::get('mainstay.ai.models.openai.'.hash('sha256', self::KEY."\0https://api.openai.com/v1"))]));
        $this->assertNotEmpty($this->logged, 'The failure is logged.');
        $this->assertSame(['provider', 'failure', 'status'], array_keys($this->logged[0]['context']));
    }

    #[Test]
    public function an_enormous_list_is_cut_to_a_page(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response(['data' => array_map(fn (int $n) => ['id' => "gpt-{$n}"], range(1, 5000))])]);

        $this->assertCount(1000, $this->catalog()->for('openai', 'text')->models);
    }

    #[Test]
    public function ids_that_are_not_model_ids_are_left_out(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-6-luna'], ['id' => '<script>'], ['id' => 'has space'], ['id' => str_repeat('m', 256)], ['nope' => 1], 'text']])]);

        $this->assertSame(['gpt-6-luna'], $this->catalog()->for('openai', 'text')->models);
    }

    #[Test]
    public function a_list_is_kept_under_the_provider_and_a_hash_of_the_key_and_is_not_asked_for_again(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList())]);

        $this->catalog()->for('openai', 'text');
        $this->catalog()->for('openai', 'images');
        Http::assertSentCount(1);

        $kept = Cache::get('mainstay.ai.models.openai.'.hash('sha256', self::KEY."\0https://api.openai.com/v1"));
        $this->assertIsArray($kept);
        $this->assertStringNotContainsString(self::KEY, json_encode($kept));

        config(['ai.providers.openai.key' => 'sk-another']);
        $this->catalog()->for('openai', 'text');
        Http::assertSentCount(2);

        config(['ai.providers.openai.key' => self::KEY]);
        $this->catalog()->for('openai', 'text');
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_failure_is_kept_too_so_a_provider_that_is_down_is_not_waited_on_every_load(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response('down', 500)]);

        $this->catalog()->for('openai', 'text');
        $this->catalog()->for('openai', 'text');

        Http::assertSentCount(1);
    }

    #[Test]
    public function forgetting_asks_again(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList())]);

        $this->catalog()->for('openai', 'text');
        $this->catalog()->forget();
        $this->catalog()->for('openai', 'text');

        Http::assertSentCount(2);
    }

    #[Test]
    public function a_cache_that_errors_costs_the_page_nothing_but_the_wait(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList())]);
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('store down'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('store down'));
        Cache::shouldReceive('forget')->andThrow(new \RuntimeException('store down'));

        $this->assertTrue($this->catalog()->for('openai', 'text')->isPickable());
        $this->catalog()->forget();
        $this->assertSame('RuntimeException', $this->logged[0]['context']['failure']);
    }

    #[Test]
    public function a_redirect_is_not_followed_with_the_key(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response('', 302, ['Location' => 'https://elsewhere.test/models']), 'elsewhere.test/*' => Http::response($this->openAiList())]);

        $this->assertFalse($this->catalog()->for('openai', 'text')->isPickable());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'elsewhere.test'));
    }

    #[Test]
    public function several_providers_are_asked_in_one_pool(): void
    {
        config(['ai.providers.openai.key' => self::KEY, 'ai.providers.anthropic.key' => self::KEY, 'ai.providers.groq.key' => self::KEY]);
        Http::fake([
            'api.openai.com/*' => Http::response($this->openAiList()),
            'api.anthropic.com/*' => Http::response(['data' => [['id' => 'claude-haiku-5-5']]]),
            'api.groq.com/*' => Http::response(['data' => [['id' => 'llama-4'], ['id' => 'whisper-large-v4']]]),
        ]);

        $lists = $this->catalog()->forUses(['text' => 'anthropic', 'images' => 'openai', 'transcription' => 'groq']);

        $this->assertSame(['claude-haiku-5-5'], $lists['text']->models);
        $this->assertSame(['gpt-image-2.5-flare', 'dall-e-3'], $lists['images']->models);
        $this->assertSame(['whisper-large-v4'], $lists['transcription']->models);
        Http::assertSentCount(3);
    }

    #[Test]
    public function the_models_endpoint_answers_json_for_whoever_holds_use_ai(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList())]);

        $this->signIn(['edit_entries']);
        $this->getJson(route('mainstay.plugins.ai.models', ['provider' => 'openai', 'use' => 'text']))->assertNotFound();
        Http::assertNothingSent();

        $this->signIn(['use_ai']);
        $response = $this->getJson(route('mainstay.plugins.ai.models', ['provider' => 'openai', 'use' => 'text']))->assertOk();
        $this->assertSame(['gpt-6-luna', 'gpt-6-sol', 'o4-mini'], $response->json('models'));
        $this->assertNull($response->json('note'));
        $this->assertSame(app(Connectors::class)->fallbackModel('text', 'openai'), $response->json('default'));
        $this->assertStringNotContainsString(self::KEY, $response->getContent());

        $this->getJson(route('mainstay.plugins.ai.models', ['provider' => 'nobody', 'use' => 'text']))->assertNotFound();
        $this->getJson(route('mainstay.plugins.ai.models', ['provider' => 'openai', 'use' => 'nothing']))->assertNotFound();
        $this->getJson(route('mainstay.plugins.ai.models', ['provider' => ['openai'], 'use' => 'text']))->assertNotFound();

        $this->getJson(route('mainstay.plugins.ai.models', ['provider' => 'anthropic', 'use' => 'text']))->assertOk()->assertJson(['models' => []])->assertJsonPath('note', fn ($note) => str_contains($note, 'No key for Anthropic'));
    }

    #[Test]
    public function the_screen_picks_from_the_chosen_providers_models_and_keeps_a_saved_custom_id(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        app(Connectors::class)->choose('text', 'openai', 'my-fine-tune', overrideAccess: true);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList())]);
        $this->signIn(['use_ai']);

        $page = $this->get(route('mainstay.plugins.ai.index'))->assertOk();

        $page->assertSee('data-model-picker', false)->assertSee('<option value="gpt-6-sol"', false)->assertSee('<option value="my-fine-tune" selected', false)->assertSee('Custom...');
        $page->assertSee('name="models[text]"', false)->assertSee('value="my-fine-tune"', false)->assertSee('Refresh models');
        $this->assertStringNotContainsString(self::KEY, $page->getContent());
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_fresh_install_offers_the_hosts_provider_models_and_says_saving_one_sets_the_provider(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList())]);
        $this->signIn(['use_ai']);

        $page = $this->get(route('mainstay.plugins.ai.index'))->assertOk();
        $html = $page->getContent();

        $page->assertSee('<option value="gpt-6-sol"', false)->assertSee('<option value="whisper-1"', false)->assertSee('Saving a model also sets the provider to OpenAI for this use.');
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*disabled)[^>]*name="models\[text\]"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<select(?=[^>]*disabled)[^>]*id="model-text-list"/', $html);
        $this->assertMatchesRegularExpression('/<p data-model-pin\s+class=/', $html, 'The line is shown for a use left to the config.');
        $this->assertMatchesRegularExpression('/<input(?=[^>]*disabled)[^>]*name="models\[images\]"/', $html, 'Gemini has no key, so its model cannot be picked.');
        $page->assertSee('Choose a provider to pick a model.');
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_model_picked_for_a_use_left_to_the_config_sets_the_hosts_provider_with_it(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => self::KEY]);
        $this->signIn(['use_ai']);

        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => ''], 'models' => ['text' => 'claude-sonnet-5-5']])->assertSessionHasNoErrors();

        $this->assertSame(['provider' => 'anthropic', 'chosen' => true, 'model' => 'claude-sonnet-5-5'], app(Connectors::class)->defaults()['text']);
        $this->assertSame(['anthropic'], Connector::query()->pluck('provider')->all());

        $this->app->forgetInstance(AiManager::class);
        Ai::clearResolvedInstances();
        $this->app->make(AiManager::class);
        $this->assertSame('claude-sonnet-5-5', config('ai.providers.anthropic.models.text.default'));
        $this->assertSame('anthropic', config('ai.default'));

        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => 'OK']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 9, 'output_tokens' => 1]])]);
        $this->post(route('mainstay.plugins.ai.test', 'anthropic'))->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['model'] === 'claude-sonnet-5-5');
    }

    #[Test]
    public function blanking_a_chosen_provider_and_picking_a_model_sets_the_hosts_provider_not_the_one_chosen_before(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => self::KEY, 'ai.providers.openai.key' => self::KEY]);
        $this->signIn(['use_ai']);
        app(Connectors::class)->choose('text', 'openai', 'gpt-6-sol', overrideAccess: true);
        $this->app->make(AiManager::class);
        $this->assertSame('openai', config('ai.default'), 'The screen\'s choice is what laravel/ai goes by now.');

        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => ''], 'models' => ['text' => 'claude-haiku-5-5']])->assertSessionHasNoErrors();

        $this->assertSame(['provider' => 'anthropic', 'chosen' => true, 'model' => 'claude-haiku-5-5'], app(Connectors::class)->defaults()['text']);
    }

    #[Test]
    public function a_blank_provider_and_a_blank_model_change_nothing(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => self::KEY]);
        $this->signIn(['use_ai']);

        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => ''], 'models' => ['text' => '  ']])->assertSessionHasNoErrors();

        $this->assertSame(0, Connector::query()->count());
        $this->assertFalse(app(Connectors::class)->defaults()['text']['chosen']);
    }

    #[Test]
    public function a_model_for_a_use_whose_config_provider_has_no_key_is_refused_and_nothing_is_saved(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => self::KEY, 'ai.default_for_images' => 'gemini']);
        $this->signIn(['use_ai']);

        $this->from(route('mainstay.plugins.ai.index'))->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => '', 'images' => ''], 'models' => ['text' => 'claude-sonnet-5-5', 'images' => 'gemini-3-image']])
            ->assertSessionHasErrors(['models.images' => 'Choose a provider to set a model.']);

        $this->assertSame(0, Connector::query()->count(), 'The text pin rolled back with the refusal.');
    }

    #[Test]
    public function a_model_for_a_use_the_config_provider_cannot_do_is_refused_properly(): void
    {
        config(['ai.default_for_images' => 'anthropic', 'ai.providers.anthropic.key' => self::KEY]);
        $this->signIn(['use_ai']);

        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['images' => ''], 'models' => ['images' => 'some-image-model']])
            ->assertSessionHasErrors(['defaults.images' => 'Anthropic does no images.']);

        $this->assertSame(0, Connector::query()->count());
    }

    #[Test]
    public function the_screen_asks_only_the_keyed_providers_a_kind_of_work_goes_to(): void
    {
        config(['ai.providers.openai.key' => self::KEY, 'ai.providers.anthropic.key' => self::KEY, 'ai.providers.groq.key' => self::KEY]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList()), 'api.anthropic.com/*' => Http::response(['data' => [['id' => 'claude-haiku-5-5']]])]);
        $this->signIn(['use_ai']);

        $this->get(route('mainstay.plugins.ai.index'))->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com'));

        app(Connectors::class)->choose('text', 'anthropic', overrideAccess: true);
        $this->get(route('mainstay.plugins.ai.index'))->assertOk()->assertSee('claude-haiku-5-5');
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'groq'));
    }

    #[Test]
    public function a_failed_fetch_leaves_the_text_input_and_a_note_and_never_blocks_saving(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        app(Connectors::class)->choose('text', 'openai', overrideAccess: true);
        Http::fake(['api.openai.com/*' => Http::response('down '.self::KEY, 500)]);
        $this->signIn(['use_ai']);

        $page = $this->get(route('mainstay.plugins.ai.index'))->assertOk();

        $page->assertSee("Could not fetch OpenAI's list: type the model id.")->assertSee('data-pickable="false"', false)->assertSee('name="models[text]"', false);
        $this->assertStringNotContainsString(self::KEY, $page->getContent());

        $this->post(route('mainstay.plugins.ai.defaults'), ['defaults' => ['text' => 'openai'], 'models' => ['text' => 'anything-typed']])->assertSessionHasNoErrors();
        $this->assertSame('anything-typed', app(Connectors::class)->defaults()['text']['model']);
    }

    #[Test]
    public function refreshing_is_a_post_for_use_ai_that_asks_again(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        app(Connectors::class)->choose('text', 'openai', overrideAccess: true);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiList())]);

        $this->signIn(['edit_entries']);
        $this->post(route('mainstay.plugins.ai.models.refresh'))->assertNotFound();

        $this->signIn(['use_ai']);
        $this->get(route('mainstay.plugins.ai.index'))->assertOk();
        $this->get(route('mainstay.plugins.ai.index'))->assertOk();
        Http::assertSentCount(1);

        $this->post(route('mainstay.plugins.ai.models.refresh'))->assertRedirect(route('mainstay.plugins.ai.index'));
        $this->get(route('mainstay.plugins.ai.index'))->assertOk();
        Http::assertSentCount(2);

        $route = Route::getRoutes()->getByName('mainstay.plugins.ai.models.refresh');
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['OPTIONS'])));
        $this->assertContains('mainstay', $route->gatherMiddleware());
        $this->assertContains(PreventRequestForgery::class, Route::getMiddlewareGroups()['mainstay'], 'The admin stack carries the CSRF check.');
    }
}
