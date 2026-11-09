<?php

namespace Mainstay\Tests\Fixtures\Drafted;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* A path with no field in it at all. */
#[Route('/about')]
class About extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;
}
