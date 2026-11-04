<?php

namespace Mainstay\Ai;

use Closure;
use Exception;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Mainstay\Auth\Gate;
use Mainstay\Mainstay;
use Mainstay\Plugin;
use Mainstay\Policies\ConnectorPolicy;

use function Laravel\Ai\agent;

/*
 | The AI providers a site can talk to, as WordPress's Settings > Connectors
 | has them: every provider laravel/ai's config names and every one a plugin
 | declares, each with its key from the host's config -- the provider's env
 | variable -- or else one saved from the admin, encrypted; and the provider
 | the site chose for each kind of work. All of it goes into laravel/ai's own
 | config, so code prompts with laravel/ai itself --
 | `agent('...')->prompt('...')`, `Image::of('...')->generate()` -- and
 | reaches whatever the site connected.
 |
 | `Mainstay::connectors()->save('anthropic', $key)`. Every call needs
 | `manage_connectors`, unless `overrideAccess` says the caller acts on its
 | own authority.
 */
final class Connectors
{
    /*
     | Each kind of work laravel/ai keeps a default provider for: the contract
     | a provider implements to do it, the config naming its default, and what
     | the screen calls it.
     */
    public const USES = [
        'text' => [TextProvider::class, 'ai.default', 'Text'],
        'images' => [ImageProvider::class, 'ai.default_for_images', 'Images'],
        'audio' => [AudioProvider::class, 'ai.default_for_audio', 'Speech'],
        'transcription' => [TranscriptionProvider::class, 'ai.default_for_transcription', 'Transcription'],
        'embeddings' => [EmbeddingProvider::class, 'ai.default_for_embeddings', 'Embeddings'],
        'reranking' => [RerankingProvider::class, 'ai.default_for_reranking', 'Reranking'],
        'classification' => [ClassificationProvider::class, 'ai.default_for_classification', 'Classification'],
    ];

    /* laravel/ai's own providers, by the names its config gives them, as
       their makers spell themselves. */
    private const LABELS = [
        'anthropic' => 'Anthropic',
        'azure' => 'Azure OpenAI',
        'bedrock' => 'Amazon Bedrock',
        'cohere' => 'Cohere',
        'deepseek' => 'DeepSeek',
        'eleven' => 'ElevenLabs',
        'gemini' => 'Google Gemini',
        'groq' => 'Groq',
        'jina' => 'Jina AI',
        'mistral' => 'Mistral AI',
        'ollama' => 'Ollama',
        'openai' => 'OpenAI',
        'openai-compatible' => 'OpenAI-compatible',
        'openrouter' => 'OpenRouter',
        'typesafe' => 'TypeSafe',
        'voyageai' => 'Voyage AI',
        'xai' => 'xAI',
    ];

    /** @var array{providers: array<string, array<string, mixed>>, defaults: array<string, mixed>}|null the host's AI config as it was before any of this was written into it */
    private ?array $hosted = null;

    public function __construct(private Mainstay $mainstay) {}

