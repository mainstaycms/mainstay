<?php

namespace Mainstay\Parts\Tests\Fixtures;

use Mainstay\Content\HoldsPage;
use Mainstay\Content\Taxonomy;

/* A taxonomy claiming to be edited as a page, which its terms are not. */
class Tagged extends Taxonomy implements HoldsPage
{
    public static function heading(): string
    {
        return 'Tagged';
    }
}
