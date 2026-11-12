<?php

namespace Mainstay\Seo\Tests\Fixtures\Variant;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* The same type as Fixtures\Late, by handle, after a deploy changed only its route's pattern. */
#[Route('/delayed/{slug}')]
class Late extends Entry
{
    #[Text(required: true, localized: true)]
    public string $slug;
}
