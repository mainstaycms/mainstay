<?php

namespace Mainstay\Seo;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;
use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Mainstay;
use ReflectionClass;

/*
 | What goes in a page's <head>: its title in the locale's template, the
 | description, canonical address, robots directive, hreflang alternates,
 | Open Graph and Twitter tags and JSON-LD, worked out from the entry's SEO
 | field and, where that is empty, from the entry itself and the site's
 | settings. Pure data; the <x-seo::head> component draws it.
 */
class Head
{
    /* Properties an entry may hold its own description in, tried in turn. */
    private const DESCRIPTION_PROPERTIES = ['description', 'summary', 'excerpt'];

    /* How far up a `parent` chain a breadcrumb goes, so a cycle ends. */
    private const BREADCRUMB_DEPTH = 8;

    private const SEPARATORS = '\s|\-–—·•:';

    public function __construct(
        private Mainstay $mainstay,
        private SeoFields $fields,
        private LocaleSettings $locales,
        private Locations $locations,
        private Sitemap $sitemap,
    ) {}

    /**
     * @return array{title: string, description: ?string, canonical: ?string, robots: ?string, alternates: list<array{string, string}>, meta: list<array{string, string, string}>, graph: ?string}
     */
    public function build(?Entry $entry = null, ?string $title = null, ?string $description = null): array
    {
        $meta = $entry === null ? null : $this->fields->of($entry);
        $locale = $this->localeOf($entry);
        $site = (string) $this->mainstay->setting('seo.site_name', '');
        $page = $meta->title ?? $title ?? $entry->title ?? '';
        $description = $meta->description ?? $description ?? $this->ownDescription($entry) ?? $this->nonBlank($this->mainstay->setting('seo.default_description'));
        $canonical = $meta->canonical ?? $this->addressOf($entry, $locale) ?? URL::current();
        $image = $this->shareable($meta?->socialImage) ?? $this->shareable($this->locales->image($locale));
        $article = $entry === null ? null : $this->article($entry);

        return [
            'title' => $this->title($this->locales->template($locale), $page, $site),
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $this->robots($meta),
            'alternates' => $this->alternates($entry),
            'meta' => $this->social($meta, $page, $description, $canonical, $image, $site, $locale, $article !== null),
            'graph' => $this->graph($entry, $article, $canonical, $page, $image, $locale, $site),
        ];
    }

    /* The template filled in; with no site name its token goes, and the
       separator it leaves dangling with it. */
    public function title(string $template, string $page, string $site): string
    {
        $filled = str_replace(['%title%', '%site%'], [$page, $site], $template);

        return $site === '' ? preg_replace('/^['.self::SEPARATORS.']+|['.self::SEPARATORS.']+$/u', '', $filled) : $filled;
    }

    private function localeOf(?Entry $entry): string
    {
        $locales = array_keys($this->mainstay->locales());

        return $entry !== null && isset($entry->locale) ? $entry->locale : (in_array(app()->getLocale(), $locales, true) ? app()->getLocale() : $locales[0]);
    }

    private function ownDescription(?Entry $entry): ?string
    {
        foreach (self::DESCRIPTION_PROPERTIES as $property) {
            if ($entry !== null && isset($entry->{$property}) && is_string($entry->{$property}) && trim($entry->{$property}) !== '') {
                return trim($entry->{$property});
            }
        }

        return null;
    }

