<?php

namespace Mainstay\Seo\Tests\Fixtures;

use Mainstay\Content\GlobalSet;
use Mainstay\Seo\Seo;
use Mainstay\Seo\SeoMeta;

/* A global with the field: it has no page of its own, so no tab. */
class Banner extends GlobalSet
{
    #[Seo]
    public ?SeoMeta $seo;
}
