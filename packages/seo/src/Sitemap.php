<?php

namespace Mainstay\Seo;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mainstay\Content\Entry;
use Mainstay\Database\Paths;
use Mainstay\Mainstay;
use Mainstay\Site\Route;
use ReflectionClass;

/*
 | The pages a search engine is told of: every live entry with an address, in
 | every locale, as a visitor may read it, less those marked noindex. The
 | list is kept until content changes (see ForgetSitemap) as paths, not links,
 | so every host a locale is served on builds its own from the one list.
 |
 | ponytail: one file, and every entry read at once. A site past the
 | protocol's 50,000 links per file wants a sitemap index and a read by page.
 */
class Sitemap
{
    private const CACHE_KEY = 'mainstay.seo.sitemap';

    /*
     | What this request read of the list, once: the token is a query and the
     | cache a read, which a sitemap of any size must pay once and not per
     | page. Not kept past the request, as the class is not shared.
     |
     | @var array{rows: list<array{type: string, id: int, locale: string, uri: string, modified: ?string}>, entries: array<string, array<string, string>>}|null
     */
    private ?array $index = null;

    public function __construct(
        private Mainstay $mainstay,
        private Paths $paths,
        private SeoFields $fields,
        private Visitor $visitor,
        private Locations $locations,
    ) {}

    /**
     * The pages whose locale is served on `$host`, each with its translations in every locale that has one.
     * None when the owner hid the whole site: an empty list is a sitemap that says there is nothing to crawl.
     *
     * @return list<array{loc: string, modified: ?string, alternates: list<array{string, string}>}>
     */
    public function pages(string $host): array
    {
        if ($this->mainstay->setting('seo.noindex_site')) {
            return [];
        }

        $default = array_key_first($this->mainstay->locales());
        $pages = [];

        foreach ($this->index()['rows'] as $row) {
            if (! $this->locations->serves($row['locale'], $host)) {
                continue;
            }

            $held = $this->translations($row['type'], $row['id']);
            $alternates = [];

            if (count($held) > 1) {
                foreach ($held as $locale => $uri) {
                    $alternates[] = [$locale, $this->locations->url($locale, $uri)];
                }

                if (isset($held[$default])) {
                    $alternates[] = ['x-default', $this->locations->url($default, $held[$default])];
                }
            }

            $pages[] = ['loc' => $this->locations->url($row['locale'], $row['uri']), 'modified' => $row['modified'], 'alternates' => $alternates];
        }

        return $pages;
    }

    /*
     | The translations of an entry a search engine may be offered, by locale,
     | each with its path: those that are live and not marked noindex. The head's
     | hreflang links and the sitemap's alternates are both this, so the two
     | never disagree about which translations exist.
     |
     | @return array<string, string>
     */
    public function translations(string $handle, int $id): array
    {
        return $this->index()['entries']["{$handle}:{$id}"] ?? [];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->index = null;
    }

    /*
     | The list kept until it is forgotten on a content event, or until what it
     | also depends on changes without one: the front page, which core
     | dispatches no event for; the locales' config; and which types have
     | addresses and of what pattern, which a deploy changes.
     |
     | @return array{rows: list<array{type: string, id: int, locale: string, uri: string, modified: ?string}>, entries: array<string, array<string, string>>}
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $token = sha1(json_encode([$this->mainstay->frontPage(), config('mainstay.locales'), $this->routing()], JSON_THROW_ON_ERROR));
        $kept = Cache::get(self::CACHE_KEY);

        if (! is_array($kept) || ($kept['token'] ?? null) !== $token) {
            $kept = ['token' => $token, 'rows' => $this->read()];
            Cache::forever(self::CACHE_KEY, $kept);
        }

        $entries = [];

        foreach ($kept['rows'] as $row) {
            $entries["{$row['type']}:{$row['id']}"][$row['locale']] = $row['uri'];
        }

        return $this->index = ['rows' => $kept['rows'], 'entries' => $entries];
    }

    /**
     * The routed types and the arguments of their #[Route].
     *
     * @return array<string, array<mixed>>
     */
    private function routing(): array
    {
        $routing = [];

        foreach ($this->mainstay->registered() as $type) {
            if (is_subclass_of($type, Entry::class) && $this->paths->routed($type)) {
                $routing[$type::handle()] = (new ReflectionClass($type))->getAttributes(Route::class)[0]->getArguments();
            }
        }

        return $routing;
    }

    /** @return list<array{type: string, id: int, locale: string, uri: string, modified: ?string}> */
    private function read(): array
    {
        $request = app(Request::class);
        $rows = [];

        foreach ($this->mainstay->registered() as $type) {
            if (! is_subclass_of($type, Entry::class) || ! $this->paths->routed($type)) {
                continue;
            }

            foreach (array_keys($this->mainstay->locales()) as $locale) {
                try {
                    $entries = $this->visitor->draw($request, $locale, fn () => $this->mainstay->find($type, locale: $locale, depth: 0));
                } catch (AuthorizationException) {
                    continue 2;
                }

                foreach ($entries as $entry) {
                    if (isset($entry->uri) && ! $this->fields->of($entry)?->noindex) {
                        $rows[] = ['type' => $type::handle(), 'id' => $entry->id, 'locale' => $locale, 'uri' => $entry->uri, 'modified' => isset($entry->updatedAt) ? $entry->updatedAt->toIso8601String() : null];
                    }
                }
            }
        }

        return $rows;
    }
}