    private function nonBlank(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function addressOf(?Entry $entry, string $locale): ?string
    {
        return $entry !== null && isset($entry->uri) ? $this->locations->url($locale, $entry->uri) : null;
    }

    /* Only what is restrictive is said: index and follow are the default. */
    private function robots(?SeoMeta $meta): ?string
    {
        $site = (bool) $this->mainstay->setting('seo.noindex_site');
        $directives = array_filter([
            $site || $meta?->noindex ? 'noindex' : null,
            $site || $meta?->nofollow ? 'nofollow' : null,
        ]);

        return $directives === [] ? null : implode(', ', $directives);
    }

    /**
     * @return list<array{string, string}> hreflang and address, once the entry has more than one translation a search engine may be offered
     */
    private function alternates(?Entry $entry): array
    {
        if ($entry === null || ! isset($entry->id)) {
            return [];
        }

        $held = $this->sitemap->translations($entry::handle(), $entry->id);

        if (count($held) < 2) {
            return [];
        }

        $alternates = [];

        foreach ($held as $locale => $uri) {
            $alternates[] = [$locale, $this->locations->url($locale, $uri)];
        }

        $default = array_key_first($this->mainstay->locales());

        if (isset($held[$default])) {
            $alternates[] = ['x-default', $this->locations->url($default, $held[$default])];
        }

        return $alternates;
    }

    /* A crawler and a share card's cache cannot use an expiring link, so only
       a public image will do: a restricted one, or one that is gone, is
       passed over, for the locale's default to stand in. */
    private function shareable(?Media $image): ?Media
    {
        return $image === null || $image->isRestricted() || $image->url('social') === null ? null : $image;
    }

    /**
     * @return list<array{string, string, string}> attribute, key and content of each tag
     */
    private function social(?SeoMeta $meta, string $page, ?string $description, string $canonical, ?Media $image, string $site, string $locale, bool $article): array
    {
        $title = $meta->socialTitle ?? $page;
        $description = $meta->socialDescription ?? $description;
        $src = $image?->url('social');
        $src = $src === null ? null : URL::to($src);

        $tags = [
            ['property', 'og:type', $article ? 'article' : 'website'],
            ['property', 'og:title', $title],
            ['property', 'og:description', $description],
            ['property', 'og:url', $canonical],
            ['property', 'og:site_name', $site],
            ['property', 'og:locale', str_contains($locale, '-') || str_contains($locale, '_') ? str_replace('-', '_', $locale) : null],
            ['property', 'og:image', $src],
            ['property', 'og:image:alt', $src === null ? null : $image->alt],
            ['name', 'twitter:card', $src === null ? 'summary' : 'summary_large_image'],
            ['name', 'twitter:title', $title],
            ['name', 'twitter:description', $description],
            ['name', 'twitter:image', $src],
        ];

        return array_values(array_filter($tags, fn (array $tag) => $tag[2] !== null && $tag[2] !== ''));
    }

    /* Organization from the settings, the Article a type opted into, and the
       breadcrumbs a `parent` chain makes; one @graph, or null for none. */
    private function graph(?Entry $entry, ?array $article, string $canonical, string $page, ?Media $image, string $locale, string $site): ?string
    {
        $organization = $this->nonBlank($this->mainstay->setting('seo.organization_name')) ?? $this->nonBlank($site);
        $root = $this->locations->url($locale, '/');
        $nodes = array_filter([
            $organization === null ? null : ['@type' => 'Organization', 'name' => $organization, 'url' => $root],
            $article === null ? null : $this->articleNode($entry, $article, $canonical, $page, $image),
            $entry === null ? null : $this->breadcrumbs($entry, $root, $site, $canonical, $page),
        ]);

        if ($nodes === []) {
            return null;
        }

        return json_encode(['@context' => 'https://schema.org', '@graph' => array_values($nodes)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }

    /** @return array{published: ?string, author: ?string}|null */
    private function article(Entry $entry): ?array
    {
        $declared = (new ReflectionClass($entry))->getAttributes(Article::class)[0] ?? null;

        if ($declared === null) {
            return null;
        }

        $article = $declared->newInstance();
        $date = isset($entry->{$article->published}) ? $entry->{$article->published} : null;

        return [
            'published' => $date instanceof CarbonInterface ? $date->toIso8601String() : $this->nonBlank($date),
            'author' => $article->author === null || ! isset($entry->{$article->author}) ? null : $this->name($entry->{$article->author}),
        ];
    }

    private function name(mixed $author): ?string
    {
        return match (true) {
            is_string($author) => $this->nonBlank($author),
            is_object($author) && isset($author->title) => $this->nonBlank($author->title),
            is_object($author) && isset($author->name) => $this->nonBlank($author->name),
            default => null,
        };
    }

    private function articleNode(Entry $entry, array $article, string $canonical, string $page, ?Media $image): array
    {
        $src = $image?->url('social');

        return array_filter([
            '@type' => 'Article',
            'headline' => $page,
            'mainEntityOfPage' => $canonical,
            'datePublished' => $article['published'],
            'dateModified' => isset($entry->updatedAt) ? $entry->updatedAt->toIso8601String() : null,
            'author' => $article['author'] === null ? null : ['@type' => 'Person', 'name' => $article['author']],
            'image' => $src === null ? null : URL::to($src),
        ], fn (mixed $value) => $value !== null);
    }

    /* The `parent` property's chain, outermost first, ending at this page;
       none when the type has no parent or this page has none set. */
    private function breadcrumbs(Entry $entry, string $root, string $site, string $canonical, string $page): ?array
    {
        $trail = [];
        $parent = $entry->parent ?? null;

        for ($depth = 0; $parent instanceof Entry && ! $parent->missing && $depth < self::BREADCRUMB_DEPTH; $depth++) {
            $address = $parent->url();

            if ($address === null) {
                break;
            }

            array_unshift($trail, [$parent->title, $address]);
            $parent = $parent->parent ?? null;
        }

        if ($trail === []) {
            return null;
        }

        $items = [[$site !== '' ? $site : 'Home', $root], ...$trail, [$page, $canonical]];

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $item, int $position) => ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $item[0], 'item' => $item[1]], $items, array_keys($items)),
        ];
    }
}
