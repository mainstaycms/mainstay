<?php

namespace Mainstay\Ai;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/*
 | Where each driver lists its models, how it is asked, and which kind of
 | work each answer is for. The base URL, key and headers are the ones
 | laravel/ai's gateway of that driver uses, read from the provider's config.
 |
 | A driver with no entry here has no list to ask: Azure names models by the
 | deployments its owner makes, Bedrock signs its requests with AWS
 | credentials, and Jina, Voyage AI and TypeSafe publish none this reads.
 |
 | The kinds are best effort: a provider's list is rarely labelled, so most
 | are told apart by the id, and one a rule misses is typed in by hand.
 */
final class ModelSources
{
    /** The most models read from one answer, so a list that is endless stays a page. */
    public const LIMIT = 1000;

    private const OPENAI_STYLE = ['openai' => 'https://api.openai.com/v1', 'groq' => 'https://api.groq.com/openai/v1', 'mistral' => 'https://api.mistral.ai/v1', 'xai' => 'https://api.x.ai/v1', 'deepseek' => 'https://api.deepseek.com/v1', 'openai-compatible' => null];

    /* Checked in this order: `gpt-4o-mini-tts` is speech, not text, and `gpt-4o-transcribe` is not speech. */
    private const ID_RULES = [
        'images' => '/gpt-image|dall-e|imagen|flux|stable-diffusion|-image(-|$)/i',
        'transcription' => '/whisper|transcribe|voxtral|scribe/i',
        'audio' => '/(^|[-_\/])tts|speech|playai/i',
        'embeddings' => '/embed|bge-|minilm|e5-/i',
        'reranking' => '/rerank/i',
    ];

    private const NOT_FOR_WORK = '/moderation|realtime|sora|davinci|babbage|guard/i';

    /** @return array{url: string, headers: array<string, string>, query: array<string, mixed>}|null null where the driver has no list to ask */
    public static function request(string $driver, array $config): ?array
    {
        $key = is_string($config['key'] ?? null) ? $config['key'] : '';
        $base = fn (string $default) => rtrim((string) ($config['url'] ?? $default), '/');
        $bearer = $key === '' ? [] : ['Authorization' => 'Bearer '.$key];
        $extra = is_array($config['headers'] ?? null) ? $config['headers'] : [];

        $request = match (true) {
            $driver === 'anthropic' => ['url' => $base('https://api.anthropic.com/v1').'/models', 'headers' => ['x-api-key' => $key, 'anthropic-version' => $config['version'] ?? '2023-06-01'], 'query' => ['limit' => self::LIMIT]],
            $driver === 'gemini' => ['url' => $base('https://generativelanguage.googleapis.com/v1beta').'/models', 'headers' => ['x-goog-api-key' => $key], 'query' => ['pageSize' => self::LIMIT]],
            $driver === 'ollama' => ['url' => $base('http://localhost:11434').'/api/tags', 'headers' => $bearer, 'query' => []],
            $driver === 'cohere' => ['url' => preg_replace('#/v\d+$#', '', $base('https://api.cohere.com/v2')).'/v1/models', 'headers' => $bearer, 'query' => ['page_size' => self::LIMIT]],
            $driver === 'eleven' => ['url' => $base('https://api.elevenlabs.io/v1').'/models', 'headers' => $key === '' ? [] : ['xi-api-key' => $key], 'query' => []],
            $driver === 'openrouter' => ['url' => $base('https://openrouter.ai/api/v1').'/models', 'headers' => $bearer, 'query' => ['output_modalities' => 'all']],
            array_key_exists($driver, self::OPENAI_STYLE) && ($config['url'] ?? self::OPENAI_STYLE[$driver]) !== null => ['url' => $base((string) self::OPENAI_STYLE[$driver]).'/models', 'headers' => $bearer, 'query' => []],
            default => null,
        };

        return $request === null ? null : array_merge($request, ['headers' => [...$request['headers'], ...$extra]]);
    }

    /** @return bool whether a provider of this driver answers without a key */
    public static function needsKey(string $driver): bool
    {
        return ! in_array($driver, ['ollama', 'openai-compatible'], true);
    }

