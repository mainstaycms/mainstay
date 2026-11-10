<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* Every path under `/nl`, which beside a Dutch served there no English one
   can have. */
#[Route('/nl/{slug}')]
class Shadowed extends Entry
{
    #[Text(required: true)]
    public string $slug;
}
