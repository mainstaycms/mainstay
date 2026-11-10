<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Template;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* The one entry at a locale's root, drawn by a view its handle is not. */
#[Route('/')]
#[Template('pages.home', 'special')]
class Home extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;
}
