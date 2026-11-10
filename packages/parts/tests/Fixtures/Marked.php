<?php

namespace Mainstay\Parts\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\HoldsPage;

/* An entry type claiming to be edited as a page without a route, which only a global may be. */
class Marked extends Entry implements HoldsPage
{
    public static function heading(): string
    {
        return 'Marked';
    }
}
