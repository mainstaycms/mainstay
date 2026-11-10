<?php

namespace Mainstay\Tests\Fixtures\Drafted;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* A path at the root, which a locale's prefix can come to answer. */
#[Route('/{slug}')]
class Leaflet extends Entry
{
    #[Text(required: true, localized: true)]
    public string $slug;
}
