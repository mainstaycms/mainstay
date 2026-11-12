<?php

namespace Mainstay\Seo;

use Illuminate\Support\Facades\URL;
use Mainstay\Mainstay;

/*
 | The address of a path in a content locale, as the site plugin builds an
 | entry's link, but from a locale and a path alone: the sitemap keeps those
 | and not the entries, and a host that serves a locale without a host of its
 | own must have it linked on the host asking, which a cached link could not.
 */
class Locations
{
    public function __construct(private Mainstay $mainstay) {}

    public function url(string $locale, string $uri): string
    {
        $base = $this->mainstay->locales()[$locale];
        $path = $base['prefix'] !== '' && $uri === '/' ? $base['prefix'] : $base['prefix'].$uri;

        return $base['host'] === null ? URL::to($path) : $base['origin'].parse_url(URL::to('/'), PHP_URL_PATH).$path;
    }

    /* Whether a request for `$host` is answered in this locale. */
    public function serves(string $locale, string $host): bool
    {
        $served = $this->mainstay->locales()[$locale]['host'];

        return $served === null || $served === strtolower($host);
    }
}