    /**
     * Every provider's laravel/ai config, by name: the host's, and each one a
     * plugin declares beneath the host's own entry of that name -- a driver
     * the plugin hands over as a closure is registered under the provider's
     * name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function providers(): array
    {
        $providers = $this->hosted()['providers'];

        foreach ($this->declared() as $name => $config) {
            $providers[$name] = [...$config, 'driver' => $config['driver'] instanceof Closure ? $name : $config['driver'], ...($providers[$name] ?? [])];
        }

        return $providers;
    }

    /**
     * Each provider as the screen lists it, by what it is called: what it can
     * do, where its key comes from -- the host's config, the screen, or
     * nowhere yet, or saved under another APP_KEY -- and, for one its config
     * does not make, why not, so one broken entry leaves the others to be
     * connected.
     *
     * @return list<array{name: string, label: string, uses: list<string>, key: 'config'|'saved'|'unreadable'|null, broken: string|null}>
     */
    public function all(bool $overrideAccess = false): array
    {
        $this->authorize($overrideAccess);
        $ai = app(AiManager::class);
        $saved = Connector::query()->whereNotNull('key')->get()
            ->mapWithKeys(fn (Connector $connector) => [$connector->provider => $connector->readableKey() === null ? 'unreadable' : 'saved'])
            ->all();

        return collect(array_keys($this->providers()))
            ->map(function (string $name) use ($ai, $saved) {
                try {
                    $provider = $ai->instance($name);
                } catch (Exception $exception) {
                    $provider = null;
                    $broken = $this->redact($name, $exception->getMessage());
                }

                return [
                    'name' => $name,
                    'label' => $this->label($name),
                    'uses' => array_keys(array_filter(self::USES, fn (array $use) => $provider instanceof $use[0])),
                    'key' => match (true) {
                        in_array($name, $this->configured(), true) => 'config',
                        default => $saved[$name] ?? null,
                    },
                    'broken' => $broken ?? null,
                ];
            })
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** @return array<string, array{provider: string|null, chosen: bool}> the provider each kind of work goes to, and whether the screen chose it rather than the host's config */
    public function defaults(): array
    {
        app(AiManager::class);
        $chosen = Connector::query()->pluck('defaults', 'provider')->filter(fn (array $uses, string $name) => array_key_exists($name, $this->providers()));

        return array_map(fn (string $use) => [
            'provider' => self::first(config(self::USES[$use][1])),
            'chosen' => $chosen->contains(fn (array $uses) => in_array($use, $uses, true)),
        ], array_combine(array_keys(self::USES), array_keys(self::USES)));
    }

    public function label(string $name): string
    {
        return $this->providers()[$name]['label'] ?? self::LABELS[$name] ?? Str::headline($name);
    }

    /* A key saved here, for a provider the host's config gives none: the
       config's wins, so one saved beneath it would never be sent. */
    public function save(string $name, string $key, bool $overrideAccess = false): void
    {
        $this->authorize($overrideAccess);
        $this->known($name);
        $key = trim($key);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:4096']])->validate();

        if (in_array($name, $this->configured(), true)) {
            throw ValidationException::withMessages(['key' => "{$this->label($name)}'s key is set in the site's config, which wins over one saved here. Change it there."]);
        }

        Connector::query()->updateOrCreate(['provider' => $name], ['key' => $key]);
        $this->refresh();
    }

    public function forget(string $name, bool $overrideAccess = false): void
    {
        $this->authorize($overrideAccess);
        $this->known($name);

        if ($connector = Connector::query()->where('provider', $name)->first()) {
            $connector->defaults === [] ? $connector->delete() : $connector->update(['key' => null]);
        }

        $this->refresh();
    }

    /* The provider a kind of work goes to, or null to leave it to the host's
       config again. */
    public function choose(string $use, ?string $name, bool $overrideAccess = false): void
    {
        $this->authorize($overrideAccess);
        $contract = self::USES[$use][0] ?? throw ValidationException::withMessages(['defaults' => "laravel/ai keeps no default provider for {$use}."]);

        if ($name !== null) {
            $this->known($name);

            if (! app(AiManager::class)->instance($name) instanceof $contract) {
                throw ValidationException::withMessages(["defaults.{$use}" => "{$this->label($name)} does no ".Str::lower(self::USES[$use][2]).'.']);
            }
        }

        DB::transaction(function () use ($use, $name) {
            foreach (Connector::query()->lockForUpdate()->get() as $connector) {
                $left = array_values(array_diff($connector->defaults, [$use]));

                if ($connector->provider !== $name && $left !== $connector->defaults) {
                    $left === [] && ! $connector->holdsKey() ? $connector->delete() : $connector->update(['defaults' => $left]);
                }
            }

            if ($name !== null) {
                $connector = Connector::query()->firstOrNew(['provider' => $name]);
                $connector->defaults = array_values(array_unique([...$connector->defaults, $use]));
                $connector->save();
            }
        });

        $this->refresh();
    }

    /*
     | Whether the provider answers with its key: its cheapest text model
     | asked for one word, or one word embedded where it does no text, so a
     | test costs a fraction of a cent. What it answered, or why it did not,
     | the key taken out wherever the provider or the client echoed it.
     */
    public function test(string $name, bool $overrideAccess = false): string
    {
        $this->authorize($overrideAccess);
        $this->known($name);

        try {
            $provider = app(AiManager::class)->instance($name);

            return match (true) {
                $provider instanceof TextProvider => trim(agent('Reply with the one word OK.')->prompt('Are you there?', provider: $name, model: $provider->cheapestTextModel())->text),
                $provider instanceof EmbeddingProvider => count(Embeddings::for(['OK'])->generate(provider: $name)->first()).' dimensions',
                default => throw new Exception("Mainstay tests a provider by text or embeddings, and {$this->label($name)} does neither."),
            };
        } catch (Exception $exception) {
            throw ValidationException::withMessages(['test' => $this->redact($name, $exception->getMessage())]);
        }
    }

    /* A provider's or the client's words with the provider's key taken out,
       wherever they echoed it. */
    private function redact(string $name, string $message): string
    {
        $key = config("ai.providers.{$name}.key");

        return is_string($key) && $key !== '' ? str_replace($key, '[key]', $message) : $message;
    }

    /*
     | Into laravel/ai as it is made, once a process: the plugins' providers
     | and their drivers, each saved key where the host's config sets none,
     | and each default the screen chose. Read then and not as Mainstay boots,
     | so a request that prompts nothing reads no table, and config:cache
     | stores none of it.
     */
    public function apply(AiManager $ai): void
    {
        $hosted = $this->hosted();
        $providers = $this->providers();
        $saved = Connector::query()->get()->keyBy('provider');

        foreach ($this->declared() as $name => $config) {
            if ($config['driver'] instanceof Closure && $providers[$name]['driver'] === $name) {
                /* Static, so laravel/ai binds this one to itself and the plugin's
                   closure keeps its own $this. */
                $driver = $config['driver'];
                $ai->extend($name, static fn ($app, array $config) => $driver($app, $config));
            }
        }

        foreach ($providers as $name => $config) {
            $key = in_array($name, $this->configured(), true) ? null : $saved->get($name)?->readableKey();
            config(["ai.providers.{$name}" => $key === null ? $config : [...$config, 'key' => $key]]);
        }

        foreach (self::USES as $use => [, $setting]) {
            $chosen = $saved->first(fn (Connector $connector) => in_array($use, $connector->defaults, true) && isset($providers[$connector->provider]));
            config([$setting => $chosen->provider ?? $hosted['defaults'][$use]]);
        }
    }

    /** @return list<string> the providers whose key the host's config sets */
    private function configured(): array
    {
        return array_keys(array_filter($this->providers(), fn (array $config) => is_string($config['key'] ?? null) && $config['key'] !== ''));
    }

    /** @return array<string, array<string, mixed>> the providers plugins declare, by name */
    private function declared(): array
    {
        return array_merge(...array_map(fn (Plugin $plugin) => $plugin->providers(), array_values($this->mainstay->plugins())));
    }

    private function hosted(): array
    {
        return $this->hosted ??= [
            'providers' => config('ai.providers', []),
            'defaults' => array_map(fn (array $use) => config($use[1]), self::USES),
        ];
    }

    /* After a write, laravel/ai as made in this process reads it too, once
       the write commits: a caller's transaction rolling back leaves it as it
       was. A queue worker made before reads it once restarted. */
    private function refresh(): void
    {
        DB::afterCommit(function () {
            if (app()->resolved(AiManager::class)) {
                $this->apply($ai = app(AiManager::class));
                $ai->forgetInstance(array_keys($this->providers()));
            }
        });
    }

    private function known(string $name): void
    {
        if (! array_key_exists($name, $this->providers())) {
            throw new RecordNotFoundException("There is no AI provider called {$name}.");
        }
    }

    private function authorize(bool $overrideAccess): void
    {
        if (! $overrideAccess) {
            Gate::for(Connector::class, ConnectorPolicy::class)->authorize('manage', Connector::class);
        }
    }

    /* A default as the screen names it: laravel/ai also takes an enum, and
       a list of providers to fail over through, whose first is the one. */
    private static function first(mixed $default): ?string
    {
        return match (true) {
            $default instanceof Lab => $default->value,
            is_array($default) && $default !== [] => array_is_list($default) ? self::first($default[0]) : (string) array_key_first($default),
            is_string($default) => $default,
            default => null,
        };
    }
}
