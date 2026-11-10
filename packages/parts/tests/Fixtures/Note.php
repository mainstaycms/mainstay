<?php

namespace Mainstay\Parts\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\HoldsPage;
use Mainstay\Fields\Blocks;

/* An entry with no address, edited as a page: its own list in the sidebar, its content built from blocks. */
#[Blocks([Brand::class])]
class Note extends Entry implements HoldsPage
{
    public static function heading(): string
    {
        return 'Notes';
    }
}