    /**
     * @return list<array{id: string, uses: list<string>}>|null null where the answer is not the shape the driver sends
     */
    public static function models(string $driver, mixed $body): ?array
    {
        if (! is_array($body)) {
            return null;
        }

        $entries = match ($driver) {
            'gemini', 'ollama', 'cohere' => $body['models'] ?? null,
            'eleven' => array_is_list($body) ? $body : null,
            default => $body['data'] ?? (array_is_list($body) ? $body : null),
        };

        if (! is_array($entries)) {
            return null;
        }

        $models = [];

        foreach (array_slice(array_filter($entries, 'is_array'), 0, self::LIMIT) as $entry) {
            $model = self::model($driver, $entry);

            if ($model !== null) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /** @return array{id: string, uses: list<string>}|null */
    private static function model(string $driver, array $entry): ?array
    {
        $id = match ($driver) {
            'gemini' => Str::after((string) ($entry['name'] ?? ''), 'models/'),
            'ollama', 'cohere' => (string) ($entry['name'] ?? ''),
            'eleven' => (string) ($entry['model_id'] ?? ''),
            default => (string) ($entry['id'] ?? ''),
        };

        if ($id === '' || preg_match(Connectors::MODEL_PATTERN, $id) !== 1 || strlen($id) > Connectors::MODEL_MAX_LENGTH) {
            return null;
        }

        $uses = match ($driver) {
            'anthropic' => ['text'],
            'gemini' => self::gemini($id, (array) ($entry['supportedGenerationMethods'] ?? [])),
            'cohere' => self::cohere((array) ($entry['endpoints'] ?? [])),
            'eleven' => ($entry['can_do_text_to_speech'] ?? false) === true ? ['audio'] : [],
            'openrouter' => self::openRouter((array) Arr::get($entry, 'architecture.output_modalities', [])),
            'deepseek' => ['text'],
            default => self::byId($id),
        };

        return $uses === [] ? null : ['id' => $id, 'uses' => $uses];
    }

    /** @return list<string> */
    private static function byId(string $id): array
    {
        foreach (self::ID_RULES as $use => $pattern) {
            if (preg_match($pattern, $id) === 1) {
                return [$use];
            }
        }

        return preg_match(self::NOT_FOR_WORK, $id) === 1 ? [] : ['text'];
    }

    /** @return list<string> */
    private static function gemini(string $id, array $methods): array
    {
        return match (true) {
            array_intersect(['embedContent', 'batchEmbedContents'], $methods) !== [] => ['embeddings'],
            in_array('predict', $methods, true) => ['images'],
            in_array('generateContent', $methods, true) => self::generating($id),
            default => [],
        };
    }

    /* Gemini transcribes with its ordinary models, so they serve transcription too. */
    private static function generating(string $id): array
    {
        return match (true) {
            preg_match('/tts/i', $id) === 1 => ['audio'],
            preg_match('/-image/i', $id) === 1 => ['images'],
            default => ['text', 'transcription'],
        };
    }

    /** @return list<string> */
    private static function cohere(array $endpoints): array
    {
        $kinds = ['text' => 'chat', 'embeddings' => 'embed', 'reranking' => 'rerank', 'classification' => 'classify'];

        return array_keys(array_filter($kinds, fn (string $endpoint) => in_array($endpoint, $endpoints, true)));
    }

    /* OpenRouter says what a model produces; a model that also answers in text is a text model. */
    private static function openRouter(array $outputs): array
    {
        $answersInText = in_array('text', $outputs, true);
        $makesImages = in_array('image', $outputs, true);
        $makesAudio = in_array('audio', $outputs, true);

        return match (true) {
            in_array('embeddings', $outputs, true) => ['embeddings'],
            in_array('rerank', $outputs, true) => ['reranking'],
            in_array('transcription', $outputs, true) => ['transcription'],
            in_array('speech', $outputs, true) => ['audio'],
            $makesImages && ! $answersInText => ['images'],
            $makesAudio && ! $answersInText => ['audio'],
            default => ['text'],
        };
    }

    /* A model that answers in text is the one a classification asks of. */
    public static function listedAs(string $use): string
    {
        return $use === 'classification' ? 'text' : $use;
    }
}
