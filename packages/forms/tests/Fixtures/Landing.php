<?php

namespace Mainstay\Forms\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\HoldsPage;
use Mainstay\Fields\Blocks;
use Mainstay\Forms\Blocks\FormBlock;

/* A page of the site that offers the Form block, and the plain text around it. */
#[Blocks([FormBlock::class])]
class Landing extends Entry implements HoldsPage
{
    public static function heading(): string
    {
        return 'Landings';
    }
}
