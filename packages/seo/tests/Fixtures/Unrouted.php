<?php

namespace Mainstay\Seo\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Seo\Seo;
use Mainstay\Seo\SeoMeta;

/* A type read over the API alone: it has SEO data and no address, so no page to read. */
class Unrouted extends Entry
{
    #[Seo]
    public ?SeoMeta $seo;
}
