<?php

namespace Mainstay\Forms\Http;

use Illuminate\Support\Facades\App;
use Mainstay\Mainstay;

/* The content language a post is read and kept in. */
class Locale
{
    public function __construct(private Mainstay $mainstay) {}

    /* The one asked for, else the app's, else the first the site has: one of the site's own, which is all a read takes. */
    public function ofRequest(?string $asked = null): string
    {
        $locales = array_keys($this->mainstay->locales());

        return collect([$asked, App::getLocale()])->first(fn (?string $locale) => in_array($locale, $locales, true)) ?? $locales[0];
    }
}
