<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Content\Description;
use Mainstay\Content\Example;
use Mainstay\Content\Icon;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Text;

/* A block holding blocks of one other kind inside it, previewed holding
   one, with an icon of Mainstay's. */
#[Icon('gallery')]
#[Description('Pictures in a row, each one a slide.')]
#[Example(['caption' => 'Harbour at dawn'])]
#[Blocks([Slide::class])]
class Gallery extends Block
{
    #[Text]
    public ?string $caption;
}
