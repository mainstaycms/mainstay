<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Template;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* A view named that nobody wrote. */
#[Route('/lost')]
#[Template('nowhere')]
class Lost extends Entry
{
    #[Text]
    public string $title;
}
