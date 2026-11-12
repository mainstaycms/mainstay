<?php

namespace Mainstay\Seo\Tests\Fixtures;

use Mainstay\Seo\SeoMeta;

/* A property an SEO field cannot be declared on: it cannot hold null. */
class Strict
{
    public SeoMeta $seo;
}
