<?php

namespace Mainstay\Seo\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Relation;
use Mainstay\Fields\Text;
use Mainstay\Seo\Seo;
use Mainstay\Seo\SeoMeta;
use Mainstay\Site\Route;

/* A page with a parent, so it has breadcrumbs, and an SEO field shared by every language. */
#[Route('/{slug}')]
class Page extends Entry
{
    #[Text(required: true, localized: true)]
    public string $slug;

    #[Relation(to: Page::class)]
    public ?Page $parent;

    #[Seo(localized: false)]
    public ?SeoMeta $seo;
}
