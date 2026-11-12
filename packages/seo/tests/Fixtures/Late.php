<?php

namespace Mainstay\Seo\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* A type a deploy adds: routed, and registered by a test after the sitemap is kept. */
#[Route('/late/{slug}')]
class Late extends Entry
{
    #[Text(required: true, localized: true)]
    public string $slug;
}
