<?php

namespace Mainstay\Seo\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;
use Mainstay\Seo\Seo;
use Mainstay\Seo\SeoMeta;
use Mainstay\Site\Route;

/* Its view says who is looking, and throws for a request that asks it to. */
#[Route('/probe/{slug}')]
class Probe extends Entry
{
    #[Text(required: true, localized: true)]
    public string $slug;

    #[Seo]
    public ?SeoMeta $seo;
}
