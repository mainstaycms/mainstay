<?php

namespace Mainstay\Tests\Fixtures\Linked;

use Mainstay\Content\Taxonomy;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

#[Route('/genres/{slug}')]
class Genre extends Taxonomy
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true, localized: true)]
    public string $slug;
}
