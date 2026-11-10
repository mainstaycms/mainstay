<?php

namespace Mainstay\Parts\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\HoldsPage;
use Mainstay\Site\Route;

/* An entry type claiming to be edited as a page with a route, which has a page of its own. */
#[Route('/marked')]
class Marked extends Entry implements HoldsPage
{
    public static function heading(): string
    {
        return 'Marked';
    }
}
