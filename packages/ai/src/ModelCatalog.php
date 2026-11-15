<?php

namespace Mainstay\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/*
 | The models a provider offers for a kind of work, asked of the provider's
 | own list with the key saved for it. Only the server asks: the key goes in
 | a request header and nowhere else, and what reaches the screen, the cache
 | and the log is the models, a fixed sentence, a status or an exception's
 | class -- never a provider's or the client's words, which can echo the key.
 |
 | The address is the provider's from laravel/ai's config -- the host's, or
 | the plugin's that declared it -- never from a request, so nobody who can
 | save a key points the server at an address of their choosing. Redirects are
 | not followed, since a header the key rides in would follow them.
 |
 | Asked of providers that have a key (or need none) and that something asks
 | about: the one chosen for a kind of work, or the one being chosen. A list is
 | kept ten minutes under the provider and a hash of its key and address, a
 | failure one minute so a provider that is down is not waited on at every
 | load, and a cache that errors costs the page only the wait.
 */
class ModelCatalog
{
    private const TIMEOUT_SECONDS = 5;

    private const LIST_TTL_SECONDS = 600;

    private const FAILURE_TTL_SECONDS = 60;

    /* A list is a few kilobytes; a body far past that is not one. */
    private const MAX_BODY_BYTES = 2_000_000;

    /** @var array<string, array{models: list<array{id: string, uses: list<string>}>}|array{note: string}> what was answered this request, by cache key, so a cache that keeps nothing is not asked past once */
    private array $answered = [];

    public function __construct(private Connectors $connectors) {}

    /**
     * @param  array<string, string|null>  $providers  the provider asked about for each kind of work
     * @return array<string, ModelList> by kind of work
     */
    public function forUses(array $providers): array
    {
        $listed = $this->listed(array_values(array_unique(array_filter($providers))));

        return array_map(fn (string $use) => $this->pick($providers[$use] ?? null, $use, $listed), array_combine(array_keys($providers), array_keys($providers)));
    }

    public function for(string $provider, string $use): ModelList
    {
        return $this->forUses([$use => $provider])[$use];
    }

    /* Every list is asked for again on the next load. */
    public function forget(): void
    {
        foreach (array_keys($this->connectors->providers()) as $name) {
            $this->cache(fn () => Cache::forget($this->cacheKey($name)));
        }

        $this->answered = [];
    }

    /** @param array<string, array{models: list<array{id: string, uses: list<string>}>}|array{note: string}> $listed */
    private function pick(?string $name, string $use, array $listed): ModelList
    {
        if ($name === null) {
            return ModelList::unavailable('Choose a provider to see its models.');
        }

        $outcome = $listed[$name];

        if (isset($outcome['note'])) {
            return ModelList::unavailable($outcome['note']);
        }

        $kind = ModelSources::listedAs($use);
        $ids = array_column(array_filter($outcome['models'], fn (array $model) => in_array($kind, $model['uses'], true)), 'id');

        return $ids === [] ? ModelList::unavailable("{$this->connectors->label($name)} lists no ".Str::lower(Connectors::USES[$use][2]).' models: type the model id.') : new ModelList($ids);
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array{models: list<array{id: string, uses: list<string>}>}|array{note: string}>
     */
    private function listed(array $names): array
    {
        app(AiManager::class);
        $outcomes = [];
        $requests = [];

        foreach ($names as $name) {
            $label = $this->connectors->label($name);
            $driver = $this->connectors->providers()[$name]['driver'] ?? null;
            $config = (array) config("ai.providers.{$name}");
            $request = is_string($driver) ? ModelSources::request($driver, $config) : null;

            if ($request === null || ! in_array(parse_url($request['url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                $outcomes[$name] = ['note' => "{$label} has no list of models to fetch: type the model id."];
            } elseif (($config['key'] ?? '') === '' && ModelSources::needsKey($driver)) {
                $outcomes[$name] = ['note' => "No key for {$label} yet: type the model id, or save its key to pick from its models."];
            } elseif (is_array($remembered = $this->answered[$this->cacheKey($name)] ?? $this->cache(fn () => Cache::get($this->cacheKey($name))))) {
                $outcomes[$name] = $remembered;
            } else {
                $requests[$name] = [$driver, $request];
            }
        }

        foreach ($this->fetch($requests) as $name => $outcome) {
            $outcomes[$name] = $this->answered[$this->cacheKey($name)] = $outcome;
            $this->cache(fn () => Cache::put($this->cacheKey($name), $outcome, isset($outcome['note']) ? self::FAILURE_TTL_SECONDS : self::LIST_TTL_SECONDS));
        }

        return $outcomes;
    }

    /**
     * @param  array<string, array{string, array{url: string, headers: array<string, string>, query: array<string, mixed>}}>  $requests
     * @return array<string, array{models: list<array{id: string, uses: list<string>}>}|array{note: string}>
     */
    private function fetch(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        try {
            $answers = Http::pool(fn (Pool $pool) => array_map(
                fn (string $name) => $pool->as($name)->withHeaders($requests[$name][1]['headers'])->connectTimeout(self::TIMEOUT_SECONDS)->timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->withOptions(['on_headers' => self::refuseLargeBody(...)])->acceptJson()->get($requests[$name][1]['url'], $requests[$name][1]['query']),
                array_keys($requests),
            ));
        } catch (Throwable $failure) {
            $answers = array_map(fn () => $failure, $requests);
        }

        return array_map(fn (string $name) => $this->outcome($name, $requests[$name][0], $answers[$name] ?? null), array_combine(array_keys($requests), array_keys($requests)));
    }

    /** @return array{models: list<array{id: string, uses: list<string>}>}|array{note: string} */
    private function outcome(string $name, string $driver, mixed $answer): array
    {
        $label = $this->connectors->label($name);

        if (! $answer instanceof Response) {
            $this->logFailure($name, $answer instanceof Throwable ? $answer::class : 'none', null);

            return ['note' => $answer instanceof ConnectionException ? "Could not reach {$label} for its list: type the model id." : "Could not fetch {$label}'s list: type the model id."];
        }

        if ($answer->successful() && strlen($answer->body()) <= self::MAX_BODY_BYTES && ($models = ModelSources::models($driver, $answer->json())) !== null) {
            return ['models' => $models];
        }

        $this->logFailure($name, $answer->successful() ? 'unreadable' : 'status', $answer->status());

        return ['note' => match (true) {
            in_array($answer->status(), [401, 403], true) => "{$label} rejected the key for its list: type the model id.",
            default => "Could not fetch {$label}'s list: type the model id.",
        }];
    }

    /* Before the body is read, where the provider says how long it is. */
    private static function refuseLargeBody(ResponseInterface $response): void
    {
        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BODY_BYTES) {
            throw new RuntimeException('The list is larger than a list of models is.');
        }
    }

    private function logFailure(string $name, string $failure, ?int $status): void
    {
        Log::warning('An AI provider did not list its models.', ['provider' => $name, 'failure' => $failure, 'status' => $status]);
    }

    /* The provider's name and a hash of the key and the address, so a new key
       or address is a new list and no key is written to the cache. */
    private function cacheKey(string $name): string
    {
        $config = (array) config("ai.providers.{$name}");

        return "mainstay.ai.models.{$name}.".hash('sha256', (is_string($config['key'] ?? null) ? $config['key'] : '')."\0".(is_string($config['url'] ?? null) ? $config['url'] : ''));
    }

    /* A cache that errors must not break the screen it was only there to speed up. */
    private function cache(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $failure) {
            $this->logFailure('cache', $failure::class, null);

            return null;
        }
    }
}
