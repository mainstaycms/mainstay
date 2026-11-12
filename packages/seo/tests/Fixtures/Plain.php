<?php

namespace Mainstay\Seo\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* A routed type with no SEO field: in the sitemap, with no tab. */
#[Route('/plain/{slug}')]
class Plain extends Entry
{
    #[Text(required: true, localized: true)]
    public string $slug;
}
