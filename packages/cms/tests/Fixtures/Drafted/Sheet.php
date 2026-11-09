<?php

namespace Mainstay\Tests\Fixtures\Drafted;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* One slug for every language, so a path taken in one moves them all. */
#[Route(['en' => '/sheets/{slug}', 'nl' => '/bladen/{slug}'])]
class Sheet extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true)]
    public string $slug;
}
